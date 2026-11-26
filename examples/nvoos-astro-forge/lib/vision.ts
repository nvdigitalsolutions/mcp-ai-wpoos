// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// vision.ts — optional vision-verified comparisons (Proposal 118).
//
// The forge's parity gate (114 §3.6) scores the EXTRACTED design language —
// it can pass while the rebuilt page still looks wrong (jayainc pre-117:
// same palette, flattened layout). This module adds the visual layer:
// screenshots compared by a vision model over any OpenAI-compatible
// /chat/completions endpoint (OpenAI, Ollama, LM Studio, a bridged NV oOS
// assistant, …). Images travel as base64 DATA URLs in the request body —
// no public image hosting and no external screenshot services (the
// URL-fetch route that failed against bot-protected hosts, 2026-11-06).
//
// Fail-soft by contract: without NVOOS_FORGE_VISION_* env the callers skip;
// network/model errors surface as { ok: false } and never fail a build.
// Vision NEVER runs inside the build — verify-time only, deterministic
// builds stay deterministic. A reachable local Ollama daemon is used when no
// explicit credentials are set, so the layer works without a paid key.
//
// Zero dependencies (global fetch), Node type-stripping safe.
// ---------------------------------------------------------------------------

import { detectLocalModel, type LocalModel } from './local-model.ts';

export interface VisionConfig {
	/** The OpenAI-compatible API base (e.g. https://api.openai.com/v1). */
	baseUrl: string;
	key: string;
	model: string;
}

/** An in-memory image (base64 + mime) — the vision payload shape. */
export interface VisionImage {
	data: string;
	mime: string;
}

export type VisionOutcome =
	| { ok: true; verdict: Record<string, unknown>; raw: string }
	| { ok: false; error: string };

/** Resolve the vision config from env; undefined = not configured (skip). */
export function visionConfig( env: NodeJS.ProcessEnv = process.env ): VisionConfig | undefined {
	const key = env.NVOOS_FORGE_VISION_KEY;
	if ( ! key ) {
		return undefined;
	}
	return {
		baseUrl: ( env.NVOOS_FORGE_VISION_BASE_URL ?? 'https://api.openai.com/v1' ).replace( /\/+$/, '' ),
		key,
		model: env.NVOOS_FORGE_VISION_MODEL ?? 'gpt-4o-mini',
	};
}

/** A vision config pointed at the local Ollama daemon (no key required). */
export function visionConfigFromLocal(
	local: LocalModel,
	env: NodeJS.ProcessEnv = process.env
): VisionConfig {
	return {
		baseUrl: local.baseUrl,
		// Ollama ignores the key; the OpenAI-compatible client wants one.
		key: 'ollama',
		model: env.NVOOS_FORGE_VISION_MODEL ?? local.model,
	};
}

/**
 * The vision config to actually use: explicit credentials first, then a local
 * Ollama daemon whenever one answers on the loopback interface.
 * Undefined keeps the step skipped (never an error).
 */
export async function resolveVisionConfig(
	env: NodeJS.ProcessEnv = process.env
): Promise< VisionConfig | undefined > {
	const explicit = visionConfig( env );
	if ( explicit ) {
		return explicit;
	}
	const local = await detectLocalModel( env );
	return local ? visionConfigFromLocal( local, env ) : undefined;
}

/** The data-URL form OpenAI-compatible vision APIs accept for image_url.url. */
export function dataUrl( image: VisionImage ): string {
	return `data:${ image.mime };base64,${ image.data }`;
}

interface ChatMessage {
	role: 'user';
	content: Array< { type: 'text'; text: string } | { type: 'image_url'; image_url: { url: string } } >;
}

function buildBody( config: VisionConfig, images: VisionImage[], prompt: string, maxTokens: number ): string {
	const content: ChatMessage[ 'content' ] = [ { type: 'text', text: prompt } ];
	for ( const image of images ) {
		content.push( { type: 'image_url', image_url: { url: dataUrl( image ) } } );
	}
	return JSON.stringify( {
		model: config.model,
		messages: [ { role: 'user', content } ],
		max_tokens: maxTokens,
		// Structured verdicts, please — the report is parsed as JSON with a
		// text fallback.
		response_format: { type: 'json_object' },
	} );
}

