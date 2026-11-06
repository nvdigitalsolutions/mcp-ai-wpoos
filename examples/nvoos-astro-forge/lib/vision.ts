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
// builds stay deterministic.
//
// Zero dependencies (global fetch), Node type-stripping safe.
// ---------------------------------------------------------------------------

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
