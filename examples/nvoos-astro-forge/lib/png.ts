// ---------------------------------------------------------------------------
// png.ts — a minimal, dependency-free PNG codec.
//
// The forge compares screenshots (Proposal 118 territory), and its only image
// source is Playwright/endpoint PNGs. Rather than pull pngjs + pixelmatch into
// a package that is deliberately lean (and vendored into generated projects),
// this decodes exactly the PNG flavour browsers emit — 8-bit, non-interlaced,
// colour types 0/2/4/6 — and encodes RGBA for the diff artifact. node:zlib
// does the compression, so the whole thing is ~150 lines.
//
// Scope is enforced, not best-effort: an unsupported PNG (16-bit, palette,
// interlaced) throws a typed error the caller reports, because silently
// comparing garbage would be worse than skipping the gate.
// ---------------------------------------------------------------------------

import { deflateSync, inflateSync } from 'node:zlib';

/** An 8-bit RGBA raster. `data.length === width * height * 4`. */
export interface Raster {
	width: number;
	height: number;
	data: Uint8Array;
}

const SIGNATURE = [ 137, 80, 78, 71, 13, 10, 26, 10 ];

const CRC_TABLE: Uint32Array = ( () => {
	const table = new Uint32Array( 256 );
	for ( let n = 0; n < 256; n++ ) {
		let c = n;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		table[ n ] = c >>> 0;
	}
	return table;
} )();

