/**
 * SSRF-safe URL validation.
 *
 * Implements the practical controls from the OWASP "SSRF Prevention in
 * Node.js" guidance for a worker that must legitimately fetch arbitrary
 * public URLs (screenshots, image-to-image, workflow callbacks):
 *
 *   1. Protocol allowlist (http/https only).
 *   2. Reject IP literals inside private/reserved ranges (IPv4 + IPv6).
 *   3. Normalise obfuscated forms (decimal, hex and octal integers).
 *   4. Resolve hostnames and validate every resolved address before use
 *      (mitigates DNS rebinding / nip.io-style tricks).
 *   5. Callers re-validate on redirects.
 *
 * Set SSRF_ALLOW_PRIVATE=1 to disable the blocklist for local development.
 * Never set it on a publicly reachable deployment.
 */

import dns from 'dns';

const allowPrivate = () => '1' === process.env.SSRF_ALLOW_PRIVATE;

// Private / reserved IPv4 ranges as [start, end] 32-bit unsigned integers.
const V4_RESERVED = [
	[ 0x00000000, 0x00ffffff ], // 0.0.0.0/8
	[ 0x0a000000, 0x0affffff ], // 10.0.0.0/8
	[ 0x64400000, 0x647fffff ], // 100.64.0.0/10 (CGNAT)
	[ 0x7f000000, 0x7fffffff ], // 127.0.0.0/8
	[ 0xa9fe0000, 0xa9feffff ], // 169.254.0.0/16 (link-local)
	[ 0xac100000, 0xac1fffff ], // 172.16.0.0/12
	[ 0xc0000000, 0xc00000ff ], // 192.0.0.0/24
	[ 0xc0000200, 0xc00002ff ], // 192.0.2.0/24 (TEST-NET-1)
	[ 0xc0a80000, 0xc0a8ffff ], // 192.168.0.0/16
	[ 0xc6120000, 0xc613ffff ], // 198.18.0.0/15 (benchmarking)
	[ 0xc6336400, 0xc63364ff ], // 198.51.100.0/24 (TEST-NET-2)
	[ 0xcb007100, 0xcb0071ff ], // 203.0.113.0/24 (TEST-NET-3)
	[ 0xe0000000, 0xffffffff ], // 224.0.0.0/4 multicast + reserved
];

/**
 * Convert a dotted-quad IPv4 string to a 32-bit unsigned integer.
 *
 * @param {string} ip IPv4 address.
 * @return {number|null} Integer form, or null when malformed.
 */
function ipv4ToInt( ip ) {
	const parts = ip.split( '.' );
	if ( 4 !== parts.length ) {
		return null;
	}
	let out = 0;
	for ( const part of parts ) {
		if ( ! /^\d{1,3}$/.test( part ) ) {
			return null;
		}
		const n = parseInt( part, 10 );
		if ( n < 0 || n > 255 ) {
			return null;
		}
		out = out * 256 + n;
	}
	return out;
}

/**
 * Check whether an IPv4 address sits inside a private/reserved range.
 *
 * @param {string} ip IPv4 address.
 * @return {boolean} True when not publicly routable.
 */
export function isPrivateIPv4( ip ) {
	const n = ipv4ToInt( ip );
	if ( null === n ) {
		return false;
	}
	return V4_RESERVED.some( ( [ start, end ] ) => n >= start && n <= end );
}

/**
 * Check whether an IPv6 address is loopback, unique-local, link-local,
 * multicast, unspecified, or a mapped private IPv4 address.
 *
 * @param {string} ip IPv6 address.
 * @return {boolean} True when not publicly routable.
 */
export function isPrivateIPv6( ip ) {
	const lower = ip.toLowerCase();
	if ( '::' === lower || '::1' === lower ) {
		return true;
	}
	if ( /^f[cd]/.test( lower ) ) {
		return true; // fc00::/7 unique local
	}
	if ( /^fe[89ab]/.test( lower ) ) {
		return true; // fe80::/10 link-local
	}
	if ( /^ff/.test( lower ) ) {
		return true; // multicast
	}
	const mapped = lower.match( /^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/ );
	if ( mapped ) {
		return isPrivateIPv4( mapped[ 1 ] );
	}
	// Hex form of IPv4-mapped addresses (URL parsers normalise the dotted
	// form to this, e.g. ::ffff:127.0.0.1 -> ::ffff:7f00:1).
	const mappedHex = lower.match( /^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/ );
	if ( mappedHex ) {
		const n = ( parseInt( mappedHex[ 1 ], 16 ) << 16 ) | parseInt( mappedHex[ 2 ], 16 );
		return V4_RESERVED.some( ( [ start, end ] ) => n >= start && n <= end );
	}
	return false;
}

