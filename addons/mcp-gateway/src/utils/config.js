/**
 * Environment configuration for the NV oOS MCP Gateway.
 *
 * Everything is env-var driven (Cloudways Velocity dashboard-friendly — no
 * admin UI, same stance as the media worker). Parsing is a pure function of
 * the env object so tests can inject configurations without mutating
 * process.env.
 *
 * Variables:
 *   GATEWAY_PUBLIC_KEYS            Public API keys → site slugs. Two forms:
 *                                  `key=slug-a,slug-b;key2=slug-c`, or a JSON
 *                                  object `{"key":["slug-a","slug-b"]}`.
 *   GATEWAY_PUBLIC_KEYS_PREVIOUS   Same format; accepted during key rotation
 *                                  windows (warns once, like the worker's
 *                                  *_PREVIOUS tokens).
 *   NVOOS_SITE_<SLUG>_URL          Upstream site MCP endpoint, full URL incl.
 *                                  /wp-json/mcp-ai/v1/mcp. Slug uppercased,
 *                                  hyphens → underscores.
 *   NVOOS_SITE_<SLUG>_TOKEN        Fleet Operator token (op_xxxx.SECRET) for
 *                                  the upstream site. Never logged, never
 *                                  returned.
 *   AUTH_MODE                      `strict` (default) fails closed at boot
 *                                  when no public keys are configured.
 *   UPSTREAM_TIMEOUT_MS            Per-site request timeout (default 20000).
 *                                  Applies to tools/list and notifications.
 *   UPSTREAM_TOOL_TIMEOUT_MS        Budget for tools/call proxying only —
 *                                  long-running tools (deep_research etc.)
 *                                  get more room without slowing tools/list.
 *                                  Defaults to UPSTREAM_TIMEOUT_MS.
 *   GATEWAY_OAUTH_ISSUER           OAuth 2.1 authorization server issuer URL
 *                                  (e.g. an Auth0 tenant, trailing slash ok).
 *                                  When set, the gateway acts as an RFC 9728
 *                                  protected resource: well-known metadata,
 *                                  WWW-Authenticate challenges, and JWT
 *                                  bearer tokens alongside static keys.
 *   GATEWAY_OAUTH_JWKS_URI         Optional JWKS override (defaults to the
 *                                  issuer's /.well-known/jwks.json).
 *   GATEWAY_OAUTH_RESOURCE         RFC 8707 resource identifier — the
 *                                  canonical audience for access tokens
 *                                  (default https://mcp.nvoos.pro).
 */

const SLUG_RE = /^[a-z0-9][a-z0-9-]{0,39}$/;
const MIN_KEY_LENGTH = 16;
const DEFAULT_OAUTH_RESOURCE = 'https://mcp.nvoos.pro';

/**
 * Normalise an issuer URL: trim whitespace, keep one trailing slash.
 *
 * @param {string} raw Raw issuer value.
 * @return {string} Normalised issuer (may be empty).
 */
export function normalizeIssuer( raw ) {
	return String( raw || '' ).trim().replace( /\/+$/, '' ) + '/';
}

/**
 * Join an issuer and a well-known path into one URL without doubled slashes.
 *
 * @param {string} issuer Normalised issuer (trailing slash).
 * @param {string} path   Relative path without leading slash.
 * @return {string} Absolute URL.
 */
export function issuerUrl( issuer, path ) {
	return issuer.replace( /\/$/, '' ) + '/' + String( path ).replace( /^\/+/, '' );
}

/**
 * Parse the OAuth 2.1 resource-server configuration.
 *
 * Inert unless GATEWAY_OAUTH_ISSUER is set (fail-closed on invalid values —
 * problems are reported through the shared errors array).
 *
 * @param {object} env    Env object.
 * @param {string[]} errors Shared error collector.
 * @return {object} `{ enabled, issuer, jwksUri, resource }`.
 */
export function parseOAuthConfig( env, errors ) {
	const rawIssuer = String( env.GATEWAY_OAUTH_ISSUER || '' ).trim();
	if ( ! rawIssuer ) {
		return { enabled: false, issuer: '', jwksUri: '', resource: DEFAULT_OAUTH_RESOURCE };
	}

	const issuer = normalizeIssuer( rawIssuer );
	const resource = String( env.GATEWAY_OAUTH_RESOURCE || '' ).trim() || DEFAULT_OAUTH_RESOURCE;
	const jwksUri = String( env.GATEWAY_OAUTH_JWKS_URI || '' ).trim() || issuerUrl( issuer, '.well-known/jwks.json' );

	for ( const [ label, value ] of [
		[ 'GATEWAY_OAUTH_ISSUER', issuer ],
		[ 'GATEWAY_OAUTH_RESOURCE', resource ],
		[ 'GATEWAY_OAUTH_JWKS_URI', jwksUri ],
	] ) {
		try {
			const parsed = new URL( value );
			if ( 'https:' !== parsed.protocol ) {
				throw new Error( 'protocol' );
			}
		} catch {
			errors.push( `OAuth ${ label } must be an absolute https URL.` );
		}
	}

	return { enabled: true, issuer, jwksUri, resource };
}

