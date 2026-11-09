// ---------------------------------------------------------------------------
// pixel.ts — the pixel gate, with the anti-flake machinery the industry uses.
//
// `geometry.ts` compares boxes (stable, font-independent) and `vision.ts` asks
// a model whether two screenshots look alike (semantic, non-deterministic).
// Neither answers "are these two renders the same pixels?". This is that gate.
//
// Two engines, because they fail differently (Mironsoft, Wopee, pixelmatch):
//
//  1. Per-pixel delta — pixelmatch's YIQ colour metric with the anti-aliasing
//     filter (Mapbox, ISC). Sensitive; prone to noise without the AA filter,
//     which is why `includeAA` defaults to FALSE.
//  2. SSIM — structural similarity over luminance windows (Wang et al. 2004,
//     IEEE TIP). Noise-resistant: catches "same structure, shifted", which a
//     per-pixel ratio over-reports and a per-pixel threshold under-reports.
//
// Verdicts are two-sided on purpose: a pixel gate fails on `diffRatio` above
// `maxDiffRatio` OR `ssim` below `minSsim`, and warns at the tighter pair.
// Defaults follow practice: 0.1% differing pixels (pixelmatch's 0.1 threshold
// with AA filtering), SSIM 0.98 to fail / 0.99 to warn.
//
// Masks (rectangles) absorb genuinely dynamic regions — carousels, clocks,
// third-party widgets. Do not "fix" noise by loosening the global threshold;
// mask the region (Percy/Chromatic guidance, Visual Sentinel 2026).
// ---------------------------------------------------------------------------

import { decodePng, encodePng, type Raster } from './png.ts';

export interface MaskRect {
	x: number;
	y: number;
	width: number;
	height: number;
}

export interface PixelCompareOptions {
	/** pixelmatch sensitivity, 0–1 (default 0.1; 0.15 for noisy CI). */
	threshold?: number;
	/** Count anti-aliased pixels as differences (default false). */
	includeAA?: boolean;
	/** Regions excluded from the comparison (copied from source into build). */
	masks?: MaskRect[];
	/** Fail above this differing-pixel ratio (default 0.001 = 0.1%). */
	maxDiffRatio?: number;
	/** Warn above this differing-pixel ratio (default 0.0001 = 0.01%). */
	warnDiffRatio?: number;
	/** Fail below this SSIM (default 0.98). */
	minSsim?: number;
	/** Warn below this SSIM (default 0.99). */
	warnSsim?: number;
	/** Emit the highlighted diff raster (default false). */
	includeDiff?: boolean;
}

export interface PixelCompareResult {
	width: number;
	height: number;
	diffPixels: number;
	totalPixels: number;
	diffRatio: number;
	ssim: number;
	verdict: 'pass' | 'warn' | 'fail';
	reasons: string[];
	/** Red = differing, faded = unchanged. Present only with `includeDiff`. */
	diff?: Raster;
}

const MAX_DELTA = 35215; // 255² × (the pixelmatch constant's fixed factor)

function blend( channel: number, alpha: number ): number {
	return 255 + ( channel - 255 ) * alpha;
}

/**
 * pixelmatch's colour delta: 0 when identical, negative when img1 is darker.
 * `yOnly` returns the luma delta (used by the anti-alias probe).
 */
