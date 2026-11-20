// ---------------------------------------------------------------------------
// gate.ts — the page-gate session (Proposal 152).
//
// The forge fetches WordPress over two planes:
//
//   REST   /wp-json/…        authenticated with an Application Password
//   HTML   /, /about/, …     authenticated with NOTHING — until this module
//
// A pre-launch site gates the HTML plane only: builders and coming-soon
// plugins answer `503 + Retry-After`, or a `200` holding page, while
// `/wp-json/` stays open. Content then forges, design does not, and the build
// silently degrades to "the coming-soon page with the client's posts
// underneath". This module opens ONE page session per process and hands it to
// every rendered-HTML surface:
//
//   const gate = await openGate( profile );
//   if ( gate.blocked ) { /* stop, or --allow-blocked */ }
//   // global fetch now carries the session for the CMS host only, and every
//   // Playwright newContext() call picks the cookies up via gateContextOptions
//
// Two credential planes, deliberately separate (verified 2026-11-20):
// Application Passwords "cannot be used to log into wp-admin via wp-login.php"
// — a plugin gate that checks `is_user_logged_in()` needs the SESSION cookie,
// not the REST credential. Both live in env/vault; neither ever reaches the
// profile, the generated repo, an artifact, or a log line.
//
// SECRETS: values are read from the environment (or the AES-GCM vault) and are
// never printed. `describe()` is the only shape that leaves the module for
// logs/MCP payloads, and it carries mechanism names — never a value.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';
import { homedir } from 'node:os';

import { assertSafeHttpUrl } from './ssrf.ts';
import type { SiteProfile } from './types.ts';
import { Vault } from './vault.ts';

/** How the probe reads a rendered page. */
export type GateState = 'open' | 'gated' | 'edge-auth' | 'rate-limited' | 'unknown';

/** Why the page looked gated — the operator's remediation hint. */
export type GateFingerprint =
	| 'elementor-maintenance'
	| 'wp-maintenance-mode'
	| 'seedprod'
	| 'under-construction'
	| 'core-maintenance'
	| 'generic-holding-page';

/** A cookie in the shape Playwright's context options accept. */
export interface ForgeGateCookie {
	name: string;
	value: string;
	domain: string;
	path: string;
	secure?: boolean;
	httpOnly?: boolean;
	sameSite?: 'Strict' | 'Lax' | 'None';
	/** Unix seconds — Playwright accepts it; omitted means a session cookie. */
	expires?: number;
}

/** The probe result — everything a caller needs to decide, with no secrets. */
export interface GateProbe {
	state: GateState;
	status: number;
	/** Seconds, parsed per RFC 9110 §10.2.3 (delta-seconds or HTTP-date). */
	retryAfter?: number;
	/** `GET /wp-json/` answered 200 — the tell that separates the SITE's own
	 *  mode from an edge/WAF block (nothing-to-forge.md §3). */
	restHealthy: boolean;
	server?: string;
	cfCache?: string;
	fingerprint?: GateFingerprint;
	/** Visible text length of the rendered page (tags/comments stripped). */
	visibleChars: number;
	/** Ordered, secret-free observations that justify `state`. */
	evidence: string[];
	/** Ordered, copy-paste-ready remediation steps. */
	remediation: string[];
}

/** Resolved gate configuration (env names + non-secret structure). */
export interface GateConfig {
	mode: 'auto' | 'login' | 'bypass' | 'session' | 'off';
	loginUrl: string;
	username?: string;
	password?: string;
	/** Cookies parsed from WP_GATE_COOKIE / a storageState file. */
	cookies: ForgeGateCookie[];
	query: Record<string, string>;
	headers: Record<string, string>;
	/** True when a page-session mechanism was supplied (not just bypass). */
	hasSession: boolean;
}

/** The opened gate — what a command uses and later closes. */
export interface OpenGate {
	probe: GateProbe;
	/** The credential-aware fetch (identical to the global after install). */
	fetch: typeof fetch;
	cookies: ForgeGateCookie[];
	/** `probe.state !== 'open'` — the caller must stop or opt out. */
	blocked: boolean;
	/** Secret-free description for logs and MCP payloads. */
	describe(): { state: GateState; fingerprint?: GateFingerprint; origin: string; mechanism: string[]; blocked: boolean };
	/** Remove the global fetch wrapper and the Playwright cookies. */
	close(): void;
}

export interface OpenGateOptions {
	/** CLI-only loopback exemption (NVOOS_FORGE_ALLOW_LOOPBACK=1). */
	allowLoopback?: boolean;
	/** Test seam — the underlying fetch. */
	fetchImpl?: typeof fetch;
	/** Hard override (tests / explicit callers). Default: env → profile → auto. */
	mode?: GateConfig['mode'];
	/** Per-request timeout (default 20 s). */
	timeoutMs?: number;
}

const MAX_HTML_BYTES = 512 * 1024;
const DEFAULT_TIMEOUT_MS = 20_000;
const MAX_REDIRECTS = 5;
const DEFAULT_MIN_VISIBLE_CHARS = 200;

