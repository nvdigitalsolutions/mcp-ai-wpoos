// ---------------------------------------------------------------------------
// forms-direct.ts — the DIRECT delivery endpoint emitter.
//
// `forms.delivery: 'direct'` replaces the WordPress bridge proxy with a route
// that sends the submission itself through the provider API (lib/mail.ts).
// Bridge mode stays the default: WordPress already owns the form handler, and
// its SMTP setting keeps every credential off the site.
//
// The endpoint is defensive by construction (2026 form-abuse guidance):
//   body-size cap → parse → honeypot → time trap → Turnstile verify →
//   per-IP throttle → send.
// A bot that trips the honeypot or the time trap gets `{ ok: true }` — never a
// rejection it can learn from.
//
// Turnstile (2026-11-15): the widget is chrome, `siteverify` is the boundary.
// Verification runs BEFORE the throttle on purpose — a token is free to mint,
// so letting a rejected one consume a real address's slots would let one bot
// lock the form for every visitor behind the same NAT.
// ---------------------------------------------------------------------------

/**
 * Cloudflare Turnstile settings, from `profile.forms.turnstile`.
 *
 * Nothing here is secret: `siteKey` is rendered into the page and the secret
 * lives in `TURNSTILE_SECRET` on the host.
 */
export interface DirectFormsTurnstile {
	/** PUBLIC site key. Empty (or absent) disables verification entirely. */
	siteKey?: string;
	/** The action the token is minted for; siteverify must echo it back. */
	action?: string;
	/** The hostname siteverify must echo back (empty compares the request). */
	hostname?: string;
	/**
	 * What to do when siteverify cannot answer (timeout / 5xx / DNS):
	 * `allow` (default) keeps the enquiry, `block` refuses it.
	 *
	 * A MISSING token is always accepted-unverified with a loud log: a widget
	 * that never rendered is a CSP or script fault, and 400ing on it is the
	 * silent-drop failure class this endpoint exists to avoid.
	 */
	onProviderError?: 'allow' | 'block';
}