function colorDelta(
	a: Uint8Array,
	b: Uint8Array,
	indexA: number,
	indexB: number,
	yOnly = false
): number {
	let r1 = a[ indexA ];
	let g1 = a[ indexA + 1 ];
	let b1 = a[ indexA + 2 ];
	const a1 = a[ indexA + 3 ];
	let r2 = b[ indexB ];
	let g2 = b[ indexB + 1 ];
	let b2 = b[ indexB + 2 ];
	const a2 = b[ indexB + 3 ];
	if ( a1 === a2 && r1 === r2 && g1 === g2 && b1 === b2 ) {
		return 0;
	}
	if ( a1 < 255 ) {
		const alpha = a1 / 255;
		r1 = blend( r1, alpha );
		g1 = blend( g1, alpha );
		b1 = blend( b1, alpha );
	}
	if ( a2 < 255 ) {
		const alpha = a2 / 255;
		r2 = blend( r2, alpha );
		g2 = blend( g2, alpha );
		b2 = blend( b2, alpha );
	}
	const y1 = r1 * 0.29889531 + g1 * 0.58662247 + b1 * 0.11448223;
	const y2 = r2 * 0.29889531 + g2 * 0.58662247 + b2 * 0.11448223;
	if ( yOnly ) {
		return y1 - y2;
	}
	// YIQ delta: luma dominates, chroma refines. The 0.5053/0.299/0.1957
	// weights are pixelmatch's; the result is signed by the luma order.
	const i1 = r1 * 0.59597799 - g1 * 0.2741761 - b1 * 0.32180189;
	const q1 = r1 * 0.21147017 - g1 * 0.52261711 + b1 * 0.31114694;
	const i2 = r2 * 0.59597799 - g2 * 0.2741761 - b2 * 0.32180189;
	const q2 = r2 * 0.21147017 - g2 * 0.52261711 + b2 * 0.31114694;
	const delta =
		0.5053 * ( y1 - y2 ) ** 2 +
		0.299 * ( i1 - i2 ) ** 2 +
		0.1957 * ( q1 - q2 ) ** 2;
	return y1 > y2 ? -delta : delta;
}

/** Does the pixel at (x, y) sit inside a flat, anti-aliased neighbourhood? */
function hasManySiblings(
	data: Uint8Array,
	width: number,
	height: number,
	x: number,
	y: number
): boolean {
	const x0 = Math.max( x - 1, 0 );
	const y0 = Math.max( y - 1, 0 );
	const x2 = Math.min( x + 1, width - 1 );
	const y2 = Math.min( y + 1, height - 1 );
	const position = ( y * width + x ) * 4;
	let zeroes = x === x0 || x === x2 || y === y0 || y === y2 ? 1 : 0;
	for ( let cx = x0; cx <= x2; cx++ ) {
		for ( let cy = y0; cy <= y2; cy++ ) {
			if ( cx === x && cy === y ) {
				continue;
			}
			if ( colorDelta( data, data, position, ( cy * width + cx ) * 4, true ) === 0 ) {
				zeroes++;
				if ( zeroes > 2 ) {
					return true;
				}
			}
		}
	}
	return false;
}

/** pixelmatch's anti-alias heuristic, evaluated on both images. */
function isAntialiased(
	first: Uint8Array,
	second: Uint8Array,
	width: number,
	height: number,
	x: number,
	y: number
): boolean {
	const x0 = Math.max( x - 1, 0 );
	const y0 = Math.max( y - 1, 0 );
	const x2 = Math.min( x + 1, width - 1 );
	const y2 = Math.min( y + 1, height - 1 );
	const position = ( y * width + x ) * 4;
	let zeroes = x === x0 || x === x2 || y === y0 || y === y2 ? 1 : 0;
	let min = 0;
	let max = 0;
	let minX = x;
	let minY = y;
	let maxX = x;
	let maxY = y;
	for ( let cx = x0; cx <= x2; cx++ ) {
		for ( let cy = y0; cy <= y2; cy++ ) {
			if ( cx === x && cy === y ) {
				continue;
			}
			const delta = colorDelta( first, first, position, ( cy * width + cx ) * 4, true );
			if ( delta === 0 ) {
				zeroes++;
				if ( zeroes > 2 ) {
					return false;
				}
			} else if ( delta < min ) {
				min = delta;
				minX = cx;
				minY = cy;
			} else if ( delta > max ) {
				max = delta;
				maxX = cx;
				maxY = cy;
			}
		}
	}
	if ( min === 0 || max === 0 ) {
		return false;
	}
	return (
		( hasManySiblings( first, width, height, minX, minY ) &&
			hasManySiblings( second, width, height, minX, minY ) ) ||
		( hasManySiblings( first, width, height, maxX, maxY ) &&
			hasManySiblings( second, width, height, maxX, maxY ) )
	);
}

