/**
 * Multi-provider LLM structured extraction (LLMExtractionStrategy).
 *
 * Providers (explicit override > OpenAI-compatible base URL > autodetect):
 *   1. OpenAI            — openai SDK, response_format json_object
 *   2. Any OpenAI-compatible endpoint (Ollama, Groq, OpenRouter, vLLM, …)
 *                          — openai SDK pointed at CRAWL_LLM_BASE_URL
 *   3. Google Gemini     — @google/generative-ai, responseMimeType json
 *   4. Anthropic Claude  — REST /v1/messages, instruction-only JSON
 *   5. DeepSeek          — openai SDK pointed at api.deepseek.com
 *
 * Selection rules (resolveLlmProvider):
 *   - CRAWL_LLM_PROVIDER (openai|gemini|anthropic|deepseek) wins.
 *   - CRAWL_LLM_BASE_URL routes through the OpenAI-compatible client (key
 *     optional — Ollama runs without one).
 *   - Otherwise the first configured credential in priority order:
 *     OPENAI_API_KEY → GEMINI_API_KEY → ANTHROPIC_API_KEY → DEEPSEEK_API_KEY.
 *   - None configured → null (callers answer 501).
 *
 * Every response is hardened before parsing: code fences and surrounding
 * prose are tolerated, and the balanced JSON substring is extracted.
 */

import axios from 'axios';
import OpenAI from 'openai';
import { GoogleGenerativeAI } from '@google/generative-ai';
import { getCredential } from './provider-keys.js';

const VALID_PROVIDERS = [ 'openai', 'gemini', 'anthropic', 'deepseek' ];

const LLM_TIMEOUT_MS = ( () => {
	const value = Number( process.env.CRAWL_LLM_TIMEOUT_MS );
	return Number.isFinite( value ) && value > 0 ? value : 60000;
} )();

const PROVIDER_CONFIG = {
	openai: { key: 'OPENAI_API_KEY', model: () => process.env.CRAWL_LLM_MODEL || 'gpt-4o-mini' },
	gemini: { key: 'GEMINI_API_KEY', model: () => process.env.CRAWL_LLM_MODEL_GEMINI || 'gemini-2.5-flash' },
	anthropic: { key: 'ANTHROPIC_API_KEY', model: () => process.env.CRAWL_LLM_MODEL_ANTHROPIC || 'claude-sonnet-4-5' },
	deepseek: {
		key: 'DEEPSEEK_API_KEY',
		model: () => process.env.CRAWL_LLM_MODEL_DEEPSEEK || 'deepseek-chat',
		baseUrl: 'https://api.deepseek.com',
	},
};

/**
 * Extract a JSON value from a raw model response, tolerating code fences
 * and surrounding prose.
 *
 * @param {string} raw Raw response text.
 * @return {*} Parsed JSON value.
 * @throws {Error} When no parseable JSON is present.
 */
export function extractJsonFromText( raw ) {
	const text = String( raw || '' ).trim();
	if ( ! text ) {
		throw new Error( 'LLM returned an empty response' );
	}

	// Fast path: the response is already clean JSON.
	try {
		return JSON.parse( text );
	} catch {
		// Fall through to extraction.
	}

	// Strip a single ```json … ``` fence when present.
	const fenced = text.match( /```(?:json)?\s*([\s\S]*?)```/i );
	const candidate = ( fenced ? fenced[ 1 ] : text ).trim();

	const startObject = candidate.indexOf( '{' );
	const startArray = candidate.indexOf( '[' );
	let start = -1;
	if ( -1 !== startObject && ( -1 === startArray || startObject < startArray ) ) {
		start = startObject;
	} else {
		start = startArray;
	}
	if ( -1 === start ) {
		throw new Error( 'LLM response did not contain JSON' );
	}

	const slice = extractBalancedJson( candidate, start );
	if ( null === slice ) {
		throw new Error( 'LLM response contained unbalanced JSON' );
	}

	try {
		return JSON.parse( slice );
	} catch {
		throw new Error( 'LLM response JSON was malformed' );
	}
}

/**
 * Scan forward from a JSON opening bracket to its balanced closing bracket,
 * respecting strings (so braces inside string values never break the scan).
 *
 * @param {string} text  Text containing JSON.
 * @param {number} start Index of the opening { or [.
 * @return {string|null} Balanced JSON substring, or null when unterminated.
 */