const DEFAULT_USER_AGENT =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * Holding-page markers. Deliberately specific — a live site may legitimately
 * contain the WORDS "coming soon", but not a plugin's structural markers.
 */
const REJECT_MARKERS: Array<{ marker: string; fingerprint: GateFingerprint }> = [
	{ marker: 'wpmm-', fingerprint: 'wp-maintenance-mode' },
	{ marker: 'seedprod', fingerprint: 'seedprod' },
	{ marker: 'coming-soon', fingerprint: 'generic-holding-page' },
	{ marker: 'coming_soon', fingerprint: 'generic-holding-page' },
	{ marker: 'under-construction', fingerprint: 'under-construction' },
	{ marker: 'underconstruction', fingerprint: 'under-construction' },
	{ marker: 'upc-bar', fingerprint: 'under-construction' },
	{ marker: 'elementor-maintenance', fingerprint: 'elementor-maintenance' },
	{ marker: 'elementor-coming-soon', fingerprint: 'elementor-maintenance' },
	{ marker: 'briefly unavailable for scheduled maintenance', fingerprint: 'core-maintenance' },
];

/** Structure signals that say "this is a built page, not a holding page". */
const STRUCTURE_SIGNAL = /elementor-element-|wp-block-|wp-content\/|<main[\s>]|class="[^"]*\bentry-content\b/i;

/** Cookie names that belong to a WordPress login session. */
const SESSION_COOKIE = /^wordpress_(logged_in|sec|test_cookie)/;
const LOGGED_IN_COOKIE = /^wordpress_logged_in_/;

const REDIRECT_STATUS = new Set( [ 301, 302, 303, 307, 308 ] );
const HTML_ACCEPT = 'text/html,application/xhtml+xml';

// ---------------------------------------------------------------------------
// Public entry points
// ---------------------------------------------------------------------------

/**
 * Open the page gate for a profile. Always resolves; `blocked` is the verdict.
 * Installs a global fetch wrapper scoped to the profile's host and publishes
 * the session cookies for Playwright. Call `close()` when done.
 */
export async function openGate( profile: SiteProfile, options: OpenGateOptions = {} ): Promise< OpenGate > {
	assertSafeHttpUrl( profile.url, { allowLoopback: options.allowLoopback === true } );
	const base = profile.url.replace( /\/+$/, '' );
	const origin = new URL( base );
	const config = await resolveConfig( profile, base, options );
	const fetchImpl = options.fetchImpl ?? fetch;

	if ( config.mode === 'off' ) {
		return {
			probe: offProbe( base ),
			fetch: fetchImpl,
			cookies: [],
			blocked: false,
			describe: () => ( { state: 'open', origin: origin.host, mechanism: [ 'off' ], blocked: false } ),
			close: () => undefined,
		};
	}

	let probe = await probeGate( profile, { ...options, fetchImpl } );
	let sessionCookies: ForgeGateCookie[] = [];

	if ( probe.state !== 'open' && wantsSession( config ) ) {
		const acquired = await acquireSession( config, base, origin, fetchImpl, options.timeoutMs );
		if ( acquired.cookies.length > 0 ) {
			sessionCookies = acquired.cookies;
			const authed = await probeGate( profile, {
				...options,
				fetchImpl,
				cookies: sessionCookies,
				headers: config.headers,
				query: config.query,
			} );
			probe = { ...authed, evidence: [ `session: ${ acquired.mechanism }`, ...authed.evidence ] };
		} else if ( acquired.error ) {
			probe = {
				...probe,
				evidence: [ `session failed: ${ acquired.error }`, ...probe.evidence ],
			};
		}
	}

	// A bypass token (query/header) or an edge credential is not a session:
	// re-probe with it before declaring the source blocked.
	if (
		probe.state !== 'open' &&
		( Object.keys( config.query ).length > 0 || Object.keys( config.headers ).length > 0 )
	) {
		const retried = await probeGate( profile, {
			...options,
			fetchImpl,
			cookies: sessionCookies,
			headers: config.headers,
			query: config.query,
		} );
		if ( retried.state === 'open' ) {
			probe = {
				...retried,
				evidence: [ `bypass: ${ [ ...Object.keys( config.query ), ...Object.keys( config.headers ) ].join( ', ' ) }`, ...retried.evidence ],
			};
		}
	}

	const blocked = probe.state !== 'open';
	const hasMechanism =
		sessionCookies.length > 0 || Object.keys( config.headers ).length > 0 || Object.keys( config.query ).length > 0;
	// Install even when blocked: a gate that answers 503 on `/` may still serve
	// the real page on a sub-path, and --allow-blocked runs need the session.
	const uninstall = hasMechanism
		? installGateFetch(
				origin.host,
				{ cookies: sessionCookies, headers: config.headers, query: config.query },
				fetchImpl
		  )
		: () => undefined;

	if ( sessionCookies.length > 0 ) {
		setActiveGateCookies( sessionCookies );
	}

	return {
		probe,
		fetch: hasMechanism ? ( globalThis.fetch as typeof fetch ) : fetchImpl,
		cookies: sessionCookies,
		blocked,
		describe: () => ( {
			state: probe.state,
			fingerprint: probe.fingerprint,
			origin: origin.host,
			mechanism: mechanismNames( config, sessionCookies ),
			blocked,
		} ),
		close: () => {
			uninstall();
			setActiveGateCookies( [] );
		},
	};
}

