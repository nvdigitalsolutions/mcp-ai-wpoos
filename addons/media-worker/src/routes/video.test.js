/**
 * Argument bounds for the video route (Proposal 164 §164.4.5).
 *
 * The route used to build one ffmpeg shell string from request fields, so a
 * `start` of `1; rm -rf /` was a command, and it `eval`'d ffprobe's
 * `r_frame_rate` — a string that comes from the uploaded file, which made a
 * crafted video into remote code execution.
 *
 * These tests pin the three replacements:
 *
 *  - values are parsed and bounded before they reach an argv element;
 *  - the output format is an allowlist, because it names a file;
 *  - a rational is divided, not evaluated.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	boundedNumber,
	boundedDimension,
	resolveFormat,
	resolveFit,
	parseRational,
	boolField,
	atempoChain,
	PROCESS_FORMATS,
} from './video.js';

test( 'an absent numeric field is null rather than zero', () => {
	assert.equal( boundedNumber( undefined, 0, 10, 'start' ), null );
	assert.equal( boundedNumber( '', 0, 10, 'start' ), null );
	assert.equal( boundedNumber( null, 0, 10, 'start' ), null );
} );

test( 'a numeric field outside its bounds is refused with the field name', () => {
	assert.throws( () => boundedNumber( -1, 0, 10, 'start' ), /start must be a number between 0 and 10/ );
	assert.throws( () => boundedNumber( 11, 0, 10, 'start' ), /start must be a number/ );
} );

test( 'a shell metacharacter in a numeric field is refused, not passed on', () => {
	// The old route interpolated this into an ffmpeg command string.
	assert.throws(
		() => boundedNumber( '1; rm -rf /', 0, 86400, 'start' ),
		/start must be a number/
	);
	assert.throws( () => boundedDimension( '1920; whoami', 'width' ), /width must be a number/ );
} );

test( 'a dimension is rounded and bounded', () => {
	assert.equal( boundedDimension( '1280.6', 'width' ), 1281 );
	assert.equal( boundedDimension( 8192, 'width' ), 8192 );
	assert.throws( () => boundedDimension( 8193, 'width' ), /between 1 and 8192/ );
	assert.throws( () => boundedDimension( 0, 'width' ), /between 1 and 8192/ );
} );

test( 'the output format is an allowlist, because it names a file', () => {
	assert.equal( resolveFormat( 'MP4' ), 'mp4' );
	assert.equal( resolveFormat( 'jpg' ), 'jpg' );
	assert.equal( resolveFormat( undefined ), 'mp4' );

	// Path traversal: the old route interpolated this straight into a filename.
	assert.equal( resolveFormat( '../../etc/passwd' ), null );
	assert.equal( resolveFormat( 'mp4/../../evil' ), null );
	assert.equal( resolveFormat( 'exe' ), null );
} );

test( 'the format list is small and contains no traversal characters', () => {
	for ( const format of PROCESS_FORMATS ) {
		assert.match( format, /^[a-z0-9]+$/, `${ format } must be a bare extension` );
	}
} );

test( 'a fit is an allowlist of two behaviours', () => {
	assert.equal( resolveFit( 'cover' ), 'cover' );
	assert.equal( resolveFit( 'CONTAIN' ), 'contain' );
	assert.equal( resolveFit( undefined ), 'cover' );
	assert.equal( resolveFit( '' ), 'cover' );

	// The value becomes part of an ffmpeg filter argument, so an unknown one is
	// refused rather than defaulted over.
	assert.equal( resolveFit( 'blur' ), null );
	assert.equal( resolveFit( 'cover; rm -rf /' ), null );
	assert.equal( resolveFit( 'cover,pad=1:1' ), null );
} );

test( 'a rational is divided, never evaluated', () => {
	assert.equal( parseRational( '30000/1001' ).toFixed( 2 ), '29.97' );
	assert.equal( parseRational( '25/1' ), 25 );
	assert.equal( parseRational( '30' ), 30 );

	// The value comes from the uploaded file. This is the string that used to
	// reach `eval`.
	assert.equal( parseRational( 'javascript:process.exit(1)' ), 0 );
	assert.equal( parseRational( '1/0' ), 0 );
	assert.equal( parseRational( '' ), 0 );
} );

test( 'a multipart boolean is read from its text form', () => {
	// Every multipart field arrives as a string, so "false" is truthy unless
	// it is parsed. The old route had no boolean field; this pins the new one.
	assert.equal( boolField( 'false', true ), false );
	assert.equal( boolField( 'FALSE', true ), false );
	assert.equal( boolField( '0', true ), false );
	assert.equal( boolField( 'no', true ), false );
	assert.equal( boolField( 'off', true ), false );

	assert.equal( boolField( 'true', false ), true );
	assert.equal( boolField( '1', false ), true );

	// Absent or empty takes the fallback, so a caller can omit the field.
	assert.equal( boolField( undefined, true ), true );
	assert.equal( boolField( '', false ), false );
} );

test( 'an atempo stage never leaves the filter range', () => {
	for ( const ratio of [ 0.25, 0.3, 0.5, 0.75, 1, 1.5, 2, 4 ] ) {
		for ( const stage of atempoChain( ratio ).split( ',' ) ) {
			const factor = Number( stage.replace( 'atempo=', '' ) );

			assert.match( stage, /^atempo=[0-9.]+$/ );
			assert.ok( factor >= 0.5 && factor <= 100, `${ stage } is outside 0.5–100` );
		}
	}
} );

test( 'an atempo chain multiplies back to the requested factor', () => {
	for ( const ratio of [ 0.25, 0.3, 0.5, 1, 2.5, 4 ] ) {
		const product = atempoChain( ratio )
			.split( ',' )
			.reduce( ( acc, stage ) => acc * Number( stage.replace( 'atempo=', '' ) ), 1 );

		assert.ok( Math.abs( product - ratio ) < 1e-6, `${ ratio } chained to ${ product }` );
	}
} );

test( 'half speed is chained because a single atempo cannot go below 0.5', () => {
	assert.equal( atempoChain( 1 ), 'atempo=1' );
	assert.equal( atempoChain( 2 ), 'atempo=2' );
	assert.equal( atempoChain( 0.25 ), 'atempo=0.5,atempo=0.5' );
	assert.equal( atempoChain( 0.3 ), 'atempo=0.5,atempo=0.6' );
} );

test( 'a speed outside the tool range is refused with the field name', () => {
	assert.equal( boundedNumber( '2.5', 0.25, 4, 'speed' ), 2.5 );
	assert.equal( boundedNumber( undefined, 0.25, 4, 'speed' ), null );

	assert.throws( () => boundedNumber( 0.1, 0.25, 4, 'speed' ), /speed must be a number between 0.25 and 4/ );
	assert.throws( () => boundedNumber( 5, 0.25, 4, 'speed' ), /speed must be a number/ );
	assert.throws( () => boundedNumber( '2; rm -rf /', 0.25, 4, 'speed' ), /speed must be a number/ );
} );
