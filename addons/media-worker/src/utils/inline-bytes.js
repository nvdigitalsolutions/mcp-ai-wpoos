// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Carry a file's bytes in a JSON response.
 *
 * The worker's binary routes have always written to its own volume and answered
 * `output_path`; the image and PDF families additionally answer `b64`. The
 * browser routes did not, which meant a host could trigger a render it could
 * never retrieve — Proposal 164 §164.51 recorded that as a delivery gap and
 * §164.53 closes it.
 *
 * Two rules:
 *
 *  - **Cap the payload.** Base64 inflates by a third, and a full-page PNG of a
 *    long page can be tens of megabytes. Past the cap the route refuses with
 *    HTTP 413 instead of building a response the host cannot hold.
 *  - **Report the size of the file, not of the encoding.** `size` is the byte
 *    count on disk, so a caller sizing a store does not have to divide by 1.37.
 *
 * This lives outside `routes/browser.js` so it can be unit-tested without
 * Puppeteer or Express — the route module imports both, and importing it in a
 * test would launch neither but resolve both.
 */

import fs from 'fs';

/** 24 MiB. Past this the route refuses rather than inlining. */
export const MAX_INLINE_BYTES = 24 * 1024 * 1024;

/**
 * Read a file the worker just wrote and prepare the inline half of the response.
 *
 * @param {string} filePath Absolute path to the file.
 * @param {string} mime     MIME type to report.
 * @param {number} cap      Maximum bytes to inline.
 * @returns {{b64: string, mime: string, size: number}}
 */
export function inlineFile( filePath, mime, cap = MAX_INLINE_BYTES ) {
	const size = fs.statSync( filePath ).size;

	if ( size > cap ) {
		const error = new Error(
			`The file is ${ ( size / 1048576 ).toFixed( 1 ) } MB; the inline cap is ${ ( cap / 1048576 ).toFixed( 0 ) } MB.`
		);
		error.status = 413;
		throw error;
	}

	return {
		b64: fs.readFileSync( filePath ).toString( 'base64' ),
		mime,
		size,
	};
}