/**
 * Check a raw IP address (any family) for private/reserved status.
 *
 * @param {string} address IP address.
 * @return {boolean} True when not publicly routable.
 */
export function isPrivateAddress( address ) {
	if ( address.includes( ':' ) ) {
		return isPrivateIPv6( address );
	}
	return isPrivateIPv4( address );
}

/**
 * Normalise integer-form IPv4 obfuscations (decimal, hex, octal) into
 * dotted-quad form, or return the original string.
 *
 * @param {string} hostname Hostname value.
 * @return {string} Normalised hostname.
 */
function normalizeObfuscatedIPv4( hostname ) {
	if ( /^0x[0-9a-f]+$/i.test( hostname ) ) {
		const n = parseInt( hostname, 16 );
		return intToIPv4( n );
	}
	if ( /^\d+$/.test( hostname ) ) {
		const n = parseInt( hostname, 10 );
		if ( n <= 0xffffffff ) {
			return intToIPv4( n );
		}
	}
	if ( /^0[0-7]+$/.test( hostname ) ) {
		const n = parseInt( hostname, 8 );
		return intToIPv4( n );
	}
	return hostname;
}

/**
 * Convert a 32-bit unsigned integer to dotted-quad form.
 *
 * @param {number} n Integer.
 * @return {string} Dotted-quad IPv4 string.
 */
function intToIPv4( n ) {
	return [ n >>> 24, ( n >>> 16 ) & 0xff, ( n >>> 8 ) & 0xff, n & 0xff ].join( '.' );
}

/**
 * Check whether a hostname denotes a non-public destination, including
 * obfuscated integer forms of private IPv4 addresses.
 *
 * @param {string} hostname Hostname (without brackets).
 * @return {boolean} True when the hostname is private/reserved.
 */
export function isPrivateHostname( hostname ) {
	const normalized = normalizeObfuscatedIPv4( hostname.toLowerCase() );
	if ( normalized.includes( ':' ) ) {
		return isPrivateIPv6( normalized );
	}
	if ( /^\d{1,3}(\.\d{1,3}){3}$/.test( normalized ) ) {
		return isPrivateIPv4( normalized );
	}
	return false;
}

/**
 * Check whether a hostname is an IP literal (no DNS resolution needed).
 *
 * @param {string} hostname Hostname value.
 * @return {boolean} True for IPv4/IPv6 literals.
 */
export function isIpLiteral( hostname ) {
	return (
		/^\d{1,3}(\.\d{1,3}){3}$/.test( hostname ) ||
		hostname.includes( ':' )
	);
}

/**
 * Synchronous URL validation: protocol allowlist + hostname blocklist.
 * Use resolvePublicUrl() when the URL will actually be fetched, so DNS
 * results are validated too.
 *
 * @param {string} raw    Raw URL input.
 * @param {Object} [opts] Options.
 * @return {URL} Parsed URL.
 * @throws {Error} With status 400 on invalid/blocked URLs.
 */
export function validatePublicUrl( raw, opts = {} ) {
	let url;
	try {
		url = new URL( String( raw ) );
	} catch {
		throw Object.assign( new Error( 'Invalid URL' ), { status: 400 } );
	}

	if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
		throw Object.assign( new Error( `Protocol not allowed: ${ url.protocol }` ), { status: 400 } );
	}

	const hostname = url.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();
	if ( ! opts.allowPrivate && ! allowPrivate() && isPrivateHostname( hostname ) ) {
		throw Object.assign( new Error( 'URL host is not publicly routable' ), { status: 400 } );
	}

	return url;
}

/**
 * Validate a URL and resolve its hostname, rejecting any destination whose
 * DNS records point at private/reserved addresses (DNS-rebinding control).
 *
 * @param {string} raw    Raw URL input.
 * @param {Object} [opts] Options ({ allowPrivate }).
 * @return {Promise<URL>} Parsed URL.
 */
export async function resolvePublicUrl( raw, opts = {} ) {
	const url = validatePublicUrl( raw, opts );
	const hostname = url.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();

	if ( ! opts.allowPrivate && ! allowPrivate() && ! isIpLiteral( hostname ) ) {
		const records = await dns.promises.lookup( hostname, { all: true, verbatim: true } );
		if ( ! records.length ) {
			throw Object.assign( new Error( 'URL host does not resolve' ), { status: 400 } );
		}
		for ( const record of records ) {
			if ( isPrivateAddress( record.address ) ) {
				throw Object.assign( new Error( 'URL host resolves to a private address' ), { status: 400 } );
			}
		}
	}

	return url;
}
