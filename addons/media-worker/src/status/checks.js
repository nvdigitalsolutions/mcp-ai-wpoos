/**
 * Synthetic external checks for publicly reachable sites.
 *
 * Every target URL passes the shared SSRF guard (utils/safe-url.js) before
 * any network activity — the same rule that gates crawling. Private or
 * loopback targets are rejected and logged as synthetic_skipped, never
 * probed.
 */

import tls from 'tls';
import axios from 'axios';
import { resolvePublicUrl } from '../utils/safe-url.js';

/**
 * HTTP(S) reachability + latency check.
 *
 * @param {string} url        Target URL.
 * @param {number} timeoutMs  Request budget.
 * @param {Function} [fetch]  Injectable transport (tests).
 * @return {Promise<Object>} { ok, statusCode, latencyMs, error }.
 */
export async function runHttpCheck( url, timeoutMs, fetch ) {
	const started = Date.now();
	try {
		await resolvePublicUrl( url ); // SSRF guard — throws on private targets.
		const doGet = fetch || ( ( target, ms ) => axios.get( target, { timeout: ms, maxRedirects: 3 } ) );
		const response = await doGet( url, timeoutMs );
		const latencyMs = Date.now() - started;
		const statusCode = response && Number.isFinite( response.status ) ? response.status : 0;
		return {
			ok: statusCode >= 200 && statusCode < 500,
			statusCode,
			latencyMs,
			error: statusCode >= 500 ? `HTTP ${ statusCode }` : '',
		};
	} catch ( err ) {
		return {
			ok: false,
			statusCode: err.response && err.response.status ? err.response.status : 0,
			latencyMs: Date.now() - started,
			error: err.message || 'request failed',
		};
	}
}

/**
 * TLS certificate expiry check.
 *
 * @param {string} host Hostname.
 * @param {number} port Port (default 443).
 * @param {number} timeoutMs Socket budget.
 * @param {number} nowMs Current time (tests).
 * @return {Promise<Object>} { ok, tlsDaysLeft, error }.
 */
export function runTlsCheck( host, port = 443, timeoutMs = 10000, nowMs = Date.now() ) {
	return new Promise( ( resolve ) => {
		let settled = false;
		const finish = ( result ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			resolve( result );
		};

		let socket = null;
		try {
			socket = tls.connect( { host, port, servername: host }, () => {
				const cert = socket.getPeerCertificate();
				const validTo = cert && cert.valid_to ? Date.parse( cert.valid_to ) : NaN;
				socket.destroy();
				if ( ! Number.isFinite( validTo ) ) {
					finish( { ok: false, tlsDaysLeft: null, error: 'no peer certificate' } );
					return;
				}
				const tlsDaysLeft = Math.floor( ( validTo - nowMs ) / ( 24 * 60 * 60 * 1000 ) );
				finish( { ok: tlsDaysLeft >= 0, tlsDaysLeft, error: tlsDaysLeft >= 0 ? '' : 'certificate expired' } );
			} );
			socket.on( 'error', ( err ) => {
				socket.destroy();
				finish( { ok: false, tlsDaysLeft: null, error: err.message || 'tls error' } );
			} );
			socket.setTimeout( timeoutMs, () => {
				socket.destroy();
				finish( { ok: false, tlsDaysLeft: null, error: 'tls timeout' } );
			} );
		} catch ( err ) {
			finish( { ok: false, tlsDaysLeft: null, error: err.message || 'tls error' } );
		}
	} );
}

/**
 * Full synthetic check for one site: SSRF-validated HTTP probe against the
 * site's public status endpoint + TLS expiry for the host.
 *
 * @param {Object} site Record for the site (siteUrl, etc.).
 * @param {Object} cfg  Site config (syntheticUrl, syntheticTimeoutMs).
 * @param {Function} [now] Clock (tests).
 * @return {Promise<Object>} { ok, checkedAt, statusCode, latencyMs, tlsDaysLeft, error }.
 */
export async function runSyntheticCheck( site, cfg, now = Date.now ) {
	const target =
		cfg.syntheticUrl || ( site.siteUrl ? `${ site.siteUrl.replace( /\/$/, '' ) }/wp-json/mcp-ai/v1/status` : '' );

	if ( ! target ) {
		return { ok: false, checkedAt: now(), statusCode: 0, latencyMs: null, tlsDaysLeft: null, error: 'no target url' };
	}

	let hostname = '';
	try {
		const parsed = await resolvePublicUrl( target ); // SSRF guard — throws.
		hostname = parsed.hostname;
	} catch ( err ) {
		return {
			ok: false,
			checkedAt: now(),
			statusCode: 0,
			latencyMs: null,
			tlsDaysLeft: null,
			error: `skipped: ${ err.message }`,
		};
	}

	const http = await runHttpCheck( target, cfg.syntheticTimeoutMs );
	const tlsResult = 'https:' === new URL( target ).protocol
		? await runTlsCheck( hostname, 443, cfg.syntheticTimeoutMs, now() )
		: { ok: true, tlsDaysLeft: null, error: '' };

	return {
		ok: http.ok,
		checkedAt: now(),
		statusCode: http.statusCode,
		latencyMs: http.latencyMs,
		tlsDaysLeft: tlsResult.tlsDaysLeft,
		error: [ http.error, tlsResult.error ].filter( Boolean ).join( '; ' ),
	};
}