function extractBalancedJson( text, start ) {
	const open = text[ start ];
	const close = '{' === open ? '}' : ']';
	let depth = 0;
	let inString = false;
	let escaped = false;

	for ( let i = start; i < text.length; i++ ) {
		const ch = text[ i ];
		if ( inString ) {
			if ( escaped ) {
				escaped = false;
			} else if ( '\\' === ch ) {
				escaped = true;
			} else if ( '"' === ch ) {
				inString = false;
			}
			continue;
		}
		if ( '"' === ch ) {
			inString = true;
		} else if ( ch === open ) {
			depth += 1;
		} else if ( ch === close ) {
			depth -= 1;
			if ( 0 === depth ) {
				return text.slice( start, i + 1 );
			}
		}
	}
	return null;
}

/**
 * Build the extraction prompt shared by every provider.
 *
 * @param {string} markdown    Crawled page content.
 * @param {string|null} instruction Optional extraction instruction.
 * @return {string} Prompt text.
 */
export function buildExtractionPrompt( markdown, instruction ) {
	return [
		'Extract structured data from the following web page content.',
		'Return ONLY a valid JSON object. Do not include code fences or commentary.',
		instruction
			? `Extraction instruction: ${ instruction }`
			: 'Include the main entities, key facts, and any structured data present.',
		'',
		'WEB PAGE CONTENT (Markdown):',
		'---',
		( markdown || '' ).slice( 0, 120000 ),
		'---',
	].join( '\n' );
}

/**
 * Resolve which LLM provider should serve extraction for a site.
 *
 * @param {string} [site] Site slug ('default' in single-tenant mode).
 * @param {Object} [deps] Injectable ({ getCredential }).
 * @return {Object|null} { provider, model, apiKey, baseUrl? }, or null when
 *                       no provider is configured.
 * @throws {Error} With status 400 on an invalid explicit CRAWL_LLM_PROVIDER
 *                 or a missing key for an explicitly selected provider.
 */
export function resolveLlmProvider( site = 'default', deps = {} ) {
	const credential = deps.getCredential || getCredential;

	const explicit = ( process.env.CRAWL_LLM_PROVIDER || '' ).toLowerCase();
	if ( explicit ) {
		if ( ! VALID_PROVIDERS.includes( explicit ) ) {
			throw Object.assign(
				new Error( `Unsupported CRAWL_LLM_PROVIDER: ${ explicit } (expected one of ${ VALID_PROVIDERS.join( ', ' ) })` ),
				{ status: 400 }
			);
		}
		const config = providerConfig( explicit, site, credential );
		if ( ! config.apiKey ) {
			throw Object.assign(
				new Error( `CRAWL_LLM_PROVIDER=${ explicit } but ${ PROVIDER_CONFIG[ explicit ].key } is not configured` ),
				{ status: 400 }
			);
		}
		return config;
	}

	// OpenAI-compatible endpoint (key optional — Ollama runs without one).
	if ( process.env.CRAWL_LLM_BASE_URL ) {
		return {
			provider: 'openai',
			model: PROVIDER_CONFIG.openai.model(),
			baseUrl: process.env.CRAWL_LLM_BASE_URL,
			apiKey: credential( site, 'OPENAI_API_KEY' ) || 'not-required',
		};
	}

	for ( const provider of VALID_PROVIDERS ) {
		const config = providerConfig( provider, site, credential );
		if ( config.apiKey ) {
			return config;
		}
	}
	return null;
}

/**
 * Build the resolved configuration for a named provider.
 *
 * @param {string} provider   Provider id.
 * @param {string} site       Site slug.
 * @param {Function} credential Credential resolver.
 * @return {Object} { provider, model, apiKey, baseUrl? }.
 */
function providerConfig( provider, site, credential ) {
	const config = PROVIDER_CONFIG[ provider ];
	return {
		provider,
		model: config.model(),
		apiKey: credential( site, config.key ),
		...( config.baseUrl ? { baseUrl: config.baseUrl } : {} ),
	};
}

// ── Provider extractors ────────────────────────────────────