async function chat(
	config: VisionConfig,
	body: string,
	fetchImpl: typeof fetch,
	timeoutMs: number
): Promise<{ ok: true; text: string } | { ok: false; error: string }> {
	let response: Response;
	try {
		response = await fetchImpl( `${ config.baseUrl }/chat/completions`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${ config.key }`,
			},
			body,
			signal: AbortSignal.timeout( timeoutMs ),
		} );
	} catch ( error ) {
		return { ok: false, error: error instanceof Error ? error.message : String( error ) };
	}
	if ( ! response.ok ) {
		return { ok: false, error: `vision request failed: ${ response.status } ${ response.statusText }` };
	}
	try {
		const payload = ( await response.json() ) as {
			choices?: Array< { message?: { content?: string } } >;
		};
		const text = payload.choices?.[ 0 ]?.message?.content;
		if ( typeof text !== 'string' || text.trim().length === 0 ) {
			return { ok: false, error: 'vision response carried no message content' };
		}
		return { ok: true, text };
	} catch ( error ) {
		return { ok: false, error: `vision response parse failed: ${ error instanceof Error ? error.message : error }` };
	}
}

/** Parse a model's JSON-or-prose answer into a verdict object. */
export function parseVerdict( text: string ): Record<string, unknown> {
	const trimmed = text.trim();
	// Strip a ```json fence when the model wraps the answer.
	const fenced = /^```(?:json)?\s*([\s\S]*?)\s*```$/.exec( trimmed );
	const candidate = fenced ? fenced[ 1 ] : trimmed;
	try {
		const parsed = JSON.parse( candidate ) as unknown;
		if ( parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ) {
			return parsed as Record<string, unknown>;
		}
	} catch {
		// Fall through to the prose fallback.
	}
	return { fidelity: null, summary: trimmed };
}

/**
 * Run a vision model over one or more images with a prompt. Returns the
 * parsed verdict (JSON when the model obliges, the raw prose otherwise) or
 * a typed failure — never throws.
 */
export async function visionRun(
	config: VisionConfig,
	images: VisionImage[],
	prompt: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs: number = 120_000,
	maxTokens: number = 900
): Promise<VisionOutcome> {
	const outcome = await chat( config, buildBody( config, images, prompt, maxTokens ), fetchImpl, timeoutMs );
	if ( ! outcome.ok ) {
		return outcome;
	}
	return { ok: true, verdict: parseVerdict( outcome.text ), raw: outcome.text };
}

/** The default side-by-side comparison prompt — strict JSON, concrete only. */
export const COMPARE_PROMPT = [
	'You are a design-review assistant. Two screenshots of the SAME brand website are attached.',
	'A is the ORIGINAL WordPress site. B is a REBUILT Astro frontend.',
	'Compare them visually and report ONLY what is actually visible in the images.',
	'Answer as strict JSON:',
	'{',
	'  "fidelity": <number 0-100, overall visual fidelity of B against A>,',
	'  "layout": { "order": "match" | "diverge", "notes": "<string>" },',
	'  "palette": { "match": true | false, "notes": "<string>" },',
	'  "typography": { "match": true | false, "notes": "<string>" },',
	'  "divergences": [ { "area": "<header|hero|section|cards|footer|spacing|color|text>", "severity": "low|medium|high", "note": "<concrete, one line>" } ],',
	'  "summary": "<one paragraph, concrete>"',
	'}',
	'Do not invent divergences; an empty divergences array is a valid answer.',
].join( '\n' );

/**
 * Compare two screenshots (source vs built) and return the verdict.
 */
export async function visionCompare(
	config: VisionConfig,
	source: VisionImage,
	built: VisionImage,
	prompt: string = COMPARE_PROMPT,
	fetchImpl: typeof fetch = fetch,
	timeoutMs: number = 120_000
): Promise<VisionOutcome> {
	return visionRun( config, [ source, built ], prompt, fetchImpl, timeoutMs );
}

/**
 * The closed vocabulary a STRUCTURE verdict may name.
 *
 * Closed on purpose: the model's job is to pick a label the renderer already
 * has a component for, never to invent one. A verdict outside this list is
 * `unknown`, which the caller treats as "no structure measured".
 */
export const STRUCTURE_VOCABULARY = [
	'comparison',
	'pricing',
	'stats',
	'steps',
	'timeline',
	'team',
	'testimonials',
	'faq',
	'gallery',
	'logos',
	'hero',
	'cards',
	'form',
	'text',
] as const;

export type StructureType = ( typeof STRUCTURE_VOCABULARY )[ number ];

export interface StructureVerdict {
	/** `unknown` means the model saw nothing it could name — never a guess. */
	type: StructureType | 'unknown';
	/** 0–1, the model's own confidence. */
	confidence: number;
	/** One line, the model's reason. Stored for attribution. */
	note?: string;
}

/**
 * The structure prompt — one band, one label.
 *
 * The band is rendered in a browser and screenshotted because the STRUCTURE is
 * not in the sanitised markup: a builder publishes a comparison matrix's marks
 * as `<svg>` (52 raw, 0 after sanitisation) and a timeline's connector as a
 * pseudo-element. Vision is the only reader left for those.
 */
export const STRUCTURE_PROMPT = [
	'You are a web-structure classifier. One screenshot of a single website BAND is attached.',
	'Name the band’s STRUCTURE — what it IS, not what it looks like.',
	'Work in this order: (1) find ONE concrete visual cue in the screenshot, (2) name the structure that cue implies, (3) set a confidence that reflects how unambiguous that cue is.',
	'A confidence of 0.9 means the cue is unmistakable. If you are choosing between two labels, answer below 0.5 — a wrong label is worse than none.',
	'Answer as strict JSON:',
	'{',
	'  "type": "comparison" | "pricing" | "stats" | "steps" | "timeline" | "team" | "testimonials" | "faq" | "gallery" | "logos" | "hero" | "cards" | "form" | "text" | "unknown",',
	'  "confidence": <number 0-1>,',
	'  "note": "<one line: the concrete visual evidence for the label>"',
	'}',
	'Definitions: comparison = a matrix of features against options with check/cross marks;',
	'pricing = plan columns carrying prices; stats = big numbers with labels; steps = an ordered',
	'numbered process; timeline = a chronology on a line; faq = stacked questions with answers.',
	'Answer "unknown" rather than guessing; a wrong structure is worse than none.',
].join( '\n' );

/** Parse a structure verdict, tolerating a fenced answer and prose fallbacks. */
export function parseStructureVerdict( text: string ): StructureVerdict {
	const parsed = parseVerdict( text );
	const raw = typeof parsed.type === 'string' ? parsed.type.trim().toLowerCase() : '';
	const type = ( STRUCTURE_VOCABULARY as readonly string[] ).includes( raw )
		? ( raw as StructureType )
		: 'unknown';
	const confidenceRaw = typeof parsed.confidence === 'number' ? parsed.confidence : Number( parsed.confidence );
	const confidence = Number.isFinite( confidenceRaw ) ? Math.min( 1, Math.max( 0, confidenceRaw ) ) : 0;
	const note = typeof parsed.note === 'string' && parsed.note.trim() ? parsed.note.trim().slice( 0, 240 ) : undefined;
	// A label with no confidence is not a measurement.
	if ( type === 'unknown' || confidence <= 0 ) {
		return { type: 'unknown', confidence: 0, ...( note ? { note } : {} ) };
	}
	return { type, confidence, ...( note ? { note } : {} ) };
}

/**
 * Classify ONE band's structure from its screenshot.
 *
 * Opt-in and advisory by contract: the caller decides whether to store the
 * result, and a deterministic detector always wins over it (see
 * `mergeStructureVerdicts`). Never throws.
 */
export async function visionStructure(
	config: VisionConfig,
	band: VisionImage,
	fetchImpl: typeof fetch = fetch,
	timeoutMs: number = 120_000,
	prompt: string = STRUCTURE_PROMPT
): Promise<{ ok: true; verdict: StructureVerdict; raw: string } | { ok: false; error: string }> {
	const outcome = await visionRun( config, [ band ], prompt, fetchImpl, timeoutMs, 300 );
	if ( ! outcome.ok ) {
		return outcome;
	}
	return { ok: true, verdict: parseStructureVerdict( outcome.raw ), raw: outcome.raw };
}

/**
 * Merge a vision verdict into the measured structures.
 *
 * A deterministic detector is authoritative: when one already named the band,
 * vision is ignored (it may only fill a GAP). A stored vision structure carries
 * its provenance so a wrong label is attributable and removable.
 */
export function mergeStructureVerdicts< T extends { type: string; source?: string } >(
	measured: T[],
	vision: { type: StructureType | 'unknown'; confidence: number; note?: string },
	options: { model?: string; minConfidence?: number } = {}
): T[] {
	const minimum = options.minConfidence ?? 0.7;
	if ( vision.type === 'unknown' || vision.confidence < minimum ) {
		return measured;
	}
	if ( measured.some( ( entry ) => entry.source !== 'vision' ) ) {
		return measured;
	}
	// `measured` wins on type; vision may only ADD where nothing was measured.
	if ( measured.length > 0 ) {
		return measured;
	}
	return [
		...measured,
		{
			type: vision.type,
			source: 'vision',
			...( options.model ? { model: options.model } : {} ),
			confidence: vision.confidence,
			...( vision.note ? { note: vision.note } : {} ),
		} as unknown as T,
	];
}
