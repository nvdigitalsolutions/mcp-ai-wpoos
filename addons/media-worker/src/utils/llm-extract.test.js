/**
 * Tests for the multi-provider LLM structured extraction module.
 *
 * No network access: provider clients are injected as fakes, and env
 * selection is exercised with careful save/restore discipline.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	extractJsonFromText,
	buildExtractionPrompt,
	resolveLlmProvider,
	llmExtractJson,
} from './llm-extract.js';

// ── Env isolation helpers ───────────────────────────────────

const PROVIDER_ENV_KEYS = [
	'OPENAI_API_KEY',
	'GEMINI_API_KEY',
	'ANTHROPIC_API_KEY',
	'DEEPSEEK_API_KEY',
	'CRAWL_LLM_PROVIDER',
	'CRAWL_LLM_BASE_URL',
	'CRAWL_LLM_MODEL',
	'CRAWL_LLM_MODEL_GEMINI',
	'CRAWL_LLM_MODEL_ANTHROPIC',
	'CRAWL_LLM_MODEL_DEEPSEEK',
];

function scrubEnv() {
	const saved = {};
	for ( const key of PROVIDER_ENV_KEYS ) {
		saved[ key ] = process.env[ key ];
		delete process.env[ key ];
	}
	return () => {
		for ( const key of PROVIDER_ENV_KEYS ) {
			if ( saved[ key ] ) {
				process.env[ key ] = saved[ key ];
			} else {
				delete process.env[ key ];
			}
		}
	};
}

// ── extractJsonFromText ─────────────────────────────────────

test( 'extractJsonFromText parses clean objects and arrays', () => {
	assert.deepEqual( extractJsonFromText( '{"answer":42}' ), { answer: 42 } );
	assert.deepEqual( extractJsonFromText( '[1, 2, 3]' ), [ 1, 2, 3 ] );
} );

test( 'extractJsonFromText tolerates fences and surrounding prose', () => {
	assert.deepEqual( extractJsonFromText( '```json\n{"a":1}\n```' ), { a: 1 } );
	assert.deepEqual( extractJsonFromText( 'Sure! Here is the data:\n{"a":1}\nHope it helps.' ), { a: 1 } );
} );

test( 'extractJsonFromText survives braces inside strings', () => {
	const raw = 'prefix {"text":"a {nested} brace","n":2} suffix';
	assert.deepEqual( extractJsonFromText( raw ), { text: 'a {nested} brace', n: 2 } );
} );

test( 'extractJsonFromText rejects responses without JSON', () => {
	assert.throws( () => extractJsonFromText( '' ), /empty response/ );
	assert.throws( () => extractJsonFromText( 'no json here at all' ), /did not contain JSON/ );
	assert.throws( () => extractJsonFromText( '{"unclosed":' ), /unbalanced/ );
	assert.throws( () => extractJsonFromText( '{"a": nope}' ), /malformed/ );
} );

// ── buildExtractionPrompt ───────────────────────────────────

test( 'buildExtractionPrompt embeds the instruction and truncates content', () => {
	const prompt = buildExtractionPrompt( 'hello world', 'Find the price' );
	assert.ok( prompt.includes( 'Find the price' ) );
	assert.ok( prompt.includes( 'hello world' ) );
	assert.ok( prompt.includes( 'Return ONLY a valid JSON object' ) );

	const long = buildExtractionPrompt( 'x'.repeat( 200000 ), null );
	assert.ok( long.length < 150000, 'oversized content is truncated' );
} );

// ── resolveLlmProvider ──────────────────────────────────────

test( 'resolveLlmProvider autodetects the first configured credential', () => {
	const restore = scrubEnv();

	process.env.DEEPSEEK_API_KEY = 'sk-deep';
	const deepseek = resolveLlmProvider( 'default' );
	assert.equal( deepseek.provider, 'deepseek' );
	assert.equal( deepseek.baseUrl, 'https://api.deepseek.com' );
	assert.equal( deepseek.model, 'deepseek-chat' );

	process.env.GEMINI_API_KEY = 'gem-1';
	process.env.ANTHROPIC_API_KEY = 'ant-1';
	const gemini = resolveLlmProvider( 'default' );
	assert.equal( gemini.provider, 'gemini', 'OpenAI → Gemini → Anthropic → DeepSeek priority' );

	process.env.OPENAI_API_KEY = 'oai-1';
	const openai = resolveLlmProvider( 'default' );
	assert.equal( openai.provider, 'openai' );

	restore();
} );

test( 'resolveLlmProvider returns null when nothing is configured', () => {
	const restore = scrubEnv();
	assert.equal( resolveLlmProvider( 'default' ), null );
	restore();
} );

test( 'resolveLlmProvider honours an explicit provider override', () => {
	const restore = scrubEnv();
	process.env.OPENAI_API_KEY = 'oai-1';
	process.env.ANTHROPIC_API_KEY = 'ant-1';
	process.env.CRAWL_LLM_PROVIDER = 'anthropic';

	const resolved = resolveLlmProvider( 'default' );
	assert.equal( resolved.provider, 'anthropic' );
	assert.equal( resolved.apiKey, 'ant-1' );

	restore();
} );

test( 'resolveLlmProvider rejects an explicit provider without its key', () => {
	const restore = scrubEnv();
	process.env.CRAWL_LLM_PROVIDER = 'gemini';

	assert.throws(
		() => resolveLlmProvider( 'default' ),
		( err ) => 400 === err.status && /GEMINI_API_KEY/.test( err.message )
	);

	restore();
} );

test( 'resolveLlmProvider rejects unknown explicit providers', () => {
	const restore = scrubEnv();
	process.env.CRAWL_LLM_PROVIDER = 'magic';

	assert.throws(
		() => resolveLlmProvider( 'default' ),
		( err ) => 400 === err.status && /Unsupported CRAWL_LLM_PROVIDER/.test( err.message )
	);

	restore();
} );

test( 'resolveLlmProvider routes CRAWL_LLM_BASE_URL through the OpenAI client', () => {
	const restore = scrubEnv();
	process.env.CRAWL_LLM_BASE_URL = 'http://ollama:11434/v1';

	const resolved = resolveLlmProvider( 'default' );
	assert.equal( resolved.provider, 'openai' );
	assert.equal( resolved.baseUrl, 'http://ollama:11434/v1' );
	assert.equal( resolved.apiKey, 'not-required', 'Ollama-style endpoints need no key' );

	restore();
} );

// ── llmExtractJson (provider extractors via injected clients) ─

/** Fake OpenAI-compatible client recording configuration + calls. */
function fakeOpenAi( content = '{"answer":42}' ) {
	const records = [];
	const client = {
		chat: {
			completions: {
				create: async ( args ) => {
					records.push( args );
					return { choices: [ { message: { content } } ] };
				},
			},
		},
	};
	return { client, records, factory: ( options ) => {
		records.push( { factory: options } );
		return client;
	} };
}

