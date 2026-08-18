/**
 * Per-site provider credential resolution (Phase 2 — proposal 027, extended
 * by Phase 3 — proposal 028).
 *
 * Resolution order for getCredential(site, envName):
 *   1. SITE_PROVIDER_KEYS[site][envName.toLowerCase()] — per-site override
 *      (merged with per-site SITE_PROVIDER_KEYS_<SLUG> env vars and, when
 *      PROVIDER_KEYS_FILE is set, the watched file — env/file win over JSON).
 *   2. process.env[envName]                          — shared pool fallback
 *   3. null                                          — callers return 503
 *
 * In single-tenant mode (no SITE_PROVIDER_KEYS) behavior is identical to
 * reading process.env directly. PROVIDER_KEYS_STRICT=1 disables the pool
 * fallback in multi-tenant mode (per-site keys only, fail closed).
 *
 * PROVIDER_KEYS_FILE (Phase 3 W5): when set, credentials load from a JSON
 * file (same shape as SITE_PROVIDER_KEYS) and hot-swap on change via a
 * debounced fs.watch. Malformed updates are rejected and the previous map
 * stays active — there is never a broken window. No new HTTP surface.
 *
 * Values are never logged by this module; callers own that discipline.
 */

import fs from 'fs';

/**
 * Canonical credential names understood by the worker (lowercase form of
 * the env var name). Map keys must match these exactly
 * (e.g. "openai_api_key", "firefly_client_id"). Anything else triggers a
 * one-time typo warning.
 */
const PROVIDER_NAMES = new Set( [
	'openai_api_key',
	'gemini_api_key',
	'deepseek_api_key',
	'stability_api_key',
	'replicate_api_key',
	'midjourney_api_key',
	'leonardo_api_key',
	'ideogram_api_key',
	'getimg_api_key',
	'deepai_api_key',
	'firefly_client_id',
	'firefly_client_secret',
	'anthropic_api_key',
	'twitter_api_key',
	'twitter_api_secret',
	'twitter_access_token',
	'twitter_access_token_secret',
	'facebook_page_token',
	'instagram_access_token',
	'instagram_business_account_id',
	'linkedin_token',
	'linkedin_person_urn',
] );

let cacheChecked = false;
let cachedMap = null; // merged result (null when empty)
let cachedJsonMap = {}; // SITE_PROVIDER_KEYS
let cachedEnvMaps = {}; // SITE_PROVIDER_KEYS_<SLUG>
let cachedFileMap = {}; // PROVIDER_KEYS_FILE
let warnedMalformed = false;
let warnedUnknown = false;

/**
 * Recompute the merged map (JSON -> per-site env -> file, later wins).
 *
 * @return {Object|null} Merged map or null when empty.
 */
function recomputeProviderMap() {
	const merged = { ...cachedJsonMap, ...cachedEnvMaps, ...cachedFileMap };
	cachedMap = 0 === Object.keys( merged ).length ? null : merged;
	return cachedMap;
}

/**
 * Validate one site's credential-entries object (name -> string). Warns
 * once on unknown credential names; throws on structural problems.
 *
 * @param {string} site    Site slug (for messages only).
 * @param {Object} entries Parsed entries object.
 * @return {Object} The validated entries (passed through).
 */
function validateSiteEntries( site, entries ) {
	if ( ! entries || 'object' !== typeof entries || Array.isArray( entries ) ) {
		throw new Error( `site "${ site }" entries are not an object` );
	}
	if ( ! warnedUnknown ) {
		for ( const name of Object.keys( entries ) ) {
			if ( ! PROVIDER_NAMES.has( String( name ).toLowerCase() ) ) {
				warnedUnknown = true;
				console.warn(
					`[Providers] Unknown credential name "${ name }" in provider keys (site "${ site }") — typo?`
				);
			}
		}
	}
	for ( const [ name, value ] of Object.entries( entries ) ) {
		if ( 'string' !== typeof value ) {
			throw new Error( `credential "${ name }" for site "${ site }" is not a string` );
		}
	}
	return entries;
}

/**
 * Validate a parsed provider map (site slug -> entries).
 *
 * @param {Object} parsed Parsed JSON object.
 * @return {Object} The validated map (passed through).
 */
function validateProviderMap( parsed ) {
	if ( ! parsed || 'object' !== typeof parsed || Array.isArray( parsed ) ) {
		throw new Error( 'not an object' );
	}
	for ( const [ site, entries ] of Object.entries( parsed ) ) {
		validateSiteEntries( site, entries );
	}
	return parsed;
}

/**
 * Parse one JSON string into a validated provider map.
 *
 * @param {string} raw Raw JSON.
 * @return {Object|null} Map or null when empty/invalid.
 */
function parseJsonMap( raw ) {
	if ( ! raw ) {
		return null;
	}
	try {
		return validateProviderMap( JSON.parse( raw ) );
	} catch ( err ) {
		if ( ! warnedMalformed ) {
			warnedMalformed = true;
			console.error( '[Providers] Provider keys are invalid and were ignored:', err.message );
		}
		return null;
	}
}

/**
 * Collect per-site provider-key env vars (Phase 3 W3): every
 * SITE_PROVIDER_KEYS_<SLUG> (slug uppercased, hyphens -> underscores)
 * whose value parses as a JSON object. These merge OVER the global
 * SITE_PROVIDER_KEYS JSON so platform env-size limits can be worked
 * around one site at a time.
 *
 * @return {Object} Map of site slug -> { credential_name: value }.
 */
