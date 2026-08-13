/**
 * Per-site provider credential resolution (Phase 2 — proposal 027).
 *
 * Resolution order for getCredential(site, envName):
 *   1. SITE_PROVIDER_KEYS[site][envName.toLowerCase()] — per-site override
 *   2. process.env[envName]                          — shared pool fallback
 *   3. null                                          — callers return 503
 *
 * In single-tenant mode (no SITE_PROVIDER_KEYS) behavior is identical to
 * reading process.env directly. PROVIDER_KEYS_STRICT=1 disables the pool
 * fallback in multi-tenant mode (per-site keys only, fail closed).
 *
 * Values are never logged by this module; callers own that discipline.
 */

/**
 * Canonical credential names understood by the worker (lowercase form of
 * the env var name). Map keys in SITE_PROVIDER_KEYS must match these
 * exactly (e.g. "openai_api_key", "firefly_client_id"). Anything else
 * triggers a one-time typo warning.
 */
const PROVIDER_NAMES = new Set( [
	'openai_api_key',
	'gemini_api_key',
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

let cachedMap = null;
let cacheChecked = false;
let warnedMalformed = false;
let warnedUnknown = false;

/**
 * Parse the SITE_PROVIDER_KEYS env var (cached). Malformed JSON or
 * non-object values warn once and yield null (fail closed per provider,
 * never crash boot).
 *
 * @return {Object|null} Map of site slug -> { provider_name: value }.
 */
export function parseSiteProviderKeys() {
	if ( cacheChecked ) {
		return cachedMap;
	}
	cacheChecked = true;

	const raw = process.env.SITE_PROVIDER_KEYS;
	if ( ! raw ) {
		return cachedMap;
	}
	try {
		const parsed = JSON.parse( raw );
		if ( ! parsed || 'object' !== typeof parsed || Array.isArray( parsed ) ) {
			throw new Error( 'not an object' );
		}
		for ( const [ site, entries ] of Object.entries( parsed ) ) {
			if ( ! entries || 'object' !== typeof entries || Array.isArray( entries ) ) {
				throw new Error( `site "${ site }" entries are not an object` );
			}
			if ( ! warnedUnknown ) {
				for ( const name of Object.keys( entries ) ) {
					if ( ! PROVIDER_NAMES.has( String( name ).toLowerCase() ) ) {
						warnedUnknown = true;
						console.warn(
							`[Providers] Unknown credential name "${ name }" in SITE_PROVIDER_KEYS (site "${ site }") — typo?`
						);
					}
				}
			}
			for ( const [ name, value ] of Object.entries( entries ) ) {
				if ( 'string' !== typeof value ) {
					throw new Error( `credential "${ name }" for site "${ site }" is not a string` );
				}
			}
		}
		cachedMap = parsed;
		return cachedMap;
	} catch ( err ) {
		if ( ! warnedMalformed ) {
			warnedMalformed = true;
			console.error( '[Providers] SITE_PROVIDER_KEYS is invalid and was ignored:', err.message );
		}
		return cachedMap;
	}
}

/**
 * Test-only: reset the parse cache so env changes take effect.
 */
export function resetProviderKeysCache() {
	cacheChecked = false;
	cachedMap = null;
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
