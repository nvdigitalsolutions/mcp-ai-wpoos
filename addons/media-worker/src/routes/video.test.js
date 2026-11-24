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
	parseRational,
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
