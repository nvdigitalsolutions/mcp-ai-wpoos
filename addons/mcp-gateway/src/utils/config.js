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
 *   PUBLIC_SITE_SLUGS              Comma-separated slugs visible on the
 *                                  public /health endpoint (privacy filter
 *                                  for private client sites). Unset or `*` =
 *                                  all configured sites (legacy behaviour);
 *                                  /health/full always reports every site.
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
 *   GATEWAY_OAUTH_DEFAULT_SITES    Optional fallback: comma-separated site
 *                                  slugs granted to a valid OAuth token
 *                                  that carries no `site:<slug>` scope.
 *                                  ChatGPT's connector requests only the
 *                                  AS's OIDC scopes, never resource scopes —
 *                                  without this fallback such tokens get
 *                                  403 insufficient_scope. Unset = reject.
 *   TOOL_QUOTAS                    Per-tool quotas, compact form:
 *                                  `<pattern>:<rate>/<window>:<burst>` —
 *                                  e.g. `delete_post:5/1h:2,woo_*:60/1m:*`.
 *                                  Most-specific pattern wins; `*` bursts
 *                                  equal the rate. (Proposal 094, Phase 1.)
 *   GATEWAY_POLICY                 Policy ruleset (JSON string) — first-match
 *                                  wins with allow/deny/require_approval
 *                                  effects and tool/site/key/time/geo
 *                                  conditions. (Proposal 094, Phase 2.)
 *   POLICY_FILE                    Alternative: path to a JSON policy file
 *                                  (Git-versioned policy; read at boot).
 *   SHADOW_POLICY                  Shadow ruleset (JSON string) — evaluated
 *                                  and logged, never enforced. (Phase 4.)
 *   GEO_MAP                        JSON object mapping public key ids to ISO
 *                                  country codes (used when not behind a
 *                                  trusted proxy edge).
 *   GATEWAY_ADMIN_TOKEN            Operator token for the /admin/* surface
 *                                  (policy inspection, simulation, catalog).
 *   SITE_LIFECYCLE                 Per-site lifecycle states for the catalog:
 *                                  `slug-a:draft,slug-b:published`.
 */

import fs from 'node:fs';
import { parseToolQuotas } from '../quota/config.js';
import { parsePolicy } from '../policy/evaluator.js';
import { parseGeoMap } from '../policy/geo.js';

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
 * @return {object} `{ enabled, issuer, jwksUri, resource, defaultSites }`.
 */