function crc32( bytes: Uint8Array ): number {
	let crc = 0xffffffff;
	for ( let index = 0; index < bytes.length; index++ ) {
		crc = CRC_TABLE[ ( crc ^ bytes[ index ] ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

function readU32( bytes: Uint8Array, offset: number ): number {
	return (
		( bytes[ offset ] << 24 ) |
		( bytes[ offset + 1 ] << 16 ) |
		( bytes[ offset + 2 ] << 8 ) |
		bytes[ offset + 3 ]
	) >>> 0;
}

function writeU32( target: Uint8Array, offset: number, value: number ): void {
	target[ offset ] = ( value >>> 24 ) & 0xff;
	target[ offset + 1 ] = ( value >>> 16 ) & 0xff;
	target[ offset + 2 ] = ( value >>> 8 ) & 0xff;
	target[ offset + 3 ] = value & 0xff;
}

/** Colour type → channels per pixel (8-bit). */
const CHANNELS: Record< number, number > = { 0: 1, 2: 3, 4: 2, 6: 4 };

/**
 * Paeth predictor (PNG spec §9.4). Kept separate so the unfilter loop reads as
 * the spec's five cases.
 */
function paeth( a: number, b: number, c: number ): number {
	const p = a + b - c;
	const pa = Math.abs( p - a );
	const pb = Math.abs( p - b );
	const pc = Math.abs( p - c );
	if ( pa <= pb && pa <= pc ) {
		return a;
	}
	return pb <= pc ? b : c;
}

/** Decode a PNG buffer into RGBA. Throws on unsupported variants. */
export function decodePng( input: Uint8Array ): Raster {
	if ( input.length < 8 || SIGNATURE.some( ( byte, index ) => input[ index ] !== byte ) ) {
		throw new Error( 'Not a PNG (bad signature).' );
	}
	let offset = 8;
	let width = 0;
	let height = 0;
	let colorType = 6;
	let bitDepth = 8;
	let interlace = 0;
	const idat: Uint8Array[] = [];
	while ( offset + 8 <= input.length ) {
		const length = readU32( input, offset );
		const type = String.fromCharCode(
			input[ offset + 4 ],
			input[ offset + 5 ],
			input[ offset + 6 ],
			input[ offset + 7 ]
		);
		const dataStart = offset + 8;
		const dataEnd = dataStart + length;
		if ( dataEnd > input.length ) {
			throw new Error( `Truncated PNG chunk ${ type }.` );
		}
		const data = input.subarray( dataStart, dataEnd );
		if ( type === 'IHDR' ) {
			width = readU32( data, 0 );
			height = readU32( data, 4 );
			bitDepth = data[ 8 ];
			colorType = data[ 9 ];
			interlace = data[ 12 ];
		} else if ( type === 'IDAT' ) {
			idat.push( data );
		} else if ( type === 'IEND' ) {
			break;
		}
		offset = dataEnd + 4; // skip CRC
	}
	if ( bitDepth !== 8 ) {
		throw new Error( `Unsupported PNG bit depth ${ bitDepth } (only 8-bit is supported).` );
	}
	if ( interlace !== 0 ) {
		throw new Error( 'Interlaced PNGs are not supported.' );
	}
	const channels = CHANNELS[ colorType ];
	if ( ! channels ) {
		throw new Error( `Unsupported PNG colour type ${ colorType } (palette images are not supported).` );
	}
	if ( width === 0 || height === 0 ) {
		throw new Error( 'PNG has no pixels.' );
	}
	const raw = inflateSync( Buffer.concat( idat.map( ( chunk ) => Buffer.from( chunk ) ) ) );
	const stride = width * channels;
	const expected = height * ( stride + 1 );
	if ( raw.length < expected ) {
		throw new Error( `PNG pixel data is short (${ raw.length } < ${ expected }).` );
	}
	const pixels = new Uint8Array( height * stride );
	let source = 0;
	for ( let y = 0; y < height; y++ ) {
		const filter = raw[ source++ ];
		const rowStart = y * stride;
		const previousStart = rowStart - stride;
		for ( let x = 0; x < stride; x++ ) {
			const value = raw[ source++ ];
			const left = x >= channels ? pixels[ rowStart + x - channels ] : 0;
			const up = y > 0 ? pixels[ previousStart + x ] : 0;
			const upLeft = y > 0 && x >= channels ? pixels[ previousStart + x - channels ] : 0;
			let restored: number;
			switch ( filter ) {
				case 0:
					restored = value;
					break;
				case 1:
					restored = value + left;
					break;
				case 2:
					restored = value + up;
					break;
				case 3:
					restored = value + ( ( left + up ) >> 1 );
					break;
				case 4:
					restored = value + paeth( left, up, upLeft );
					break;
				default:
					throw new Error( `Unknown PNG filter ${ filter }.` );
			}
			pixels[ rowStart + x ] = restored & 0xff;
		}
	}
	return { width, height, data: toRgba( pixels, width, height, channels ) };
}

function toRgba(
	pixels: Uint8Array,
	width: number,
	height: number,
	channels: number
): Uint8Array {
	if ( channels === 4 ) {
		return pixels;
	}
	const out = new Uint8Array( width * height * 4 );
	for ( let index = 0; index < width * height; index++ ) {
		const source = index * channels;
		const target = index * 4;
		if ( channels === 1 ) {
			out[ target ] = out[ target + 1 ] = out[ target + 2 ] = pixels[ source ];
			out[ target + 3 ] = 255;
		} else if ( channels === 2 ) {
			out[ target ] = out[ target + 1 ] = out[ target + 2 ] = pixels[ source ];
			out[ target + 3 ] = pixels[ source + 1 ];
		} else {
			out[ target ] = pixels[ source ];
			out[ target + 1 ] = pixels[ source + 1 ];
			out[ target + 2 ] = pixels[ source + 2 ];
			out[ target + 3 ] = 255;
		}
	}
	return out;
}

function chunk( type: string, data: Uint8Array ): Uint8Array {
	const out = new Uint8Array( data.length + 12 );
	writeU32( out, 0, data.length );
	for ( let index = 0; index < 4; index++ ) {
		out[ 4 + index ] = type.charCodeAt( index );
	}
	out.set( data, 8 );
	const crcInput = out.subarray( 4, data.length + 8 );
	writeU32( out, data.length + 8, crc32( crcInput ) );
	return out;
}

/** Encode RGBA to a PNG (colour type 6, filter 0 per row). */
export function encodePng( raster: Raster ): Uint8Array {
	const { width, height, data } = raster;
	if ( data.length !== width * height * 4 ) {
		throw new Error( 'encodePng expects RGBA data with length width*height*4.' );
	}
	const stride = width * 4;
	const raw = new Uint8Array( height * ( stride + 1 ) );
	for ( let y = 0; y < height; y++ ) {
		raw[ y * ( stride + 1 ) ] = 0;
		raw.set( data.subarray( y * stride, ( y + 1 ) * stride ), y * ( stride + 1 ) + 1 );
	}
	const ihdr = new Uint8Array( 13 );
	writeU32( ihdr, 0, width );
	writeU32( ihdr, 4, height );
	ihdr[ 8 ] = 8;
	ihdr[ 9 ] = 6;
	const parts = [
		new Uint8Array( SIGNATURE ),
		chunk( 'IHDR', ihdr ),
		chunk( 'IDAT', deflateSync( Buffer.from( raw ) ) ),
		chunk( 'IEND', new Uint8Array( 0 ) ),
	];
	const total = parts.reduce( ( sum, part ) => sum + part.length, 0 );
	const out = new Uint8Array( total );
	let offset = 0;
	for ( const part of parts ) {
		out.set( part, offset );
		offset += part.length;
	}
	return out;
}
