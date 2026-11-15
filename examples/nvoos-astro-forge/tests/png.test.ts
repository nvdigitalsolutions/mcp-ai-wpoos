// ---------------------------------------------------------------------------
// png.test.ts — the dependency-free codec.
//
// The pixel gate trusts this decoder, so the contract is narrow and enforced:
// 8-bit, non-interlaced, colour types 0/2/4/6 — anything else throws instead of
// silently comparing garbage.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { decodePng, encodePng, type Raster } from '../lib/png.ts';

/** Patch a byte inside IHDR's data (sig 8 + len 4 + type 4 = data at 16). */
function patchIhdr( png: Uint8Array, offset: number, value: number ): Uint8Array {
	const copy = new Uint8Array( png );
	copy[ 16 + offset ] = value;
	return copy;
}

function solid( width: number, height: number, rgba: [ number, number, number, number ] ): Raster {
	const data = new Uint8Array( width * height * 4 );
	for ( let index = 0; index < width * height; index++ ) {
		data.set( rgba, index * 4 );
	}
	return { width, height, data };
}

describe( 'encodePng / decodePng', () => {
	it( 'round-trips RGBA byte-for-byte', () => {
		const raster: Raster = {
			width: 3,
			height: 2,
			data: new Uint8Array( [
				255, 0, 0, 255, 0, 255, 0, 255, 0, 0, 255, 255,
				10, 20, 30, 40, 200, 100, 50, 255, 0, 0, 0, 0,
			] ),
		};

		const decoded = decodePng( encodePng( raster ) );

		expect( decoded.width ).toBe( 3 );
		expect( decoded.height ).toBe( 2 );
		expect( Array.from( decoded.data ) ).toEqual( Array.from( raster.data ) );
	} );

	it( 'encodes a valid signature and IHDR', () => {
		const png = encodePng( solid( 1, 1, [ 1, 2, 3, 255 ] ) );
		expect( Array.from( png.subarray( 0, 8 ) ) ).toEqual( [ 137, 80, 78, 71, 13, 10, 26, 10 ] );
		expect( String.fromCharCode( ...png.subarray( 12, 16 ) ) ).toBe( 'IHDR' );
	} );

	it( 'rejects a non-PNG buffer', () => {
		expect( () => decodePng( new Uint8Array( [ 1, 2, 3, 4 ] ) ) ).toThrow( /bad signature/i );
	} );

	it( 'rejects 16-bit depth rather than guessing', () => {
		const png = patchIhdr( encodePng( solid( 1, 1, [ 0, 0, 0, 255 ] ) ), 8, 16 );
		expect( () => decodePng( png ) ).toThrow( /bit depth/i );
	} );

	it( 'rejects interlacing', () => {
		const png = patchIhdr( encodePng( solid( 1, 1, [ 0, 0, 0, 255 ] ) ), 12, 1 );
		expect( () => decodePng( png ) ).toThrow( /interlaced/i );
	} );

	it( 'rejects palette images', () => {
		const png = patchIhdr( encodePng( solid( 1, 1, [ 0, 0, 0, 255 ] ) ), 9, 3 );
		expect( () => decodePng( png ) ).toThrow( /colour type/i );
	} );

	it( 'validates the encode input length', () => {
		expect( () => encodePng( { width: 2, height: 2, data: new Uint8Array( 4 ) } ) ).toThrow(
			/width\*height\*4/
		);
	} );
} );
