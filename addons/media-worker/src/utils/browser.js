/**
 * Hardened Puppeteer launcher shared by the browser and PDF routes.
 *
 * Security posture (per security plan 024 + Puppeteer production guidance):
 *   - Chromium runs WITH its sandbox. `--no-sandbox` is stripped from any
 *     PUPPETEER_ARGS override: this worker renders attacker-influenced HTML
 *     and arbitrary URLs, and a sandbox-less browser is the single most
 *     dangerous Puppeteer misconfiguration.
 *   - Requests made by rendered pages are intercepted and re-validated
 *     (resolved IPs must be publicly routable) — a browser-level SSRF guard.
 *   - Downloads from rendered pages are denied.
 *   - Concurrent browser launches are capped to bound memory usage.
 *
 * Constrained local environments may set ALLOW_NO_SANDBOX=1 to fall back to
 * --no-sandbox when the sandbox cannot start. Never set it on a publicly
 * reachable deployment.
 */

import dns from 'dns';
import { isPrivateHostname, isPrivateAddress } from './safe-url.js';

const MAX_CONCURRENT = Math.max( 1, Number( process.env.PUPPETEER_MAX_CONCURRENT || 2 ) );
const DANGEROUS_ARGS = new Set( [ '--no-sandbox', '--disable-setuid-sandbox' ] );

let activeLaunches = 0;

/**
 * Acquire a slot in the concurrency limiter.
 *
 * @return {Function} Release function.
 */
async function acquireSlot() {
	for ( ;; ) {
		if ( activeLaunches < MAX_CONCURRENT ) {
			activeLaunches += 1;
			return () => {
				activeLaunches -= 1;
			};
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
	}
}

/**
 * Build the Chromium argument list, stripping dangerous flags.
 *
 * @return {string[]} Launch arguments.
 */
function buildArgs() {
	const requested = ( process.env.PUPPETEER_ARGS || '' ).split( ' ' ).filter( Boolean );
	const stripped = requested.filter( ( arg ) => DANGEROUS_ARGS.has( arg ) );
	if ( stripped.length ) {
		console.warn(
			`[Browser] Ignoring ${ stripped.join( ', ' ) } from PUPPETEER_ARGS — ` +
			'the Chromium sandbox must stay enabled on public deployments.'
		);
	}
	return [ '--disable-dev-shm-usage', ...requested.filter( ( arg ) => ! DANGEROUS_ARGS.has( arg ) ) ];
}

/**
 * Launch Chromium with the sandbox enabled (fallback documented above).
 *
 * @return {Promise<import('puppeteer').Browser>} Browser instance.
 */
export async function launchHardenedBrowser() {
	const release = await acquireSlot();
	try {
		const puppeteer = ( await import( 'puppeteer' ) ).default;
		const args = buildArgs();
		const options = {
			headless: 'new',
			executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
			args,
		};
		try {
			return await puppeteer.launch( options );
		} catch ( err ) {
			if ( '1' === process.env.ALLOW_NO_SANDBOX && /sandbox/i.test( err.message ) ) {
				console.warn(
					'[Browser] Chromium sandbox could not start; falling back to ' +
					'--no-sandbox because ALLOW_NO_SANDBOX=1. Do NOT use this on ' +
					'publicly reachable deployments.'
				);
				return await puppeteer.launch( {
					...options,
					args: [ ...options.args, '--no-sandbox', '--disable-setuid-sandbox' ],
				} );
			}
			throw err;
		}
	} finally {
		release();
	}
}

/**
 * Apply page-level containment: SSRF-aware request interception and
 * download denial.
 *
 * @param {import('puppeteer').Page} page Page instance.
 */
export async function hardenPage( page ) {
	// Deny downloads from rendered pages.
	try {
		const client = await page.target().createCDPSession();
		await client.send( 'Page.setDownloadBehavior', { behavior: 'deny' } );
	} catch {
		// Non-fatal: older Chromium builds may not support the CDP call.
	}

	await page.setRequestInterception( true );
	page.on( 'request', async ( request ) => {
		try {
			const target = new URL( request.url() );
			if ( 'http:' !== target.protocol && 'https:' !== target.protocol ) {
				return request.abort();
			}
			const hostname = target.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();
			if ( isPrivateHostname( hostname ) ) {
				return request.abort();
			}
			if ( ! hostname.includes( ':' ) && ! /^\d{1,3}(\.\d{1,3}){3}$/.test( hostname ) ) {
				const records = await dns.promises.lookup( hostname, { all: true, verbatim: true } );
				for ( const record of records ) {
					if ( isPrivateAddress( record.address ) ) {
						return request.abort();
					}
				}
			}
			request.continue();
		} catch {
			request.abort();
		}
	} );
}