/**
 * Environment suffix for a site slug: `site-a` → `SITE_A`.
 *
 * @param {string} slug Site slug.
 * @return {string} Uppercased, underscore-joined suffix.
 */
export function envSuffix( slug ) {
	return String( slug ).toUpperCase().replace( /-/g, '_' );
}

/**
 * Parse the public-keys map env var. Accepts both the `key=slugs;…` form
 * and a JSON object form.
 *
 * @param {string} raw Raw env value.
 * @return {Map<string, string[]>|null} key → slug list, or null when
 *                                      unset/invalid.
 */
export function parseKeyMap( raw ) {
	if ( ! raw ) {
		return null;
	}
	const map = new Map();

	const trimmed = raw.trim();
	if ( trimmed.startsWith( '{' ) ) {
		try {
			const parsed = JSON.parse( trimmed );
			if ( parsed && 'object' === typeof parsed && ! Array.isArray( parsed ) ) {
				for ( const [ key, slugs ] of Object.entries( parsed ) ) {
					const list = Array.isArray( slugs ) ? slugs.map( String ) : [ String( slugs ) ];
					if ( key ) {
						map.set( key, list );
					}
				}
				return map.size ? map : null;
			}
		} catch {
			// Fall through to the legacy form.
		}
	}

	for ( const entry of trimmed.split( ';' ) ) {
		const eq = entry.indexOf( '=' );
		if ( -1 === eq ) {
			continue;
		}
		const key = entry.slice( 0, eq ).trim();
		const slugs = entry
			.slice( eq + 1 )
			.split( ',' )
			.map( ( slug ) => slug.trim() )
			.filter( Boolean );
		if ( key && slugs.length ) {
			map.set( key, slugs );
		}
	}
	return map.size ? map : null;
}

/**
 * Load and validate the full gateway configuration from an env object.
 * Collects errors rather than throwing so every problem is reported at once.
 *
 * @param {object} env Env object (defaults to process.env).
 * @return {object} `{ keys, previousKeys, sites, errors }`.
 */
export function loadConfig( env = process.env ) {
	const errors = [];

	const keys = parseKeyMap( env.GATEWAY_PUBLIC_KEYS ) || new Map();
	const previousKeys = parseKeyMap( env.GATEWAY_PUBLIC_KEYS_PREVIOUS ) || new Map();

	// ── Sites ───────────────────────────────────────────────────────
	const sites = new Map();
	const seenSlugs = new Set();
	for ( const slugs of [ ...keys.values(), ...previousKeys.values() ] ) {
		for ( const slug of slugs ) {
			if ( seenSlugs.has( slug ) ) {
				continue;
			}
			seenSlugs.add( slug );

			if ( ! SLUG_RE.test( slug ) ) {
				errors.push(
					`Invalid site slug "${ slug }" — must match ${ SLUG_RE.source } (the env suffix would be unusable).`
				);
				continue;
			}
			const suffix = envSuffix( slug );
			const url = env[ `NVOOS_SITE_${ suffix }_URL` ] || '';
			const token = env[ `NVOOS_SITE_${ suffix }_TOKEN` ] || '';
			if ( ! url || ! token ) {
				errors.push(
					`Site "${ slug }" is missing NVOOS_SITE_${ suffix }_URL or NVOOS_SITE_${ suffix }_TOKEN.`
				);
				continue;
			}
			try {
				// eslint-disable-next-line no-unused-vars
				const parsed = new URL( url );
				if ( ! /^https?:$/.test( parsed.protocol ) ) {
					throw new Error( 'protocol' );
				}
			} catch {
				errors.push( `Site "${ slug }" has an invalid upstream URL.` );
				continue;
			}
			sites.set( slug, { slug, url, token } );
		}
	}

	// ── Keys must resolve to configured sites ──────────────────────
	for ( const [ key, slugs ] of keys ) {
		if ( key.length < MIN_KEY_LENGTH ) {
			errors.push(
				`A public key is shorter than ${ MIN_KEY_LENGTH } characters — use a strong random key.`
			);
		}
		for ( const slug of slugs ) {
			if ( ! sites.has( slug ) ) {
				errors.push( `Public key references site "${ slug }" which is not configured.` );
			}
		}
	}

	const authMode = ( env.AUTH_MODE || 'strict' ).toLowerCase();
	if ( 0 === keys.size && 'strict' === authMode ) {
		errors.push(
			'GATEWAY_PUBLIC_KEYS is not set but AUTH_MODE=strict — the gateway refuses to start unauthenticated.'
		);
	}

	// ── OAuth 2.1 resource server (opt-in, inert unless configured) ──
	const oauth = parseOAuthConfig( env, errors );

	return { keys, previousKeys, sites, oauth, errors };
}

/**
 * Fail closed: throw when the configuration carries any error.
 *
 * @param {object} cfg loadConfig() result.
 * @throws {Error} With every error joined.
 */
export function assertConfig( cfg ) {
	if ( cfg.errors.length ) {
		throw new Error( `[config] ${ cfg.errors.join( ' ' ) }` );
	}
	return cfg;
}
