// ---------------------------------------------------------------------------
// pixel.test.ts — the pixel gate's verdicts.
//
// Two engines, two failure modes: the per-pixel ratio catches localised change,
// SSIM catches structural change. Thresholds are two-sided (fail and warn), and
// masks must remove a genuinely dynamic region from BOTH counts.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { comparePngBuffers, compareRasters, encodeDiff, ssimScore } from '../lib/pixel.ts';
import { encodePng, type Raster } from '../lib/png.ts';

function solid( width: number, height: number, rgba: [ number, number, number, number ] ): Raster {
	const data = new Uint8Array( width * height * 4 );
	for ( let index = 0; index < width * height; index++ ) {
		data.set( rgba, index * 4 );
	}
	return { width, height, data };
}

function withBlock(
	base: Raster,
	rect: { x: number; y: number; width: number; height: number },
	rgba: [ number, number, number, number ]
): Raster {
	const copy: Raster = { width: base.width, height: base.height, data: new Uint8Array( base.data ) };
	for ( let y = rect.y; y < rect.y + rect.height; y++ ) {
		for ( let x = rect.x; x < rect.x + rect.width; x++ ) {
			copy.data.set( rgba, ( y * base.width + x ) * 4 );
		}
	}
	return copy;
}

const WHITE: [ number, number, number, number ] = [ 255, 255, 255, 255 ];
const BLACK: [ number, number, number, number ] = [ 0, 0, 0, 255 ];
const RED: [ number, number, number, number ] = [ 255, 0, 0, 255 ];

describe( 'compareRasters', () => {
	it( 'passes identical rasters with diffRatio 0 and SSIM 1', () => {
		const raster = solid( 100, 100, WHITE );
		const result = compareRasters( raster, solid( 100, 100, WHITE ) );

		expect( result.diffPixels ).toBe( 0 );
		expect( result.diffRatio ).toBe( 0 );
		expect( result.ssim ).toBe( 1 );
		expect( result.verdict ).toBe( 'pass' );
	} );

	it( 'fails a 1% changed area (above the 0.1% default)', () => {
		const source = solid( 100, 100, WHITE );
		const built = withBlock( source, { x: 0, y: 0, width: 10, height: 10 }, BLACK );
		const result = compareRasters( source, built );

		expect( result.diffPixels ).toBe( 100 );
		expect( result.diffRatio ).toBeCloseTo( 0.01, 6 );
		expect( result.verdict ).toBe( 'fail' );
		expect( result.reasons.join( ' ' ) ).toMatch( /pixels differ/ );
	} );

	it( 'warns between the warn and fail ratios', () => {
		const source = solid( 100, 100, WHITE );
		const built = withBlock( source, { x: 0, y: 0, width: 5, height: 1 }, BLACK );
		const result = compareRasters( source, built, {
			maxDiffRatio: 0.01,
			warnDiffRatio: 0.001,
		} );

		expect( result.diffRatio ).toBeCloseTo( 0.0005, 6 );
		expect( result.diffRatio ).toBeLessThan( 0.001 );
		expect( result.verdict ).toBe( 'pass' );

		const louder = compareRasters( source, withBlock( source, { x: 0, y: 0, width: 20, height: 1 }, BLACK ), {
			maxDiffRatio: 0.01,
			warnDiffRatio: 0.001,
		} );
		expect( louder.verdict ).toBe( 'warn' );
	} );

	it( 'fails on size mismatch instead of comparing crops', () => {
		const result = compareRasters( solid( 10, 10, WHITE ), solid( 12, 10, WHITE ) );

		expect( result.verdict ).toBe( 'fail' );
		expect( result.diffRatio ).toBe( 1 );
		expect( result.reasons[ 0 ] ).toMatch( /size mismatch/i );
	} );

	it( 'excludes masked regions from the comparison', () => {
		const source = solid( 100, 100, WHITE );
		const built = withBlock( source, { x: 40, y: 40, width: 10, height: 10 }, RED );
		const result = compareRasters( source, built, {
			masks: [ { x: 40, y: 40, width: 10, height: 10 } ],
		} );

		expect( result.diffPixels ).toBe( 0 );
		expect( result.verdict ).toBe( 'pass' );
	} );

	it( 'fails a structural change through SSIM even when pixels are few', () => {
		const source = solid( 64, 64, WHITE );
		// A high-contrast stripe: few pixels change, structure changes a lot.
		const built = withBlock( source, { x: 0, y: 0, width: 64, height: 32 }, BLACK );
		const result = compareRasters( source, built, { maxDiffRatio: 1 } );

		expect( result.ssim ).toBeLessThan( 0.98 );
		expect( result.verdict ).toBe( 'fail' );
		expect( result.reasons.join( ' ' ) ).toMatch( /SSIM/ );
	} );

	it( 'still counts a flat-region change (the AA filter is not a blanket pass)', () => {
		const source = solid( 3, 3, WHITE );
		const built = withBlock( source, { x: 1, y: 1, width: 1, height: 1 }, [ 200, 200, 200, 255 ] );
		const result = compareRasters( source, built );

		expect( result.diffPixels ).toBe( 1 );
	} );

	it( 'emits a diff raster only when asked', () => {
		const source = solid( 4, 4, WHITE );
		const built = withBlock( source, { x: 0, y: 0, width: 1, height: 1 }, BLACK );

		expect( compareRasters( source, built ).diff ).toBeUndefined();

		const result = compareRasters( source, built, { includeDiff: true } );
		expect( result.diff?.width ).toBe( 4 );
		const red = encodeDiff( result );
		expect( red ).toBeInstanceOf( Uint8Array );
		// The changed pixel is painted red in the artifact.
		expect( Array.from( result.diff!.data.subarray( 0, 3 ) ) ).toEqual( [ 255, 0, 0 ] );
	} );
} );

describe( 'ssimScore', () => {
	it( 'is 1 for identical rasters and near 0 for inverted ones', () => {
		expect( ssimScore( solid( 16, 16, WHITE ), solid( 16, 16, WHITE ) ) ).toBe( 1 );
		expect( ssimScore( solid( 16, 16, WHITE ), solid( 16, 16, BLACK ) ) ).toBeLessThan( 0.01 );
	} );

	it( 'is 0 when the dimensions differ', () => {
		expect( ssimScore( solid( 16, 16, WHITE ), solid( 8, 16, WHITE ) ) ).toBe( 0 );
	} );
} );

describe( 'comparePngBuffers', () => {
	it( 'decodes and compares PNG bytes end to end', () => {
		const source = encodePng( solid( 32, 32, WHITE ) );
		const built = encodePng( solid( 32, 32, WHITE ) );

		const result = comparePngBuffers( source, built );

		expect( result.verdict ).toBe( 'pass' );
		expect( result.width ).toBe( 32 );
	} );
} );