/** BT.601 luma for one RGBA pixel. */
function luma( data: Uint8Array, index: number ): number {
	return (
		data[ index ] * 0.29889531 +
		data[ index + 1 ] * 0.58662247 +
		data[ index + 2 ] * 0.11448223
	);
}

/**
 * Global SSIM over non-overlapping 8×8 luma windows (uniform window function).
 * Returns 1 for identical images, approaching 0 for unrelated ones.
 */
export function ssimScore( a: Raster, b: Raster ): number {
	if ( a.width !== b.width || a.height !== b.height ) {
		return 0;
	}
	const { width, height } = a;
	const window = 8;
	const C1 = ( 0.01 * 255 ) ** 2;
	const C2 = ( 0.03 * 255 ) ** 2;
	let total = 0;
	let windows = 0;
	for ( let y0 = 0; y0 < height; y0 += window ) {
		for ( let x0 = 0; x0 < width; x0 += window ) {
			const x1 = Math.min( x0 + window, width );
			const y1 = Math.min( y0 + window, height );
			const count = ( x1 - x0 ) * ( y1 - y0 );
			if ( count === 0 ) {
				continue;
			}
			let sumA = 0;
			let sumB = 0;
			let sumAA = 0;
			let sumBB = 0;
			let sumAB = 0;
			for ( let y = y0; y < y1; y++ ) {
				for ( let x = x0; x < x1; x++ ) {
					const index = ( y * width + x ) * 4;
					const la = luma( a.data, index );
					const lb = luma( b.data, index );
					sumA += la;
					sumB += lb;
					sumAA += la * la;
					sumBB += lb * lb;
					sumAB += la * lb;
				}
			}
			const meanA = sumA / count;
			const meanB = sumB / count;
			const varianceA = sumAA / count - meanA * meanA;
			const varianceB = sumBB / count - meanB * meanB;
			const covariance = sumAB / count - meanA * meanB;
			const numerator = ( 2 * meanA * meanB + C1 ) * ( 2 * covariance + C2 );
			const denominator =
				( meanA * meanA + meanB * meanB + C1 ) * ( varianceA + varianceB + C2 );
			total += denominator === 0 ? 1 : numerator / denominator;
			windows++;
		}
	}
	return windows === 0 ? 1 : total / windows;
}

/** Copy `source` pixels into `built` inside every mask (compare ignores them). */
function applyMasks( source: Raster, built: Raster, masks: MaskRect[] ): void {
	for ( const mask of masks ) {
		const x0 = Math.max( 0, Math.floor( mask.x ) );
		const y0 = Math.max( 0, Math.floor( mask.y ) );
		const x1 = Math.min( source.width, Math.ceil( mask.x + mask.width ) );
		const y1 = Math.min( source.height, Math.ceil( mask.y + mask.height ) );
		for ( let y = y0; y < y1; y++ ) {
			const start = ( y * source.width + x0 ) * 4;
			const end = ( y * source.width + x1 ) * 4;
			built.data.set( source.data.subarray( start, end ), start );
		}
	}
}

/**
 * Compare two rasters. Size mismatch is a failure, not a warning: the caller
 * captured different viewports, so every downstream number would be a lie.
 */
