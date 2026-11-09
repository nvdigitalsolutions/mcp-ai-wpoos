// ---------------------------------------------------------------------------
// local-model.ts — the optional LOCAL model layer (Ollama).
//
// The forge should not need a paid key for its model-assisted steps. When an
// Ollama daemon is reachable (NVOOS_FORGE_OLLAMA_URL, OLLAMA_HOST, or the
// default 127.0.0.1:11434) the vision comparison runs against it through
// Ollama's OpenAI-compatible /v1 endpoint. Detection is best-effort and cached
// per process: absence is not an error, it just leaves the step skipped.
//
// Node type-stripping safe: types only, no enums/namespaces.
// ---------------------------------------------------------------------------

export interface LocalModel {
	/** The daemon root, without a trailing slash. */
	host: string;
	/** The OpenAI-compatible base URL (`<host>/v1`). */
	baseUrl: string;
	/** The model the forge should use. */
	model: string;
	/** The chosen model accepts images. */
	vision: boolean;
	/** Every installed model name. */
	models: string[];
}

const DEFAULT_HOST = 'http://127.0.0.1:11434';
/** A busy daemon (loading a model) can take a moment to answer /api/tags; a
 * 1.5s window produced a false negative while a model was warming. */
const PROBE_TIMEOUT_MS = 2500;

interface OllamaTag {
	name?: string;
	capabilities?: string[];
}

let cached: Promise< LocalModel | undefined > | undefined;

/** Clear the per-process cache (tests, or after starting the daemon). */
export function resetLocalModelCache(): void {
	cached = undefined;
}

function hostFromEnv( env: NodeJS.ProcessEnv ): string {
	const raw = env.NVOOS_FORGE_OLLAMA_URL ?? env.OLLAMA_HOST ?? DEFAULT_HOST;
	const withScheme = /^https?:\/\//i.test( raw ) ? raw : `http://${ raw }`;
	return withScheme.replace( /\/+$/, '' );
}

async function probe(
	env: NodeJS.ProcessEnv,
	fetchImpl: typeof fetch
): Promise< LocalModel | undefined > {
	const host = hostFromEnv( env );
	const controller = new AbortController();
	const timer = setTimeout( () => controller.abort(), PROBE_TIMEOUT_MS );
	try {
		const response = await fetchImpl( `${ host }/api/tags`, { signal: controller.signal } );
		if ( ! response.ok ) {
			return undefined;
		}
		const payload = ( await response.json() ) as { models?: OllamaTag[] };
		const tags = ( payload.models ?? [] ).filter( ( tag ) => Boolean( tag?.name ) ) as Array<
			OllamaTag & { name: string }
		>;
		if ( tags.length === 0 ) {
			return undefined;
		}
		const models = tags.map( ( tag ) => tag.name );
		const wanted = env.NVOOS_FORGE_OLLAMA_MODEL;
		const chosen =
			( wanted && models.includes( wanted ) ? wanted : undefined ) ??
			tags.find( ( tag ) => ( tag.capabilities ?? [] ).includes( 'vision' ) )?.name ??
			models[ 0 ];
		const vision = ( tags.find( ( tag ) => tag.name === chosen )?.capabilities ?? [] ).includes( 'vision' );
		return { host, baseUrl: `${ host }/v1`, model: chosen, vision, models };
	} catch {
		return undefined;
	} finally {
		clearTimeout( timer );
	}
}

/** Detect a local Ollama daemon once per process. Absence returns undefined. */
export function detectLocalModel(
	env: NodeJS.ProcessEnv = process.env,
	fetchImpl: typeof fetch = fetch
): Promise< LocalModel | undefined > {
	// NVOOS_FORGE_NO_LOCAL=1 opts out (a shared runner that must never touch
	// the loopback interface, or a test that asserts the no-model path).
	if ( env.NVOOS_FORGE_NO_LOCAL === '1' ) {
		return Promise.resolve( undefined );
	}
	if ( ! cached ) {
		cached = probe( env, fetchImpl );
	}
	return cached;
}
