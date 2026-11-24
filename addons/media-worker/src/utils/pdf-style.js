/**
 * Watermark styling and OCR rasterisation constants.
 *
 * Pure functions, deliberately in their own module: `routes/pdf.test.js` can
 * import them without express, multer or a running server, which is what makes
 * the placement maths cheap to pin. The route file is then only wiring.
 *
 * ## Why the defaults are exactly the old fixed mark
 *
 * Before this module the watermark route drew one hard-coded mark: 48pt
 * Helvetica, grey, 0.3 opacity, centred, rotated −45°. Callers that send no
 * style (the WordPress tool does not) must keep getting that byte-for-byte, so
 * `diagonal` reproduces the original coordinates rather than re-deriving them
 * from a text-width estimate. A re-derivation would shift every existing
 * document by a few points for no reason.
 *
 * ## Why 300 DPI
 *
 * OCR engines want pixels, and the accuracy ceiling is set at rasterisation
 * time. The published guidance converges on 300 DPI as the working default
 * (600 for small type or archival work; below ~200 accuracy falls off sharply),
 * while very high densities hit engine pixel caps and blow up memory. So 300 is
 * the default, 150 the floor and 600 the ceiling.
 */

/** Default multiplication factor for the mark, matching the original route. */
export const WATERMARK_DEFAULT_SIZE = 48;

/** Default opacity, matching the original route. */
export const WATERMARK_DEFAULT_OPACITY = 0.3;

/** Margin from the page edge for the `top` and `bottom` positions, in points. */
const EDGE_MARGIN = 36;

/**
 * Clamp a caller-supplied opacity to 0–1.
 *
 * @param {*}      value    Raw value from the body.
 * @param {number} fallback Used when the value is absent or unparseable.
 * @return {number} Opacity in [0, 1].
 */
export function clampOpacity( value, fallback = WATERMARK_DEFAULT_OPACITY ) {
	if ( value === undefined || value === null || '' === value ) {
		return fallback;
	}

	const parsed = Number( value );

	if ( ! Number.isFinite( parsed ) ) {
		return fallback;
	}

	return Math.min( 1, Math.max( 0, parsed ) );
}

/**
 * Clamp a caller-supplied OCR resolution.
 *
 * @param {*}      value    Raw value from the body.
 * @param {number} fallback Used when the value is absent or unparseable.
 * @param {number} min      Lower bound (below this, accuracy collapses).
 * @param {number} max      Upper bound (above this, engine pixel caps and memory).
 * @return {number} DPI.
 */
export function clampDpi( value, fallback = 300, min = 150, max = 600 ) {
	const parsed = Number( value );

	if ( ! Number.isFinite( parsed ) || parsed <= 0 ) {
		return fallback;
	}

	return Math.round( Math.min( max, Math.max( min, parsed ) ) );
}

/**
 * Clamp the page budget for one OCR call.
 *
 * OCR is seconds per page, and a 200-page scan behind one HTTP request is a
 * timeout that looks like a crash. The cap is explicit and the response says
 * when it truncated, so a caller can loop.
 *
 * @param {*}      value    Raw value from the body.
 * @param {number} fallback Used when absent.
 * @param {number} max      Hard ceiling.
 * @return {number} Page budget, at least 1.
 */
export function clampPageBudget( value, fallback = 20, max = 50 ) {
	const parsed = Number( value );

	if ( ! Number.isFinite( parsed ) || parsed <= 0 ) {
		return fallback;
	}

	return Math.min( max, Math.max( 1, Math.floor( parsed ) ) );
}

/**
 * Rough rendered width of a string in Helvetica, in points.
 *
 * Helvetica averages a little under half an em per glyph. This is an estimate
 * used only to centre `center`/`top`/`bottom` marks — the `diagonal` default
 * does not use it, so existing output cannot move.
 *
 * @param {string} text     Mark text.
 * @param {number} fontSize Font size in points.
 * @return {number} Estimated width in points.
 */
export function estimateTextWidth( text, fontSize ) {
	return String( text ).length * fontSize * 0.5;
}

/**
 * Where to draw the mark on one page.
 *
 * @param {Object} spec              Placement request.
 * @param {string} [spec.position]   `diagonal` | `center` | `top` | `bottom`.
 * @param {number} spec.pageWidth    Page width in points.
 * @param {number} spec.pageHeight   Page height in points.
 * @param {string} spec.text         Mark text.
 * @param {number} [spec.fontSize]   Font size in points.
 * @return {{x: number, y: number, rotate: number, size: number}} pdf-lib draw arguments.
 */
export function watermarkPlacement( spec ) {
	const width = Number( spec.pageWidth ) || 0;
	const height = Number( spec.pageHeight ) || 0;
	const text = String( spec.text || '' );
	const size = Number( spec.fontSize ) > 0 ? Number( spec.fontSize ) : WATERMARK_DEFAULT_SIZE;
	const position = String( spec.position || 'diagonal' ).toLowerCase();

	switch ( position ) {
		case 'center': {
			const textWidth = estimateTextWidth( text, size );
			return { x: Math.max( 0, width / 2 - textWidth / 2 ), y: height / 2, rotate: 0, size };
		}
		case 'top': {
			const textWidth = estimateTextWidth( text, size );
			return { x: Math.max( 0, width / 2 - textWidth / 2 ), y: Math.max( 0, height - size - EDGE_MARGIN ), rotate: 0, size };
		}
		case 'bottom': {
			const textWidth = estimateTextWidth( text, size );
			return { x: Math.max( 0, width / 2 - textWidth / 2 ), y: EDGE_MARGIN, rotate: 0, size };
		}
		case 'diagonal':
		default:
			// The original hard-coded mark, preserved exactly.
			return { x: width / 2 - 150, y: height / 2, rotate: -45, size };
	}
}

/**
 * The styles the route honours. Anything else falls back to `diagonal`.
 *
 * @return {string[]} Accepted position names.
 */
export function acceptedPositions() {
	return [ 'diagonal', 'center', 'top', 'bottom' ];
}
