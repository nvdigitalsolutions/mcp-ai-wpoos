/**
 * Runtime capability detection.
 *
 * Managed Node.js hosts (e.g. Cloudways Velocity) may not provide system
 * binaries like ffmpeg or Chromium. Detect them once at boot and expose the
 * result via /api/health/full so routes can degrade to 503
 * "capability_unavailable" instead of crashing, and WordPress falls back
 * through its existing service cascade.
 */

import { execFile } from 'child_process';
import { promisify } from 'util';

const execFileAsync = promisify( execFile );

let cached = null;

/**
 * Check whether a binary is on PATH.
 *
 * @param {string} bin Binary name.
 * @return {Promise<boolean>} True when resolvable.
 */
async function hasBinary( bin ) {
	try {
		await execFileAsync( 'which', [ bin ], { timeout: 5000 } );
		return true;
	} catch {
		return false;
	}
}

/**
 * Detect system capabilities (cached for the process lifetime).
 *
 * @return {Promise<Object>} Capability flags.
 */
export async function detectCapabilities() {
	if ( cached ) {
		return cached;
	}

	const [ ffmpeg, ffprobe, chromium, chromiumBrowser ] = await Promise.all( [
		hasBinary( 'ffmpeg' ),
		hasBinary( 'ffprobe' ),
		hasBinary( 'chromium' ),
		hasBinary( 'chromium-browser' ),
	] );

	cached = {
		ffmpeg,
		ffprobe,
		chromium: chromium || chromiumBrowser,
		redis: Boolean( process.env.REDIS_URL ),
	};
	return cached;
}
