// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Chart.js PNG rendering, shared by the data and math route groups.
 *
 * Both `/api/data/render-chart` and `/api/math/graph` end in the same place: a
 * Chart.js config turned into a PNG buffer by chartjs-node-canvas. The native
 * `canvas` binding behind it is optional, and when it is missing the failure
 * has to be a typed 503 rather than a 500 — a caller that asks for a chart on a
 * server without the binding has asked for a capability this deployment does
 * not advertise, not made a mistake.
 *
 * Keeping the import inside the function matters: `chartjs-node-canvas` is
 * imported lazily so a deployment without the binding can still start the
 * worker and serve every route that does not render.
 */

/** Effective render defaults, matching the original single-route inline code. */
const DEFAULTS = { width: 800, height: 400, background: 'white' };

/**
 * Whether a thrown error means the optional canvas binding is absent.
 *
 * Three shapes occur in practice and all three are the same capability gap:
 *
 *  - `ERR_MODULE_NOT_FOUND` / `ERR_DLOPEN_FAILED` from the ESM import of
 *    `chartjs-node-canvas`;
 *  - `MODULE_NOT_FOUND` when the package is installed but its native binary
 *    was never built, which is the **common** case (npm exits 0 and leaves
 *    `canvas/build/Release/canvas.node` absent);
 *  - a message that names the binding file, for wrappers that rethrow.
 *
 * The original inline catch tested only the first two codes, so a server
 * without the built binding got a 500 instead of the typed 503 the route
 * advertises. Treating the package itself as missing is also correct: either
 * way this deployment cannot render.
 */
export function isCanvasUnavailable( err ) {
	const code = err && err.code;

	if ( 'ERR_MODULE_NOT_FOUND' === code || 'ERR_DLOPEN_FAILED' === code || 'MODULE_NOT_FOUND' === code ) {
		return true;
	}

	const message = err && err.message ? String( err.message ) : '';

	return /canvas\.node/.test( message ) || /Cannot find module 'canvas'/.test( message );
}

/**
 * Render a Chart.js config to a PNG buffer.
 *
 * Returns the buffer and the effective dimensions, so a caller reports the size
 * it actually produced rather than the size it asked for.
 */
export async function renderChartPng( config, options = {} ) {
	const width = options.width || DEFAULTS.width;
	const height = options.height || DEFAULTS.height;
	const background = options.background || DEFAULTS.background;

	const { ChartJSNodeCanvas } = await import( 'chartjs-node-canvas' );
	const renderer = new ChartJSNodeCanvas( { width, height, backgroundColour: background } );
	const image = await renderer.renderToBuffer( config );

	return { image, width, height };
}