test( 'llmExtractJson uses the OpenAI client with json_object mode', async () => {
	const { records, factory } = fakeOpenAi();
	const result = await llmExtractJson(
		{ markdown: 'Page', instruction: null, site: 'default' },
		{ resolve: () => ( { provider: 'openai', model: 'gpt-4o-mini', apiKey: 'sk-1' } ), createOpenAi: factory }
	);

	assert.deepEqual( result, { answer: 42 } );
	const call = records.find( ( entry ) => entry.messages );
	assert.equal( call.model, 'gpt-4o-mini' );
	assert.deepEqual( call.response_format, { type: 'json_object' } );
} );

test( 'llmExtractJson points DeepSeek at its base URL', async () => {
	const { records, factory } = fakeOpenAi();
	await llmExtractJson(
		{ markdown: 'Page', instruction: null, site: 'default' },
		{ resolve: () => ( { provider: 'deepseek', model: 'deepseek-chat', apiKey: 'sk-deep', baseUrl: 'https://api.deepseek.com' } ), createOpenAi: factory }
	);

	const factoryCall = records.find( ( entry ) => entry.factory );
	assert.equal( factoryCall.factory.baseURL, 'https://api.deepseek.com' );
} );

test( 'llmExtractJson uses Gemini with responseMimeType application/json', async () => {
	const records = [];
	const genAI = {
		getGenerativeModel: ( args ) => {
			records.push( { model: args } );
			return {
				generateContent: async ( args ) => {
					records.push( { generation: args } );
					return { response: { text: () => '{"x":1}' } };
				},
			};
		},
	};
	const result = await llmExtractJson(
		{ markdown: 'Page', instruction: null, site: 'default' },
		{ resolve: () => ( { provider: 'gemini', model: 'gemini-2.5-flash', apiKey: 'gem-1' } ), createGemini: () => genAI }
	);

	assert.deepEqual( result, { x: 1 } );
	assert.equal( records[ 1 ].generation.generationConfig.responseMimeType, 'application/json' );
} );

test( 'llmExtractJson uses the Anthropic REST contract', async () => {
	const calls = [];
	const post = async ( url, body, options ) => {
		calls.push( { url, body, options } );
		return { data: { content: [ { type: 'text', text: '{"y":2}' } ] } };
	};
	const result = await llmExtractJson(
		{ markdown: 'Page', instruction: null, site: 'default' },
		{ resolve: () => ( { provider: 'anthropic', model: 'claude-sonnet-4-5', apiKey: 'ant-1' } ), post }
	);

	assert.deepEqual( result, { y: 2 } );
	assert.equal( calls[ 0 ].url, 'https://api.anthropic.com/v1/messages' );
	assert.equal( calls[ 0 ].options.headers[ 'x-api-key' ], 'ant-1' );
	assert.equal( calls[ 0 ].options.headers[ 'anthropic-version' ], '2023-06-01' );
	assert.equal( calls[ 0 ].body.model, 'claude-sonnet-4-5' );
} );

test( 'llmExtractJson throws 501 when no provider resolves', async () => {
	await assert.rejects(
		() => llmExtractJson( { markdown: 'Page', instruction: null, site: 'default' }, { resolve: () => null } ),
		( err ) => 501 === err.status && /No LLM provider configured/.test( err.message )
	);
} );

test( 'llmExtractJson surfaces malformed provider output as an error', async () => {
	const { factory } = fakeOpenAi( 'just some prose' );
	await assert.rejects(
		() => llmExtractJson(
			{ markdown: 'Page', instruction: null, site: 'default' },
			{ resolve: () => ( { provider: 'openai', model: 'gpt-4o-mini', apiKey: 'sk-1' } ), createOpenAi: factory }
		),
		/did not contain JSON/
	);
} );
