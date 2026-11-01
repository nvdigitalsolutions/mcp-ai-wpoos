// ---------------------------------------------------------------------------
// ssrf.ts — the forge URL guard (Proposal 096 §2.4 / Phase 4 MCP surface).
//
// Every fetch-capable surface (site_analyze, site_add, rebuild_trigger)
// passes its URL through here FIRST. The guard rejects anything that is not
// a plain http(s) hostname URL, blocks IP-literal hosts outright (private,
// reserved, loopback, link-local, metadata ranges — DNS-rebinding-safe by
// refusing literal targets), and strips userinfo smuggling. Policy on top:
// a URL is fetchable when its host matches a registered site (the
// operator allowlist) OR the NVOOS_FORGE_OPEN_ANALYZE=1 opt-in is set —
// both only after the literal-range rejection above.
// ---------------------------------------------------------------------------

export class SsrfError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'SsrfError';
	}
}

/** Normalize a hostname for comparison (lowercase, no brackets/port). */
export function hostnameOf( url: string ): string {
	const parsed = new URL( url );
	return parsed.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();
}

function isIpv4( host: string ): boolean {
	return /^\d{1,3}(\.\d{1,3}){3}$/.test( host );
}

function isPrivateIpv4( host: string ): boolean {
	const parts = host.split( '.' ).map( ( part ) => Number( part ) );
	if ( parts.some( ( part ) => part > 255 ) ) {
		return true; // Unparseable octets — refuse.
	}
	const [ a, b ] = parts;
	return (
		a === 0 ||
		a === 10 ||
		a === 127 ||
		( a === 100 && b >= 64 && b <= 127 ) || // 100.64/10 CGN
		( a === 169 && b === 254 ) || // link-local
		( a === 172 && b >= 16 && b <= 31 ) ||
		( a === 192 && b === 0 ) || // 192.0.0/24 IETF
		( a === 192 && b === 168 ) ||
		( a === 198 && ( b === 18 || b === 19 ) ) || // benchmarking
		a >= 224 // multicast + reserved + broadcast
	);
}

function isPrivateIpv6( host: string ): boolean {
	const lower = host.toLowerCase();
	if ( lower === '::' || lower === '::1' ) {
		return true;
	}
	if ( /^(fc|fd)/.test( lower ) ) {
		return true; // fc00::/7 ULA
	}
	if ( /^fe[89ab]/.test( lower ) ) {
		return true; // fe80::/10 link-local
	}
	if ( lower.startsWith( '::ffff:' ) ) {
		const v4 = lower.slice( 7 );
		return isIpv4( v4 ) && isPrivateIpv4( v4 ); // IPv4-mapped
	}
	return false;
}

/**
 * Reject anything that is not a safe plain http(s) URL: bad protocol,
 * credentials in the URL, IP-literal hosts in private/reserved ranges.
 * `allowLoopback` is the documented CLI-only opt-in for local dev sites
 * (NVOOS_FORGE_ALLOW_LOOPBACK=1) — it admits ONLY the loopback ranges
 * (127.0.0.0/8, ::1); every other private/reserved range stays refused.
 * The MCP surface never sets it.
 */
export function assertSafeHttpUrl( url: string, options: { allowLoopback?: boolean } = {} ): URL {
	let parsed: URL;
	try {
		parsed = new URL( url );
	} catch {
		throw new SsrfError( `Not a valid URL: ${ url }` );
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		throw new SsrfError( `Only http(s) URLs are allowed (got ${ parsed.protocol })` );
	}
	if ( parsed.username || parsed.password ) {
		throw new SsrfError( 'URLs with embedded credentials are not allowed.' );
	}
	const host = parsed.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();
	if ( options.allowLoopback && isLoopback( host ) ) {
		return parsed;
	}
	if ( isIpv4( host ) && isPrivateIpv4( host ) ) {
		throw new SsrfError( `Refusing IP-literal host ${ host } (private/reserved range).` );
	}
	if ( host.includes( ':' ) && isPrivateIpv6( host ) ) {
		throw new SsrfError( `Refusing IPv6-literal host ${ host } (loopback/ULA/link-local).` );
	}
	return parsed;
}

/** Loopback-only ranges — the narrow NVOOS_FORGE_ALLOW_LOOPBACK exemption. */
function isLoopback( host: string ): boolean {
	if ( isIpv4( host ) ) {
		return host.split( '.' )[ 0 ] === '127';
	}
	return host === '::1' || host === '[::1]';
}

/**
 * The fetch policy: registry hosts are the operator allowlist; anything
 * else needs the explicit NVOOS_FORGE_OPEN_ANALYZE=1 opt-in. Both paths
 * already passed the literal-range rejection in assertSafeHttpUrl.
 */
export function isFetchAllowed( url: string, registryUrls: string[], openAnalyze: boolean ): boolean {
	const host = hostnameOf( url );
	const registered = registryUrls.some( ( entry ) => {
		try {
			return hostnameOf( entry ) === host;
		} catch {
			return false;
		}
	} );
	if ( registered ) {
		return true;
	}
	return openAnalyze;
}