/**
 * OpenAI / any OpenAI-compatible endpoint (DeepSeek included).
 *
 * @param {string} prompt Prompt text.
 * @param {Object} cfg    { model, apiKey, baseUrl? }.
 * @param {Object} deps   Injectable ({ createOpenAi }).
 * @return {Promise<string>} Raw response text.
 */
async function extractOpenAi( prompt, cfg, deps = {} ) {
	const create = deps.createOpenAi || ( ( options ) => new OpenAI( options ) );
	const client = create( {
		apiKey: cfg.apiKey,
		...( cfg.baseUrl ? { baseURL: cfg.baseUrl } : {} ),
	} );
	const response = await client.chat.completions.create( {
		model: cfg.model,
		messages: [ { role: 'user', content: prompt } ],
		response_format: { type: 'json_object' },
		timeout: LLM_TIMEOUT_MS,
	} );
	return ( response.choices && response.choices[ 0 ] && response.choices[ 0 ].message && response.choices[ 0 ].message.content ) || '';
}

/**
 * Google Gemini.
 *
 * @param {string} prompt Prompt text.
 * @param {Object} cfg    { model, apiKey }.
 * @param {Object} deps   Injectable ({ createGemini }).
 * @return {Promise<string>} Raw response text.
 */
async function extractGemini( prompt, cfg, deps = {} ) {
	const create = deps.createGemini || ( ( apiKey ) => new GoogleGenerativeAI( apiKey ) );
	const genAI = create( cfg.apiKey );
	const model = genAI.getGenerativeModel( { model: cfg.model } );
	const result = await model.generateContent( {
		contents: [ { role: 'user', parts: [ { text: prompt } ] } ],
		generationConfig: { responseMimeType: 'application/json' },
	} );
	return ( result.response && result.response.text() ) || '';
}

/**
 * Anthropic Claude (REST — no SDK dependency needed).
 *
 * @param {string} prompt Prompt text.
 * @param {Object} cfg    { model, apiKey }.
 * @param {Object} deps   Injectable ({ post }).
 * @return {Promise<string>} Raw response text.
 */
async function extractAnthropic( prompt, cfg, deps = {} ) {
	const post = deps.post || axios.post;
	const response = await post(
		'https://api.anthropic.com/v1/messages',
		{
			model: cfg.model,
			max_tokens: 4096,
			system: 'You are a structured-data extraction assistant. Return ONLY a valid JSON object — no code fences, no commentary.',
			messages: [ { role: 'user', content: prompt } ],
		},
		{
			headers: {
				'x-api-key': cfg.apiKey,
				'anthropic-version': '2023-06-01',
				'content-type': 'application/json',
			},
			timeout: LLM_TIMEOUT_MS,
		}
	);
	const content = response.data && response.data.content;
	if ( ! Array.isArray( content ) ) {
		throw new Error( 'Unexpected Anthropic response shape' );
	}
	return content.map( ( block ) => ( 'text' === block.type ? block.text : '' ) ).join( '' );
}

const EXTRACTORS = {
	openai: extractOpenAi,
	gemini: extractGemini,
	anthropic: extractAnthropic,
	deepseek: extractOpenAi, // Same OpenAI-compatible client, DeepSeek base URL.
};

/**
 * Run the full extraction pipeline: resolve the provider, prompt the model,
 * and parse a JSON value out of the response.
 *
 * @param {Object} input { markdown, instruction, site }.
 * @param {Object} [deps] Injectable ({ resolve, createOpenAi, createGemini, post }).
 * @return {Promise<*>} Parsed JSON value.
 * @throws {Error} With status 501 when no provider is configured.
 */
export async function llmExtractJson( input, deps = {} ) {
	const resolve = deps.resolve || resolveLlmProvider;
	const config = resolve( input.site || 'default', deps );
	if ( ! config ) {
		throw Object.assign(
			new Error( 'No LLM provider configured (OPENAI_API_KEY, GEMINI_API_KEY, ANTHROPIC_API_KEY, DEEPSEEK_API_KEY, or CRAWL_LLM_BASE_URL)' ),
			{ status: 501 }
		);
	}
	const prompt = buildExtractionPrompt( input.markdown, input.instruction );
	const raw = await EXTRACTORS[ config.provider ]( prompt, config, deps );
	return extractJsonFromText( raw );
}