/** Probe a rendered page without opening a session (the `forge gate` verb). */
export async function probeGate(
	profile: SiteProfile,
	options: OpenGateOptions & { cookies?: ForgeGateCookie[]; headers?: Record< string, string >; query?: Record< string, string > } = {}
): Promise< GateProbe > {
	assertSafeHttpUrl( profile.url, { allowLoopback: options.allowLoopback === true } );
	const base = profile.url.replace( /\/+$/, '' );
	const host = new URL( base ).host;
	const fetchImpl = options.fetchImpl ?? fetch;
	const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
	const headers: Record< string, string > = {
		accept: HTML_ACCEPT,
		'user-agent': DEFAULT_USER_AGENT,
		...( options.headers ?? {} ),
	};
	const cookieHeader = cookieHeaderOf( options.cookies ?? [] );
	if ( cookieHeader ) {
		headers.cookie = cookieHeader;
	}
	const target = applyQuery( base + '/', options.query ?? {} );

	const home = await fetchImpl( target, {
		redirect: 'manual',
		headers,
		signal: AbortSignal.timeout( timeoutMs ),
	} );
	const status = home.status;
	const location = home.headers.get( 'location' ) ?? '';
	const retryAfter = parseRetryAfter( home.headers.get( 'retry-after' ) );
	const server = home.headers.get( 'server' ) ?? undefined;
	const cfCache = home.headers.get( 'cf-cache-status' ) ?? undefined;
	const html = status >= 200 && status < 300 ? ( await home.text() ).slice( 0, MAX_HTML_BYTES ) : '';
	const visibleChars = visibleTextLength( html );
	const restHealthy = await checkRest( base, fetchImpl, timeoutMs );

	const evidence: string[] = [];
	const base_ = {
		status,
		retryAfter,
		restHealthy,
		server,
		cfCache,
		visibleChars,
	};

	// 1. An edge gate redirects to its identity provider.
	if ( REDIRECT_STATUS.has( status ) ) {
		evidence.push( `HTTP ${ status } → ${ location || '(no location)' }` );
		if ( /cloudflareaccess\.com|\/cdn-cgi\/access/i.test( location ) ) {
			return { ...base_, state: 'edge-auth', evidence, remediation: remediationFor( 'edge' ) };
		}
		return { ...base_, state: 'unknown', evidence, remediation: remediationFor( 'unknown' ) };
	}

	// 2. Server-level basic auth (staging hosts, `auth_basic`).
	const authenticate = home.headers.get( 'www-authenticate' ) ?? '';
	if ( ( status === 401 || status === 403 ) && /basic/i.test( authenticate ) ) {
		evidence.push( `HTTP ${ status } with WWW-Authenticate: Basic` );
		return { ...base_, state: 'edge-auth', evidence, remediation: remediationFor( 'edge' ) };
	}

	// 3. A 503 with Retry-After: the site's own mode when REST is healthy,
	//    otherwise a host/WAF block (nothing-to-forge.md §3).
	if ( status === 503 || status === 429 ) {
		evidence.push( `HTTP ${ status }${ retryAfter !== undefined ? ` (Retry-After ${ retryAfter }s)` : '' }` );
		evidence.push( restHealthy ? '/wp-json/ answers 200 — the site is up; the HTML gate is the site\'s own mode' : '/wp-json/ is not answering 200 either' );
		return {
			...base_,
			state: restHealthy ? 'gated' : 'rate-limited',
			evidence,
			remediation: restHealthy ? remediationFor( 'plugin' ) : remediationFor( 'rate-limited', retryAfter ),
		};
	}

	// 4. Anything else that is not OK is inconclusive, not "open".
	if ( status < 200 || status >= 300 ) {
		evidence.push( `HTTP ${ status }` );
		return { ...base_, state: 'unknown', evidence, remediation: remediationFor( 'unknown' ) };
	}

	// 5. A 200 that is actually a holding page (the silent case).
	const marker = htmlMarker( html, profile.gate?.verify?.rejectMarkers );
	if ( marker ) {
		evidence.push( `holding-page marker "${ marker.marker }" in the rendered HTML` );
		return {
			...base_,
			state: 'gated',
			fingerprint: marker.fingerprint,
			evidence,
			remediation: remediationFor( marker.fingerprint ),
		};
	}
	const minVisible = profile.gate?.verify?.minVisibleChars ?? DEFAULT_MIN_VISIBLE_CHARS;
	const hasStructure = STRUCTURE_SIGNAL.test( html );
	// `noindex` is the corroborating signal: a holding page forbids indexing,
	// a real (if small) page does not. Without it, a short legitimate page
	// would be misread as a gate — the false positive that blocks a real forge.
	const noindex =
		/<meta[^>]+name\s*=\s*["']robots["'][^>]+content\s*=\s*["'][^"']*noindex/i.test( html ) ||
		/noindex/i.test( home.headers.get( 'x-robots-tag' ) ?? '' );
	if ( visibleChars < minVisible && ! hasStructure && ( noindex || ! restHealthy ) ) {
		evidence.push(
			`${ visibleChars } visible characters, no builder/block markers (< ${ minVisible })` +
				`${ noindex ? ', noindex' : '' }${ restHealthy ? '' : ', /wp-json/ not 200' }`
		);
		return {
			...base_,
			state: 'gated',
			fingerprint: 'generic-holding-page',
			evidence,
			remediation: remediationFor( 'generic-holding-page' ),
		};
	}

	if ( cfCache === 'HIT' ) {
		evidence.push( 'cf-cache-status: HIT — the edge may be serving an ANONYMOUS copy to an authenticated request' );
		return { ...base_, state: 'unknown', evidence, remediation: remediationFor( 'cache' ) };
	}

	evidence.push( `${ visibleChars } visible characters, builder/block markers present${ restHealthy ? ', /wp-json/ 200' : '' }` );
	return { ...base_, state: 'open', evidence, remediation: [] };
}

// ---------------------------------------------------------------------------
// Playwright seam
// ---------------------------------------------------------------------------

let activeCookies: ForgeGateCookie[] = [];

/** Publish the session cookies for every Playwright context created next. */
export function setActiveGateCookies( cookies: ForgeGateCookie[] ): void {
	activeCookies = cookies;
}

/**
 * Merge the active gate cookies into `browser.newContext()` options. Cookies
 * carry their own `domain`/`path`, so Chromium applies them per origin — a
 * stale cookie from another site in the same process cannot leak.
 */
export function gateContextOptions< const T extends object >( overrides: T ): T & { cookies?: ForgeGateCookie[] } {
	if ( activeCookies.length === 0 ) {
		return overrides;
	}
	return { ...overrides, cookies: [ ...activeCookies ] };
}

// ---------------------------------------------------------------------------
// Config resolution (env → vault; references only in the profile)
// ---------------------------------------------------------------------------

async function resolveConfig( profile: SiteProfile, base: string, options: OpenGateOptions ): Promise< GateConfig > {
	const gate = profile.gate ?? {};
	const env = process.env;
	const mode = normalizeMode( options.mode ?? env.WP_GATE_MODE ?? gate.mode ?? 'auto' );
	const loginUrl = new URL( gate.loginUrl ?? env.WP_GATE_LOGIN_URL ?? '/wp-login.php', base + '/' ).href;
	const usernameEnv = gate.userEnv ?? 'WP_GATE_USERNAME';
	const passwordEnv = gate.passwordEnv ?? 'WP_GATE_PASSWORD';
	const cookieEnv = gate.session?.cookieEnv ?? 'WP_GATE_COOKIE';
	const storageStateEnv = gate.session?.storageStateEnv ?? 'WP_GATE_STORAGE_STATE';

	const username = env[ usernameEnv ] ?? ( await vaultValue( base, 'username' ) );
	const password = env[ passwordEnv ] ?? ( await vaultValue( base, 'password' ) );

	let cookies = parseCookieString( env[ cookieEnv ] ?? ( await vaultValue( base, 'cookie' ) ) ?? '', new URL( base ).hostname );
	const storageStatePath = env[ storageStateEnv ];
	if ( storageStatePath && cookies.length === 0 ) {
		cookies = await cookiesFromStorageState( storageStatePath, new URL( base ).hostname );
	}

	const headers: Record< string, string > = { ...( gate.bypass?.headers ?? {} ) };
	const extraHeaders = env.WP_GATE_HEADERS_JSON ? parseJsonObject( env.WP_GATE_HEADERS_JSON ) : {};
	Object.assign( headers, extraHeaders );

	// Cloudflare Access service tokens (standard header pair).
	if ( env.CF_ACCESS_CLIENT_ID && env.CF_ACCESS_CLIENT_SECRET ) {
		headers[ 'cf-access-client-id' ] = env.CF_ACCESS_CLIENT_ID;
		headers[ 'cf-access-client-secret' ] = env.CF_ACCESS_CLIENT_SECRET;
	}

	// Staging HTTP Basic auth (nginx/apache auth_basic, WP Engine, Kinsta).
	if ( env.WP_GATE_HTTP_AUTH ) {
		headers.authorization = `Basic ${ Buffer.from( env.WP_GATE_HTTP_AUTH, 'utf8' ).toString( 'base64' ) }`;
	}
	if ( mode === 'off' ) {
		return { mode, loginUrl, username, password, cookies: [], query: {}, headers: {}, hasSession: false };
	}

	const query = { ...( gate.bypass?.query ?? {} ), ...( env.WP_GATE_QUERY ? parseQuery( env.WP_GATE_QUERY ) : {} ) };
	const hasSession = Boolean( ( username && password ) || cookies.length > 0 );

	return { mode, loginUrl, username, password, cookies, query, headers, hasSession };
}

function normalizeMode( value: string ): GateConfig['mode'] {
	const normalized = String( value ).trim().toLowerCase();
	return normalized === 'login' || normalized === 'bypass' || normalized === 'session' || normalized === 'off'
		? normalized
		: 'auto';
}

function wantsSession( config: GateConfig ): boolean {
	if ( config.mode === 'bypass' ) {
		return false;
	}
	return config.hasSession;
}

async function vaultValue( base: string, key: string ): Promise< string | undefined > {
	try {
		const vault = new Vault();
		const host = new URL( base ).hostname;
		return await vault.get( `gate.${ host.replace( /[^a-z0-9.-]/gi, '-' ) }.${ key }` );
	} catch {
		// No key configured / unreadable vault — env-only is a valid state.
		return undefined;
	}
}

// ---------------------------------------------------------------------------
// Session acquisition
// ---------------------------------------------------------------------------

interface AcquiredSession {
	cookies: ForgeGateCookie[];
	mechanism: string;
	error?: string;
}

async function acquireSession(
	config: GateConfig,
	base: string,
	origin: URL,
	fetchImpl: typeof fetch,
	timeoutMs: number = DEFAULT_TIMEOUT_MS
): Promise< AcquiredSession > {
	if ( config.cookies.length > 0 ) {
		return { cookies: config.cookies, mechanism: 'session cookie' };
	}
	if ( ! config.username || ! config.password ) {
		return { cookies: [], mechanism: 'none', error: 'no WP_GATE_USERNAME/WP_GATE_PASSWORD and no session cookie' };
	}
	return loginSession( config, base, origin, fetchImpl, timeoutMs );
}

async function loginSession(
	config: GateConfig,
	base: string,
	origin: URL,
	fetchImpl: typeof fetch,
	timeoutMs: number
): Promise< AcquiredSession > {
	const jar = new Map< string, string >();
	const common = { 'user-agent': DEFAULT_USER_AGENT } as Record< string, string >;

	// 1. GET the login form so `wordpress_test_cookie` exists — WordPress
	//    rejects a POST without it.
	try {
		const form = await fetchImpl( config.loginUrl, {
			redirect: 'manual',
			headers: { ...common, accept: HTML_ACCEPT },
			signal: AbortSignal.timeout( timeoutMs ),
		} );
		collectCookies( form, jar );
		await form.body?.cancel();
	} catch ( error ) {
		return { cookies: [], mechanism: 'login', error: `login page unreachable (${ messageOf( error ) })` };
	}

	// 2. POST the credentials. One attempt only — a security plugin that
	//    rate-limits failures must never see a loop.
	const body = new URLSearchParams( {
		log: config.username as string,
		pwd: config.password as string,
		'wp-submit': 'Log In',
		redirect_to: `${ base }/wp-admin/`,
		testcookie: '1',
	} );
	let response: Response;
	try {
		response = await fetchImpl( config.loginUrl, {
			method: 'POST',
			redirect: 'manual',
			headers: {
				...common,
				'content-type': 'application/x-www-form-urlencoded',
				cookie: cookieHeaderOf( cookiesFor( jar, origin.hostname ) ),
			},
			body,
			signal: AbortSignal.timeout( timeoutMs ),
		} );
	} catch ( error ) {
		return { cookies: [], mechanism: 'login', error: `login POST failed (${ messageOf( error ) })` };
	}
	collectCookies( response, jar );
	await response.body?.cancel();

	const cookies = cookiesFor( jar, origin.hostname );
	if ( ! cookies.some( ( cookie ) => LOGGED_IN_COOKIE.test( cookie.name ) ) ) {
		const location = response.headers.get( 'location' ) ?? '';
		const detail = /login=(empty|failed)/.test( location )
			? 'WordPress rejected the credentials'
			: `no wordpress_logged_in cookie (HTTP ${ response.status }${ location ? ` → ${ location }` : '' })`;
		return { cookies: [], mechanism: 'login', error: detail };
	}
	return {
		cookies,
		mechanism: `login as ${ config.username }`,
	};
}

/** Keep only session cookies, mapped to Playwright's cookie shape. */
function cookiesFor( jar: Map< string, string >, hostname: string ): ForgeGateCookie[] {
	const out: ForgeGateCookie[] = [];
	for ( const [ name, value ] of jar ) {
		if ( ! SESSION_COOKIE.test( name ) ) {
			continue;
		}
		out.push( { name, value, domain: hostname, path: '/', secure: true, httpOnly: true, sameSite: 'Lax' } );
	}
	return out;
}

function collectCookies( response: Response, jar: Map< string, string > ): void {
	for ( const raw of setCookieHeaders( response.headers ) ) {
		const [ pair ] = raw.split( ';' );
		const index = pair.indexOf( '=' );
		if ( index <= 0 ) {
			continue;
		}
		jar.set( pair.slice( 0, index ).trim(), pair.slice( index + 1 ).trim() );
	}
}

function setCookieHeaders( headers: Headers ): string[] {
	const candidate = headers as unknown as { getSetCookie?: () => string[] };
	if ( typeof candidate.getSetCookie === 'function' ) {
		return candidate.getSetCookie();
	}
	const raw = headers.get( 'set-cookie' );
	if ( ! raw ) {
		return [];
	}
	// Split on commas that begin a new cookie, not on the comma inside an
	// Expires date ("Expires=Wed, 21 Oct 2026 …").
	return raw.split( /,(?=\s*[A-Za-z0-9_\-]+=)/ );
}

async function cookiesFromStorageState( path: string, hostname: string ): Promise< ForgeGateCookie[] > {
	try {
		const state = JSON.parse( await readFile( expandHome( path ), 'utf8' ) ) as {
			cookies?: Array< Record< string, unknown > >;
		};
		return ( state.cookies ?? [] )
			.filter( ( cookie ) => typeof cookie.name === 'string' && typeof cookie.value === 'string' )
			.filter( ( cookie ) => cookieMatchesHost( String( cookie.domain ?? '' ), hostname ) )
			.map( ( cookie ) => ( {
				name: String( cookie.name ),
				value: String( cookie.value ),
				domain: hostname,
				path: String( cookie.path ?? '/' ),
				secure: cookie.secure !== false,
				httpOnly: cookie.httpOnly === true,
				sameSite: normalizeSameSite( cookie.sameSite ),
				...( typeof cookie.expires === 'number' && cookie.expires > 0 ? { expires: cookie.expires } : {} ),
			} ) );
	} catch {
		return [];
	}
}

function cookieMatchesHost( domain: string, hostname: string ): boolean {
	const bare = domain.replace( /^\./, '' ).toLowerCase();
	return bare === '' || hostname.toLowerCase() === bare || hostname.toLowerCase().endsWith( `.${ bare }` );
}

function normalizeSameSite( value: unknown ): 'Strict' | 'Lax' | 'None' {
	const normalized = String( value ?? '' ).toLowerCase();
	if ( normalized === 'strict' ) {
		return 'Strict';
	}
	if ( normalized === 'none' ) {
		return 'None';
	}
	return 'Lax';
}

// ---------------------------------------------------------------------------
// Global fetch wrapper — host-scoped, redirect-safe, no-store
// ---------------------------------------------------------------------------

interface InstalledGate {
	host: string;
	original: typeof fetch;
	uninstall: () => void;
}

let installed: InstalledGate | undefined;

/**
 * Wrap `globalThis.fetch` for the lifetime of the gate. The wrapper only
 * touches requests whose `host` equals the CMS host; every other request
 * (font CDN, media host, Wayback, the built site) passes through untouched.
 *
 * Redirects are followed MANUALLY and re-checked against the host: Node's
 * fetch strips `Authorization` cross-origin but not a manually set `Cookie`,
 * so a 30x to an SSO/media host is the classic leak path.
 */
export function installGateFetch(
	host: string,
	session: { cookies: ForgeGateCookie[]; headers: Record< string, string >; query: Record< string, string > },
	fetchImpl: typeof fetch = fetch
): () => void {
	if ( installed?.host === host ) {
		return installed.uninstall;
	}
	installed?.uninstall();

	const original = fetchImpl;
	const cookieHeader = cookieHeaderOf( session.cookies );
	const noStore = process.env.WP_GATE_NO_STORE !== '0';
	const hasQuery = Object.keys( session.query ).length > 0;

	const wrapper = ( async ( input: RequestInfo | URL, init: RequestInit = {} ): Promise< Response > => {
		const rawUrl = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
		let url: URL;
		try {
			url = new URL( rawUrl );
		} catch {
			return original( input, init );
		}
		if ( url.host !== host ) {
			return original( input, init );
		}

		const method = String( init.method ?? ( typeof input === 'object' && 'method' in input ? input.method : 'GET' ) ).toUpperCase();
		const headers = new Headers(
			init.headers ?? ( typeof input === 'object' && 'headers' in input ? ( input as Request ).headers : undefined )
		);
		for ( const [ name, value ] of Object.entries( session.headers ) ) {
			if ( ! headers.has( name ) ) {
				headers.set( name, value );
			}
		}
		if ( cookieHeader && ! headers.has( 'cookie' ) ) {
			headers.set( 'cookie', cookieHeader );
		}
		if ( noStore ) {
			headers.set( 'cache-control', 'no-store' );
			headers.set( 'pragma', 'no-cache' );
		}

		let current = hasQuery && method === 'GET' ? applyQuery( url.href, session.query ) : url.href;
		let currentUrl = new URL( current );
		let response = await original( current, { ...init, headers, redirect: 'manual' } );
		let hops = 0;
		while ( REDIRECT_STATUS.has( response.status ) && hops < MAX_REDIRECTS ) {
			const location = response.headers.get( 'location' );
			if ( ! location ) {
				break;
			}
			const next = new URL( location, currentUrl );
			// Never forward the session off the CMS host.
			if ( next.host !== host ) {
				return response;
			}
			await response.body?.cancel();
			hops++;
			currentUrl = next;
			const nextMethod = response.status === 303 ? 'GET' : method;
			response = await original( next.href, {
				...init,
				method: nextMethod,
				headers,
				body: nextMethod === 'GET' ? undefined : init.body,
				redirect: 'manual',
			} );
		}
		return response;
	} ) as typeof fetch;

	// Preserve `preconnect` and friends on the wrapper (undici exports them).
	for ( const key of Object.keys( original ) as Array< keyof typeof fetch > ) {
		if ( ! ( key in wrapper ) ) {
			( wrapper as unknown as Record< string, unknown > )[ key as string ] = ( original as unknown as Record< string, unknown > )[ key as string ];
		}
	}

	globalThis.fetch = wrapper;
	installed = {
		host,
		original,
		uninstall: () => {
			if ( installed?.host === host ) {
				globalThis.fetch = original;
				installed = undefined;
			}
		},
	};
	return installed.uninstall;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function offProbe( base: string ): GateProbe {
	return {
		state: 'open',
		status: 0,
		restHealthy: false,
		visibleChars: 0,
		evidence: [ 'gate mode off — no probe was made' ],
		remediation: [],
	};
}

async function checkRest( base: string, fetchImpl: typeof fetch, timeoutMs: number ): Promise< boolean > {
	try {
		const response = await fetchImpl( `${ base }/wp-json/`, {
			redirect: 'manual',
			headers: { accept: 'application/json', 'user-agent': DEFAULT_USER_AGENT },
			signal: AbortSignal.timeout( timeoutMs ),
		} );
		await response.body?.cancel();
		return response.status === 200;
	} catch {
		return false;
	}
}

/** Parse Retry-After per RFC 9110 §10.2.3: delta-seconds or an HTTP-date. */
export function parseRetryAfter( value: string | null ): number | undefined {
	if ( ! value ) {
		return undefined;
	}
	const trimmed = value.trim();
	if ( /^\d+$/.test( trimmed ) ) {
		return Number( trimmed );
	}
	const at = Date.parse( trimmed );
	if ( Number.isNaN( at ) ) {
		return undefined;
	}
	return Math.max( 0, Math.round( ( at - Date.now() ) / 1000 ) );
}

/** Visible text length — tags, comments, scripts and styles removed. */
export function visibleTextLength( html: string ): number {
	if ( ! html ) {
		return 0;
	}
	const text = html
		.replace( /<!--[\s\S]*?-->/g, ' ' )
		.replace( /<(script|style|noscript|template)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /&[a-z#0-9]+;/gi, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
	return text.length;
}

function htmlMarker(
	html: string,
	extra: string[] | undefined
): { marker: string; fingerprint: GateFingerprint } | undefined {
	const lower = html.toLowerCase();
	const configured = ( extra ?? [] ).map( ( marker ) => ( { marker, fingerprint: 'generic-holding-page' as GateFingerprint } ) );
	for ( const entry of [ ...configured, ...REJECT_MARKERS ] ) {
		if ( lower.includes( entry.marker.toLowerCase() ) ) {
			return entry;
		}
	}
	return undefined;
}

function applyQuery( url: string, query: Record< string, string > ): string {
	if ( Object.keys( query ).length === 0 ) {
		return url;
	}
	const parsed = new URL( url );
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( ! parsed.searchParams.has( key ) ) {
			parsed.searchParams.set( key, value );
		}
	}
	return parsed.href;
}

function cookieHeaderOf( cookies: ForgeGateCookie[] ): string {
	return cookies.map( ( cookie ) => `${ cookie.name }=${ cookie.value }` ).join( '; ' );
}

function parseCookieString( raw: string, hostname: string ): ForgeGateCookie[] {
	if ( ! raw.trim() ) {
		return [];
	}
	const out: ForgeGateCookie[] = [];
	for ( const part of raw.split( ';' ) ) {
		const index = part.indexOf( '=' );
		if ( index <= 0 ) {
			continue;
		}
		const name = part.slice( 0, index ).trim();
		const value = part.slice( index + 1 ).trim();
		if ( ! name || ! value ) {
			continue;
		}
		out.push( { name, value, domain: hostname, path: '/', secure: true, httpOnly: true, sameSite: 'Lax' } );
	}
	return out;
}

function parseQuery( raw: string ): Record< string, string > {
	const out: Record< string, string > = {};
	for ( const part of raw.split( '&' ) ) {
		const index = part.indexOf( '=' );
		if ( index <= 0 ) {
			continue;
		}
		out[ decodeURIComponent( part.slice( 0, index ).trim() ) ] = decodeURIComponent( part.slice( index + 1 ).trim() );
	}
	return out;
}

function parseJsonObject( raw: string ): Record< string, string > {
	try {
		const parsed = JSON.parse( raw ) as unknown;
		if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
			return {};
		}
		const out: Record< string, string > = {};
		for ( const [ key, value ] of Object.entries( parsed as Record< string, unknown > ) ) {
			if ( typeof value === 'string' ) {
				out[ key ] = value;
			}
		}
		return out;
	} catch {
		return {};
	}
}

function expandHome( path: string ): string {
	return path.startsWith( '~/' ) || path.startsWith( '~\\' ) ? `${ homedir() }${ path.slice( 1 ) }` : path;
}

function messageOf( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

function mechanismNames( config: GateConfig, cookies: ForgeGateCookie[] ): string[] {
	const names: string[] = [];
	if ( cookies.length > 0 ) {
		names.push( 'session' );
	}
	if ( config.headers[ 'cf-access-client-id' ] ) {
		names.push( 'cloudflare-access' );
	}
	if ( config.headers.authorization ) {
		names.push( 'http-basic' );
	}
	if ( Object.keys( config.query ).length > 0 ) {
		names.push( 'bypass-query' );
	}
	if ( Object.keys( config.headers ).length > 0 && names.length === 0 ) {
		names.push( 'bypass-headers' );
	}
	return names.length > 0 ? names : [ 'anonymous' ];
}

// ---------------------------------------------------------------------------
// Remediation text — the operator's ladder (Proposal 152 §5)
// ---------------------------------------------------------------------------

function remediationFor(
	kind: GateFingerprint | 'plugin' | 'edge' | 'rate-limited' | 'unknown' | 'cache',
	retryAfter?: number
): string[] {
	switch ( kind ) {
		case 'elementor-maintenance':
			return [
				'Elementor → Tools → Maintenance Mode → set "Who can see it" to logged-in users (or the custom-roles list).',
				'Then set WP_GATE_USERNAME / WP_GATE_PASSWORD to a user with edit access.',
				'Or disable Coming Soon until the forge run completes (operator action, never the forge).',
			];
		case 'wp-maintenance-mode':
			return [
				'WP Maintenance Mode → Settings → General → add the automation user\'s role to "Roles allowed" (frontend).',
				'Then set WP_GATE_USERNAME / WP_GATE_PASSWORD.',
				'Or add a bypass slug to the plugin\'s Exclude list and set WP_GATE_QUERY (e.g. "forge_bypass=1").',
			];
		case 'seedprod':
			return [
				'SeedProd → Campaigns → set the "bypass" secret for the coming-soon page, then set WP_GATE_QUERY.',
				'Or allowlist the builder\'s egress IP, or add the automation user to the page\'s access list.',
			];
		case 'under-construction':
			return [
				'UnderConstruction → Settings → enable "bypass" and set WP_GATE_QUERY to the configured key.',
				'Or add the automation user\'s role to the plugin\'s access roles and set WP_GATE_USERNAME / WP_GATE_PASSWORD.',
			];
		case 'core-maintenance':
			return [
				'WordPress core maintenance mode (a .maintenance file) expires on its own — wait 10 minutes and re-run.',
				'Core maintenance has no login bypass, so credentials will not help.',
			];
		case 'generic-holding-page':
		case 'plugin':
			return [
				'Find the gate plugin (Elementor Maintenance Mode, WP Maintenance Mode, SeedProd, UnderConstruction…) and allowlist the automation user\'s role.',
				'Then set WP_GATE_USERNAME / WP_GATE_PASSWORD.',
				'Or use the plugin\'s bypass slug/token via WP_GATE_QUERY, or allowlist the builder\'s egress IP.',
				'Or build from staging where the gate is off.',
			];
		case 'edge':
			return [
				'Cloudflare Access: set CF_ACCESS_CLIENT_ID / CF_ACCESS_CLIENT_SECRET from a Service Auth service token.',
				'Staging basic auth (nginx/htpasswd, WP Engine, Kinsta): set WP_GATE_HTTP_AUTH="user:pass".',
				'A managed challenge (Under Attack / reCAPTCHA) cannot be solved headlessly — allowlist the builder or use a bypass.',
			];
		case 'rate-limited':
			return [
				`The host or WAF is throttling (${ retryAfter === undefined ? 'no Retry-After' : `Retry-After ${ retryAfter }s` }). Wait, then retry.`,
				'If it persists, allowlist the builder IP or route through WP_FETCH_PROXY.',
			];
		case 'cache':
			return [
				'Purge the edge cache (or bypass it for this path) so an authenticated request does not read an anonymous copy.',
				'Then re-run the forge; the gate sets Cache-Control: no-store on every gated request.',
			];
		default:
			return [
				'Re-run `forge gate <profile>` with WP_GATE_* credentials, or inspect the page manually.',
				'If the site is behind a bot challenge, allowlist the builder or build from staging.',
			];
	}
}