export function parseOAuthConfig( env, errors ) {
	const rawIssuer = String( env.GATEWAY_OAUTH_ISSUER || '' ).trim();
	if ( ! rawIssuer ) {
		return { enabled: false, issuer: '', jwksUri: '', resource: DEFAULT_OAUTH_RESOURCE, defaultSites: [] };
	}

	const issuer = normalizeIssuer( rawIssuer );
	const resource = String( env.GATEWAY_OAUTH_RESOURCE || '' ).trim() || DEFAULT_OAUTH_RESOURCE;
	const jwksUri = String( env.GATEWAY_OAUTH_JWKS_URI || '' ).trim() || issuerUrl( issuer, '.well-known/jwks.json' );
	const defaultSites = String( env.GATEWAY_OAUTH_DEFAULT_SITES || '' )
		.split( ',' )
		.map( ( slug ) => slug.trim().toLowerCase() )
		.filter( Boolean );

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

	return { enabled: true, issuer, jwksUri, resource, defaultSites };
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
 * Parse PUBLIC_SITE_SLUGS — the privacy filter for the public /health
 * endpoint. Absent, empty, or `*` means "all configured sites" (legacy
 * behaviour); otherwise a Set of the slugs the public endpoint may show.
 *
 * @param {string} raw Raw env value.
 * @return {Set<string>|null} Slug set, or null for "all".
 */
export function parsePublicSlugs( raw ) {
	const trimmed = String( raw || '' ).trim();
	if ( ! trimmed || '*' === trimmed ) {
		return null;
	}
	const slugs = new Set(
		trimmed
			.split( ',' )
			.map( ( slug ) => slug.trim() )
			.filter( Boolean )
	);
	return slugs.size ? slugs : null;
}

/**
 * Load and validate the full gateway configuration from an env object.
 * Collects errors rather than throwing so every problem is reported at once.
 *
 * @param {object} env Env object (defaults to process.env).
 * @return {object} `{ keys, previousKeys, sites, publicSlugs, errors,
 *                   warnings }` plus the governance fields.
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

	// ── Public /health privacy filter ───────────────────────────────
	// Unknown slugs are warnings, not errors: a typo hides sites (the safe
	// direction) and must never take the public fleet surface down.
	const publicSlugs = parsePublicSlugs( env.PUBLIC_SITE_SLUGS );
	const warnings = [];
	if ( publicSlugs ) {
		for ( const slug of publicSlugs ) {
			if ( ! sites.has( slug ) ) {
				warnings.push(
					`PUBLIC_SITE_SLUGS lists "${ slug }" which is not a configured site — the public /health will not show it.`
				);
			}
		}
	}

	// ── OAuth 2.1 resource server (opt-in, inert unless configured) ──
	const oauth = parseOAuthConfig( env, errors );
	if ( oauth.enabled ) {
		for ( const slug of oauth.defaultSites ) {
			if ( ! sites.has( slug ) ) {
				errors.push( `GATEWAY_OAUTH_DEFAULT_SITES references unconfigured site "${ slug }".` );
			}
		}
	}

	// ── Per-tool quotas (Proposal 094, Phase 1) ─────────────────────
	const quotas = parseToolQuotas( env.TOOL_QUOTAS, errors );

	// ── Policy & governance (Proposal 094, Phases 2–5) ──────────────
	const policy = parsePolicy( loadPolicySource( env, errors ), errors );
	const shadowPolicy = parsePolicy( String( env.SHADOW_POLICY || '' ).trim(), errors );
	const geoMap = parseGeoMap( env.GEO_MAP, errors );
	const adminToken = String( env.GATEWAY_ADMIN_TOKEN || '' ).trim();
	const trustProxy = '1' === env.TRUST_PROXY;
	const siteLifecycle = parseSiteLifecycle( env.SITE_LIFECYCLE, errors );
	const auditSinkUrl = String( env.AUDIT_SINK_URL || '' ).trim();
	const auditSinkSecret = String( env.AUDIT_SINK_SECRET || '' ).trim();
	if ( Boolean( auditSinkUrl ) !== Boolean( auditSinkSecret ) ) {
		errors.push( 'AUDIT_SINK_URL and AUDIT_SINK_SECRET must be set together.' );
	}

	return {
		keys,
		previousKeys,
		sites,
		publicSlugs,
		oauth,
		quotas,
		policy,
		shadowPolicy,
		geoMap,
		adminToken,
		trustProxy,
		siteLifecycle,
		auditSinkUrl,
		auditSinkSecret,
		errors,
		warnings,
	};
}

/**
 * Resolve the policy source: inline GATEWAY_POLICY wins; otherwise
 * POLICY_FILE is read from disk (Git-versioned policy).
 *
 * @param {object}   env    Env object.
 * @param {string[]} errors Shared error collector.
 * @return {string} Policy JSON, or '' when unset/unreadable.
 */
function loadPolicySource( env, errors ) {
	const inline = String( env.GATEWAY_POLICY || '' ).trim();
	if ( inline ) {
		return inline;
	}
	const file = String( env.POLICY_FILE || '' ).trim();
	if ( ! file ) {
		return '';
	}
	try {
		return fs.readFileSync( file, 'utf8' );
	} catch ( err ) {
		errors.push( `POLICY_FILE could not be read: ${ err.message }` );
		return '';
	}
}

/** Lifecycle states the catalog recognises. */
const LIFECYCLE_STATES = new Set( [ 'draft', 'test', 'published', 'retired' ] );

/**
 * Parse SITE_LIFECYCLE (`slug-a:draft,slug-b:published`). Unknown states are
 * reported; unset values default to `published` at read time.
 *
 * @param {string}   raw    Raw env value.
 * @param {string[]} errors Shared error collector.
 * @return {Map<string, string>} slug → state.
 */
export function parseSiteLifecycle( raw, errors = [] ) {
	const map = new Map();
	if ( ! raw || ! String( raw ).trim() ) {
		return map;
	}
	for ( const entry of String( raw ).split( ',' ) ) {
		const trimmed = entry.trim();
		if ( ! trimmed ) {
			continue;
		}
		const [ slug, state ] = trimmed.split( ':' ).map( ( part ) => part.trim() );
		if ( ! slug || ! LIFECYCLE_STATES.has( state ) ) {
			errors.push(
				`SITE_LIFECYCLE entry "${ trimmed }" must be <slug>:<draft|test|published|retired>.`
			);
			continue;
		}
		map.set( slug, state );
	}
	return map;
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