function collectPerSiteEnvMaps() {
	const merged = {};
	for ( const [ key, value ] of Object.entries( process.env ) ) {
		const match = /^SITE_PROVIDER_KEYS_([A-Z0-9_]+)$/.exec( key );
		if ( ! match || ! value ) {
			continue;
		}
		const slug = match[ 1 ].toLowerCase().replace( /_/g, '-' );
		// Each env var holds ONE site's entries object (not a full site map).
		try {
			merged[ slug ] = validateSiteEntries( slug, JSON.parse( value ) );
		} catch ( err ) {
			if ( ! warnedMalformed ) {
				warnedMalformed = true;
				console.error( `[Providers] ${ key } is invalid and was ignored:`, err.message );
			}
		}
	}
	return merged;
}

/**
 * Parse the effective SITE_PROVIDER_KEYS config (cached). Sources, merged in
 * priority order (later wins): global JSON env var, per-site env vars,
 * PROVIDER_KEYS_FILE (when set).
 *
 * @return {Object|null} Map of site slug -> { provider_name: value }.
 */
export function parseSiteProviderKeys() {
	if ( cacheChecked ) {
		return cachedMap;
	}
	cacheChecked = true;

	cachedJsonMap = parseJsonMap( process.env.SITE_PROVIDER_KEYS ) || {};
	cachedEnvMaps = collectPerSiteEnvMaps();

	const filePath = process.env.PROVIDER_KEYS_FILE;
	if ( filePath ) {
		try {
			cachedFileMap = parseJsonMap( fs.readFileSync( filePath, 'utf8' ) ) || {};
		} catch ( err ) {
			console.error( '[Providers] Cannot read PROVIDER_KEYS_FILE:', err.message );
		}
	}

	return recomputeProviderMap();
}

/**
 * Hot-swap the provider map from PROVIDER_KEYS_FILE (Phase 3 W5). Rejects
 * malformed updates and keeps the previous map active. Called by the file
 * watcher and at boot.
 *
 * @return {boolean} True when the map was (re)loaded successfully.
 */
export function reloadProviderKeysFromFile() {
	const filePath = process.env.PROVIDER_KEYS_FILE;
	if ( ! filePath ) {
		return false;
	}
	let fileMap;
	try {
		fileMap = parseJsonMap( fs.readFileSync( filePath, 'utf8' ) );
	} catch ( err ) {
		console.error( '[Providers] PROVIDER_KEYS_FILE reload rejected:', err.message );
		return false;
	}
	if ( ! fileMap ) {
		return false;
	}
	// Replace (not merge) the file-sourced portion so removals take effect.
	cacheChecked = true;
	cachedFileMap = fileMap;
	recomputeProviderMap();
	console.log( '[Providers] Reloaded provider keys from file.' );
	return true;
}

/**
 * Start the debounced file watcher for PROVIDER_KEYS_FILE (Phase 3 W5).
 * No-op when the env var is unset.
 */
export function startProviderKeysWatcher() {
	const filePath = process.env.PROVIDER_KEYS_FILE;
	if ( ! filePath ) {
		return;
	}
	let timer = null;
	try {
		fs.watch( filePath, () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => reloadProviderKeysFromFile(), 1000 );
			timer.unref?.();
		} );
		console.log( `[Providers] Watching PROVIDER_KEYS_FILE: ${ filePath }` );
	} catch ( err ) {
		console.warn( '[Providers] Cannot watch PROVIDER_KEYS_FILE:', err.message );
	}
}

/**
 * Test-only: reset the parse cache so env changes take effect.
 */
export function resetProviderKeysCache() {
	cacheChecked = false;
	cachedMap = null;
	cachedJsonMap = {};
	cachedEnvMaps = {};
	cachedFileMap = {};
	warnedMalformed = false;
	warnedUnknown = false;
}

/**
 * Whether pool fallback is disabled in multi-tenant mode.
 *
 * @return {boolean} True when PROVIDER_KEYS_STRICT=1.
 */
export function isProviderKeysStrict() {
	return '1' === process.env.PROVIDER_KEYS_STRICT;
}

/**
 * Resolve a credential for a site.
 *
 * @param {string} site    Site slug from the auth middleware ('default' in
 *                         single-tenant mode).
 * @param {string} envName Canonical env var name (e.g. 'OPENAI_API_KEY').
 * @return {string|null} Credential value, or null when unavailable.
 */
export function getCredential( site, envName ) {
	const map = parseSiteProviderKeys();
	if ( map && site && 'default' !== site ) {
		const perSite = map[ site ] ? map[ site ][ String( envName ).toLowerCase() ] : undefined;
		if ( 'string' === typeof perSite && perSite ) {
			return perSite;
		}
		if ( isProviderKeysStrict() ) {
			return null;
		}
	}
	return process.env[ envName ] || null;
}

/**
 * Canonical provider names resolvable for a site (health reporting).
 *
 * @param {string} site Site slug.
 * @return {Object} Map of canonical name -> boolean.
 */
export function configuredProviders( site ) {
	const result = {};
	for ( const name of PROVIDER_NAMES ) {
		const envName = name.toUpperCase();
		result[ envName ] = Boolean( getCredential( site, envName ) );
	}
	return result;
}
