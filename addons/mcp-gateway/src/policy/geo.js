/**
 * Geo country resolution (Phase 2 of Proposal 094).
 *
 * The gateway never runs a GeoIP database. Country comes from the edge:
 * `CF-IPCountry` (Cloudflare) is trusted only when the gateway is
 * explicitly configured to sit behind that proxy (`TRUST_PROXY=1` — the
 * same gate the IP trust uses); otherwise a static `GEO_MAP` JSON maps
 * public key ids to ISO 3166-1 alpha-2 countries (per-key override). No IP
 * address is ever stored or logged by this module.
 */

/**
 * Parse the GEO_MAP env value (JSON object keyId → country code).
 *
 * @param {string}   raw    Raw env value.
 * @param {string[]} errors Shared error collector.
 * @return {Map<string, string>|null} Map, or null when unset/invalid.
 */
export function parseGeoMap( raw, errors = [] ) {
	if ( ! raw || ! String( raw ).trim() ) {
		return null;
	}
	try {
		const parsed = JSON.parse( String( raw ) );
		const map = new Map();
		for ( const [ keyId, country ] of Object.entries( parsed ) ) {
			if ( 'string' === typeof country && /^[A-Za-z]{2}$/.test( country ) ) {
				map.set( keyId, country.toUpperCase() );
			}
		}
		return map.size ? map : null;
	} catch ( err ) {
		errors.push( `GEO_MAP is invalid JSON: ${ err.message }` );
		return null;
	}
}

/**
 * Resolve the request's country code.
 *
 * @param {import('express').Request} req Request.
 * @param {object} cfg Gateway config (`trustProxy`, `geoMap`).
 * @return {string|null} ISO country code, or null when unknown.
 */
export function resolveCountry( req, cfg ) {
	if ( cfg.trustProxy && req.get( 'cf-ipcountry' ) ) {
		const code = String( req.get( 'cf-ipcountry' ) ).trim().toUpperCase();
		return /^[A-Z]{2}$/.test( code ) ? code : null;
	}
	if ( cfg.geoMap && req.gatewayKeyId ) {
		return cfg.geoMap.get( req.gatewayKeyId ) || null;
	}
	return null;
}