export function compareRasters(
	source: Raster,
	built: Raster,
	options: PixelCompareOptions = {}
): PixelCompareResult {
	const threshold = options.threshold ?? 0.1;
	const includeAA = options.includeAA === true;
	const maxDiffRatio = options.maxDiffRatio ?? 0.001;
	const warnDiffRatio = options.warnDiffRatio ?? 0.0001;
	const minSsim = options.minSsim ?? 0.98;
	const warnSsim = options.warnSsim ?? 0.99;
	if ( source.width !== built.width || source.height !== built.height ) {
		return {
			width: source.width,
			height: source.height,
			diffPixels: source.width * source.height,
			totalPixels: source.width * source.height,
			diffRatio: 1,
			ssim: 0,
			verdict: 'fail',
			reasons: [
				`size mismatch: source ${ source.width }×${ source.height }, build ${ built.width }×${ built.height } — capture both at the same viewport`,
			],
		};
	}
	const { width, height } = source;
	if ( options.masks && options.masks.length > 0 ) {
		applyMasks( source, built, options.masks );
	}
	const maxDelta = MAX_DELTA * threshold * threshold;
	const diffData = options.includeDiff ? new Uint8Array( width * height * 4 ) : undefined;
	const totalPixels = width * height;
	let diffPixels = 0;
	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			const index = ( y * width + x ) * 4;
			const delta = colorDelta( source.data, built.data, index, index );
			let differs = Math.abs( delta ) > maxDelta;
			if ( differs && ! includeAA ) {
				differs =
					! isAntialiased( source.data, built.data, width, height, x, y ) &&
					! isAntialiased( built.data, source.data, width, height, x, y );
			}
			if ( differs ) {
				diffPixels++;
			}
			if ( diffData ) {
				if ( differs ) {
					diffData[ index ] = 255;
					diffData[ index + 1 ] = 0;
					diffData[ index + 2 ] = 0;
					diffData[ index + 3 ] = 255;
				} else {
					// Fade the unchanged pixel so the diff is readable.
					const grey = Math.round( 255 - ( 255 - luma( source.data, index ) ) * 0.1 );
					diffData[ index ] = grey;
					diffData[ index + 1 ] = grey;
					diffData[ index + 2 ] = grey;
					diffData[ index + 3 ] = 255;
				}
			}
		}
	}
	const diffRatio = totalPixels === 0 ? 0 : diffPixels / totalPixels;
	const ssim = ssimScore( source, built );
	const reasons: string[] = [];
	let verdict: PixelCompareResult[ 'verdict' ] = 'pass';
	if ( diffRatio > maxDiffRatio ) {
		verdict = 'fail';
		reasons.push(
			`${ ( diffRatio * 100 ).toFixed( 3 ) }% of pixels differ (limit ${ ( maxDiffRatio * 100 ).toFixed( 3 ) }%)`
		);
	} else if ( diffRatio > warnDiffRatio ) {
		verdict = 'warn';
		reasons.push(
			`${ ( diffRatio * 100 ).toFixed( 3 ) }% of pixels differ (warn above ${ ( warnDiffRatio * 100 ).toFixed( 3 ) }%)`
		);
	}
	if ( ssim < minSsim ) {
		verdict = 'fail';
		reasons.push( `SSIM ${ ssim.toFixed( 4 ) } below ${ minSsim }` );
	} else if ( ssim < warnSsim && verdict === 'pass' ) {
		verdict = 'warn';
		reasons.push( `SSIM ${ ssim.toFixed( 4 ) } below ${ warnSsim }` );
	}
	return {
		width,
		height,
		diffPixels,
		totalPixels,
		diffRatio,
		ssim,
		verdict,
		reasons,
		diff: diffData ? { width, height, data: diffData } : undefined,
	};
}

/** Decode two PNG buffers and compare them. */
export function comparePngBuffers(
	source: Uint8Array,
	built: Uint8Array,
	options: PixelCompareOptions = {}
): PixelCompareResult {
	return compareRasters( decodePng( source ), decodePng( built ), options );
}

/** Encode a comparison's diff raster for the operator (PNG bytes). */
export function encodeDiff( result: PixelCompareResult ): Uint8Array | undefined {
	return result.diff ? encodePng( result.diff ) : undefined;
}