/** A single-quoted JS literal — profile values are data, never code. */
function literal( value: string ): string {
	return `'${ value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}

/**
 * `src/pages/api/forms/submit.ts`, rendered for direct delivery.
 *
 * The endpoint imports the vendored `src/forge/mail.ts` and reads its
 * credentials from the environment at request time, so a scaffold never needs
 * the provider key to succeed.
 */
export function renderDirectFormsEndpointTs( turnstile: DirectFormsTurnstile = {} ): string {
	const siteKey = ( turnstile.siteKey ?? '' ).trim();
	const action = ( turnstile.action ?? '' ).trim();
	const hostname = ( turnstile.hostname ?? '' ).trim();
	const onProviderError = turnstile.onProviderError === 'block' ? 'block' : 'allow';
	const verify = siteKey !== '';

	// The Turnstile token is a form field the widget injects, so it travels in
	// the same bag as the visitor's values — reserve it either way.
	const internal = verify
		? "const INTERNAL = new Set( [ 'honeypot', 'timestamp', '_redirect', 'form', 'post_id', 'cf-turnstile-response' ] );"
		: "const INTERNAL = new Set( [ 'honeypot', 'timestamp', '_redirect', 'form', 'post_id' ] );";

	const turnstileConsts = verify
		? [
				'',
				'// ---------------------------------------------------------------------------',
				'// Cloudflare Turnstile — the server-side siteverify below is the boundary;',
				'// the widget is decoration a script happily skips.',
				'// ---------------------------------------------------------------------------',
				'/** PUBLIC site key — the secret is TURNSTILE_SECRET on the host. */',
				`const TURNSTILE_SITE_KEY = ${ literal( siteKey ) };`,
				`const TURNSTILE_ACTION = ${ literal( action ) };`,
				'/** The hostname siteverify must echo back (empty compares the request). */',
				`const TURNSTILE_HOSTNAME = ${ literal( hostname ) };`,
				'/** `allow` keeps the enquiry when siteverify cannot answer; `block` refuses. */',
				`const TURNSTILE_ON_ERROR: 'allow' | 'block' = ${ literal( onProviderError ) };`,
				"const TURNSTILE_TOKEN_FIELD = 'cf-turnstile-response';",
				'/** siteverify gets this long before TURNSTILE_ON_ERROR decides. */',
				'const TURNSTILE_TIMEOUT_MS = 5000;',
				'',
				'interface TurnstileVerdict {',
				'\tok: boolean;',
				'\tdetail?: string;',
				'}',
				'',
				'/**',
				' * Validate the token with Cloudflare.',
				' *',
				' * Tokens are single-use and expire after 300 s, so a replay reports',
				' * `timeout-or-duplicate` — a rejection, not a transport error. The',
				' * hostname and action are checked too: a token minted for another site',
				' * or widget does not vouch for this form.',
				' */',
				'async function verifyTurnstile( token: string, ip: string, request: Request ): Promise< TurnstileVerdict > {',
				"\tconst secret = ( runtimeEnv().TURNSTILE_SECRET ?? '' ).trim();",
				"\tif ( secret === '' ) {",
				"\t\tconsole.warn( '[forms] TURNSTILE_SECRET is not set - accepting unverified' );",
				"\t\treturn { ok: true, detail: 'no-secret' };",
				'\t}',
				'\tconst body = new URLSearchParams( { secret, response: token } );',
				'\tif ( isIpAddress( ip ) ) {',
				"\t\tbody.set( 'remoteip', ip );",
				'\t}',
				"\tlet payload: { success?: boolean; hostname?: string; action?: string; 'error-codes'?: string[] };",
				'\ttry {',
				"\t\tconst response = await fetch( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', {",
				"\t\t\tmethod: 'POST',",
				'\t\t\tbody,',
				'\t\t\tsignal: AbortSignal.timeout( TURNSTILE_TIMEOUT_MS ),',
				'\t\t} );',
				'\t\tpayload = ( await response.json() ) as typeof payload;',
				'\t} catch ( error ) {',
				"\t\t// A transport failure is OUR fault, not the visitor's.",
				'\t\tconsole.warn( `[forms] siteverify unreachable: ${ String( error ) }` );',
				"\t\treturn { ok: TURNSTILE_ON_ERROR === 'allow', detail: 'unreachable' };",
				'\t}',
				'\tif ( payload.success !== true ) {',
				"\t\treturn { ok: false, detail: ( payload[ 'error-codes' ] ?? [] ).join( ',' ) || 'failed' };",
				'\t}',
				'\tconst expected = TURNSTILE_HOSTNAME || new URL( request.url ).hostname;',
				'\tif ( payload.hostname && expected && payload.hostname !== expected ) {',
				'\t\treturn { ok: false, detail: `hostname:${ payload.hostname }` };',
				'\t}',
				"\tif ( TURNSTILE_ACTION !== '' && payload.action && payload.action !== TURNSTILE_ACTION ) {",
				'\t\treturn { ok: false, detail: `action:${ payload.action }` };',
				'\t}',
				'\treturn { ok: true };',
				'}',
			]
		: [];

	const turnstileVerify = verify
		? [
				'\t// Turnstile BEFORE the throttle: a token is free to mint, so a rejected',
				'\t// one must not consume a real address slots — that would let a single',
				'\t// bot lock the form for everyone behind the same NAT.',
				"\tif ( TURNSTILE_SITE_KEY !== '' ) {",
				"\t\tconst token = ( fields[ TURNSTILE_TOKEN_FIELD ] ?? '' ).trim();",
				"\t\tif ( token === '' ) {",
				'\t\t\t// A missing token is a broken widget or CSP far more often than a',
				'\t\t\t// bot, and dropping the enquiry silently is the failure this',
				'\t\t\t// endpoint exists to prevent.',
				"\t\t\tconsole.warn( '[forms] Turnstile token missing - accepted unverified' );",
				'\t\t} else {',
				'\t\t\tconst verdict = await verifyTurnstile( token, ip, request );',
				'\t\t\tif ( ! verdict.ok ) {',
				'\t\t\t\tconsole.warn( `[forms] Turnstile rejected: ${ verdict.detail ?? \'unknown\' }` );',
				"\t\t\t\tconst unavailable = verdict.detail === 'unreachable';",
				'\t\t\t\treturn Response.json(',
				'\t\t\t\t\t{',
				'\t\t\t\t\t\tok: false,',
				'\t\t\t\t\t\tmessage: unavailable',
				'\t\t\t\t\t\t\t? \'Verification unavailable, please retry\'',
				'\t\t\t\t\t\t\t: \'Verification failed, please retry\',',
				'\t\t\t\t\t},',
				'\t\t\t\t\t{ status: unavailable ? 503 : 400 }',
				'\t\t\t\t);',
				'\t\t\t}',
				'\t\t}',
				'\t}',
			]
		: [];

	return [
		'// Generated by NV oOS Astro Forge — DIRECT form delivery (forms.delivery).',
		'//',
		'// Sends the submission through the provider API instead of proxying to the',
		'// WordPress bridge. Credentials come from the environment at request time:',
		'//   MAIL_PROVIDER (brevo|mailjet), MAIL_FROM, MAIL_FROM_NAME, MAIL_TO,',
		'//   MAIL_SUBJECT, BREVO_API_KEY, MAILJET_API_KEY, MAILJET_SECRET_KEY',
		...( verify ? [ '//   TURNSTILE_SECRET (Cloudflare siteverify)' ] : [] ),
		"import type { APIRoute } from 'astro';",
		"import { createHash } from 'node:crypto';",
		"import { readMailConfig, runtimeEnv, sendMail } from '../../../forge/mail.ts';",
		'',
		'export const prerender = false;',
		'',
		'/** Bodies larger than this are refused before parsing. */',
		'const MAX_BYTES = 64 * 1024;',
		'/** A human needs at least this long to fill a form (seconds). */',
		'const MIN_SECONDS = 2;',
		'const RATE_LIMIT = 5;',
		'const RATE_WINDOW_MS = 10 * 60 * 1000;',
		'/** Fields the form itself owns — never echoed into the email. */',
		internal,
		...turnstileConsts,
		'',
		'// One managed site runs one Node process, so an in-memory bucket is enough',
		'// here; a CDN/WAF rate rule is the real edge.',
		'const hits = new Map< string, number[] >();',
		'/** How many addresses the throttle remembers before it prunes. */',
		'const MAX_TRACKED_IPS = 5000;',
		'',
		'/** A bare IPv4/IPv6 literal — X-Forwarded-For is attacker-adjacent text. */',
		'function isIpAddress( value: string ): boolean {',
		'\t// Colons first: an IPv4-mapped address (`::ffff:127.0.0.1`) also splits into',
		'\t// four dot-groups, so a dot-count test alone would misread it.',
		"\tif ( value.includes( ':' ) ) {",
		'\t\treturn /^[0-9a-f:.]+$/i.test( value );',
		'\t}',
		"\tconst parts = value.split( '.' );",
		'\treturn (',
		'\t\tparts.length === 4 &&',
		"\t\tparts.every( ( part ) => /^[0-9]{1,3}$/.test( part ) && Number( part ) <= 255 )",
		'\t);',
		'}',
		'',
		'/** Loopback / RFC1918 / link-local — i.e. something is proxying for us. */',
		'function isLocalPeer( address: string ): boolean {',
		'\tif ( ! isIpAddress( address ) ) {',
		'\t\treturn false;',
		'\t}',
		"\tconst mapped = address.startsWith( '::ffff:' ) ? address.slice( 7 ) : address;",
		"\tif ( mapped.includes( ':' ) ) {",
		"\t\treturn mapped === '::1' || mapped === '::' || /^(?:fc|fd|fe[89ab])/i.test( mapped );",
		'\t}',
		"\tconst [ a, b ] = mapped.split( '.' ).map( Number );",
		'\treturn (',
		'\t\ta === 10 ||',
		'\t\ta === 127 ||',
		'\t\t( a === 192 && b === 168 ) ||',
		'\t\t( a === 172 && b >= 16 && b <= 31 ) ||',
		'\t\t( a === 169 && b === 254 )',
		'\t);',
		'}',
		'',
		'/**',
		" * The visitor's address, for the per-IP throttle.",
		' *',
		' * Behind a reverse proxy the socket peer is the PROXY, so keying the throttle',
		' * on `clientAddress` alone silently becomes one site-wide bucket. The proxy',
		' * APPENDS the address it saw to X-Forwarded-For, so the RIGHTMOST hop is the',
		' * value a client cannot forge (a visitor-supplied header is prepended, never',
		' * replaced). Trust it only when the peer is loopback/private — proof a proxy',
		' * is in front; otherwise the peer IS the visitor.',
		' */',
		'function clientIp( request: Request, clientAddress: string | undefined ): string {',
		"\tconst peer = ( clientAddress ?? '' ).trim();",
		"\tif ( peer !== '' && ! isLocalPeer( peer ) ) {",
		'\t\treturn peer;',
		'\t}',
		"\tconst hops = ( request.headers.get( 'x-forwarded-for' ) ?? '' )",
		"\t\t.split( ',' )",
		'\t\t.map( ( hop ) => hop.trim() )',
		"\t\t.filter( ( hop ) => hop !== '' );",
		'\tconst rightmost = hops.length > 0 ? hops[ hops.length - 1 ] : \'\';',
		"\treturn isIpAddress( rightmost ) ? rightmost : peer || 'unknown';",
		'}',
		'',
		'function throttled( ip: string, now: number ): boolean {',
		'\tconst recent = ( hits.get( ip ) ?? [] ).filter( ( at ) => now - at < RATE_WINDOW_MS );',
		'\trecent.push( now );',
		'\t// Keep the newest RATE_LIMIT + 1 stamps: still over the limit, but bounded.',
		'\tif ( recent.length > RATE_LIMIT + 1 ) {',
		'\t\trecent.splice( 0, recent.length - ( RATE_LIMIT + 1 ) );',
		'\t}',
		'\thits.set( ip, recent );',
		'\t// Per-address keys make the map unbounded, so prune fully-expired buckets.',
		'\tif ( hits.size > MAX_TRACKED_IPS ) {',
		'\t\tfor ( const [ key, stamps ] of hits ) {',
		'\t\t\tif ( stamps.every( ( at ) => now - at >= RATE_WINDOW_MS ) ) {',
		'\t\t\t\thits.delete( key );',
		'\t\t\t}',
		'\t\t}',
		'\t}',
		'\treturn recent.length > RATE_LIMIT;',
		'}',
		'',
		'/** Normalise JSON or urlencoded payloads to string pairs. */',
		'async function readFields( request: Request ): Promise< Record< string, string > | undefined > {',
		'\tconst raw = await request.text();',
		'\tif ( raw.length > MAX_BYTES ) {',
		'\t\treturn undefined;',
		'\t}',
		"\tconst type = request.headers.get( 'content-type' ) ?? '';",
		'\ttry {',
		"\t\tif ( type.includes( 'application/json' ) ) {",
		'\t\t\tconst parsed = JSON.parse( raw ) as Record< string, unknown >;',
		'\t\t\tconst out: Record< string, string > = {};',
		'\t\t\t// The shared client posts `{ form, fields: { ... }, honeypot,',
		'\t\t\t// timestamp }` — the same envelope the WordPress bridge reads. Flatten',
		'\t\t\t// the nested bag FIRST so the visitor values (and the Turnstile token)',
		'\t\t\t// survive; the top-level carriers then win on a name collision.',
		'\t\t\tconst nested = parsed.fields;',
		"\t\t\tif ( nested && typeof nested === 'object' && ! Array.isArray( nested ) ) {",
		'\t\t\t\tfor ( const [ key, value ] of Object.entries( nested as Record< string, unknown > ) ) {',
		"\t\t\t\t\tif ( typeof value === 'string' || typeof value === 'number' ) {",
		'\t\t\t\t\t\tout[ key ] = String( value );',
		'\t\t\t\t\t}',
		'\t\t\t\t}',
		'\t\t\t}',
		'\t\t\tfor ( const [ key, value ] of Object.entries( parsed ) ) {',
		"\t\t\t\tif ( key === 'fields' ) {",
		'\t\t\t\t\tcontinue;',
		'\t\t\t\t}',
		"\t\t\t\tif ( typeof value === 'string' || typeof value === 'number' ) {",
		'\t\t\t\t\tout[ key ] = String( value );',
		'\t\t\t\t}',
		'\t\t\t}',
		'\t\t\treturn out;',
		'\t\t}',
		"\t\tconst form = await new Response( raw, { headers: { 'content-type': type } } ).formData();",
		'\t\tconst out: Record< string, string > = {};',
		'\t\tfor ( const [ key, value ] of form.entries() ) {',
		"\t\t\tif ( typeof value === 'string' ) {",
		'\t\t\t\tout[ key ] = value;',
		'\t\t\t}',
		'\t\t}',
		'\t\treturn out;',
		'\t} catch {',
		'\t\treturn undefined;',
		'\t}',
		'}',
		'',
		'export const POST: APIRoute = async ( { request, clientAddress } ) => {',
		'\tconst fields = await readFields( request );',
		"\tif ( ! fields ) {",
		"\t\treturn new Response( 'Bad request', { status: 400 } );",
		'\t}',
		'',
		'\t// A filled honeypot or an impossibly fast submit is a bot. Accept',
		'\t// silently: a rejection teaches the sender what to change.',
		"\tif ( ( fields.honeypot ?? '' ).trim() !== '' ) {",
		'\t\treturn Response.json( { ok: true } );',
		'\t}',
		'\tconst stamp = Number( fields.timestamp ?? 0 );',
		'\tconst elapsed = ( Date.now() - stamp ) / 1000;',
		'\t// A future stamp is clock skew, not a bot. Requiring elapsed >= 0 keeps a',
		'\t// visitor whose clock runs ahead from being silently accepted and dropped',
		'\t// (measured 2026-11-15: a client 60 s ahead lost every enquiry).',
		'\tif ( stamp > 0 && elapsed >= 0 && elapsed < MIN_SECONDS ) {',
		'\t\treturn Response.json( { ok: true } );',
		'\t}',
		'',
		'\tconst ip = clientIp( request, clientAddress );',
		...turnstileVerify,
		'\tif ( throttled( ip, Date.now() ) ) {',
		"\t\treturn new Response( 'Too many requests', { status: 429 } );",
		'\t}',
		'',
		'\tconst config = readMailConfig( runtimeEnv() );',
		'\tconst rows = Object.entries( fields )',
		'\t\t.filter( ( [ key ] ) => ! INTERNAL.has( key ) )',
		"\t\t.map( ( [ key, value ] ) => `${ key }: ${ value.slice( 0, 2000 ) }` );",
		'\tif ( rows.length === 0 ) {',
		"\t\treturn new Response( 'Empty submission', { status: 400 } );",
		'\t}',
		'',
		"\tconst replyTo = ( fields.email ?? fields[ 'e-mail' ] ?? '' ).trim();",
		'\t// Idempotency: the same payload inside one bucket is one email, so a',
		'\t// double-click or a retried request cannot deliver twice.',
		'\tconst NL = String.fromCharCode( 10 );',
		"\tconst digest = createHash( 'sha256' ).update( rows.join( NL ) ).digest( 'hex' ).slice( 0, 32 );",
		"\tconst result = await sendMail( config, {",
		'\t\ttext: `${ rows.join( NL ) }${ NL }${ NL }--${ NL }Sent from a form on the website.`,',
		"\t\treplyTo: replyTo.includes( '@' ) ? replyTo : undefined,",
		'\t\tidempotencyKey: digest,',
		'\t} );',
		'',
		'\tif ( ! result.ok ) {',
		"\t\tconsole.error( `[forms] delivery failed (${ result.status }): ${ result.detail ?? '' }` );",
		"\t\treturn new Response( 'Delivery failed', { status: 502 } );",
		'\t}',
		'\treturn Response.json( { ok: true } );',
		'};',
		'',
	].join( '\n' );
}
