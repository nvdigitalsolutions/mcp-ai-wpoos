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
	resolveTransition,
	parseRational,
	boolField,
	atempoChain,
	resolveCodec,
	resolveBitrate,
	resolvePosition,
	positionExpression,
	firstDifference,
	mergeMismatch,
	xfadeOffsets,
	videoEncoder,
	VIDEO_CODECS,
	AUDIO_CODECS,
	PROCESS_FORMATS,
	XFADE,
	TRANSITIONS,
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

test( 'a merge compares every property a copy cannot change', () => {
	const base = {
		width: 640,
		height: 360,
		fps: 25,
		vcodec: 'h264',
		hasAudio: true,
		acodec: 'aac',
		sampleRate: 48000,
	};

	assert.equal( firstDifference( base, { ...base } ), null );

	assert.match( firstDifference( base, { ...base, width: 1280 } ), /frame size/ );
	assert.match( firstDifference( base, { ...base, fps: 30 } ), /frame rate/ );
	assert.match( firstDifference( base, { ...base, vcodec: 'hevc' } ), /video codec/ );
	assert.match( firstDifference( base, { ...base, hasAudio: false } ), /no audio/ );
	assert.match( firstDifference( base, { ...base, acodec: 'opus' } ), /audio codec/ );
	assert.match( firstDifference( base, { ...base, sampleRate: 44100 } ), /sample rate/ );
	assert.match( firstDifference( base, null ), /could not be read/ );
} );

test( 'a missing audio stream counts as a mismatch, because the demuxer would not fail on it', () => {
	const silent = { width: 640, height: 360, fps: 25, vcodec: 'h264', hasAudio: false, acodec: '', sampleRate: 0 };
	const loud = { ...silent, hasAudio: true, acodec: 'aac', sampleRate: 48000 };

	assert.match( firstDifference( silent, loud ), /with audio/ );
	assert.match( firstDifference( loud, silent ), /no audio/ );
} );

test( 'the mismatched clip is named by its position', () => {
	const base = { width: 640, height: 360, fps: 25, vcodec: 'h264', hasAudio: false, acodec: '', sampleRate: 0 };
	const same = { ...base };
	const taller = { ...base, height: 480 };

	assert.equal( mergeMismatch( [ base, same, same ] ), null );
	assert.equal(
		mergeMismatch( [ base, same, taller ] ),
		'clip 3 differs from clip 1: frame size (640x360 vs 640x480)'
	);
} );

test( 'a watermark position is an allowlist', () => {
	assert.equal( resolvePosition( 'top-left' ), 'top-left' );
	assert.equal( resolvePosition( 'BOTTOM-RIGHT' ), 'bottom-right' );
	assert.equal( resolvePosition( undefined ), 'bottom-right' );
	assert.equal( resolvePosition( '' ), 'bottom-right' );

	// The value becomes part of an overlay expression.
	assert.equal( resolvePosition( 'middle' ), null );
	assert.equal( resolvePosition( 'x=0:y=0' ), null );
} );

test( 'each corner gets its own overlay expression', () => {
	const margin = 20;

	assert.equal( positionExpression( 'top-left', margin ), 'x=20:y=20' );
	assert.equal( positionExpression( 'top-right', margin ), 'x=main_w-overlay_w-20:y=20' );
	assert.equal( positionExpression( 'bottom-left', margin ), 'x=20:y=main_h-overlay_h-20' );
	assert.equal(
		positionExpression( 'bottom-right', margin ),
		'x=main_w-overlay_w-20:y=main_h-overlay_h-20'
	);
	assert.equal( positionExpression( 'center', margin ), 'x=(main_w-overlay_w)/2:y=(main_h-overlay_h)/2' );

	// All five must differ, or a caller asking for one corner silently gets another.
	const expressions = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ].map(
		( position ) => positionExpression( position, margin )
	);
	assert.equal( new Set( expressions ).size, 5 );
} );

test( 'a codec name is an allowlist, not a passthrough', () => {
	assert.equal( resolveCodec( 'libx264', VIDEO_CODECS, 'video_codec' ), 'libx264' );
	assert.equal( resolveCodec( 'LIBX265', VIDEO_CODECS, 'video_codec' ), 'libx265' );
	assert.equal( resolveCodec( 'libvpx-vp9', VIDEO_CODECS, 'video_codec' ), 'libvpx-vp9' );
	assert.equal( resolveCodec( undefined, VIDEO_CODECS, 'video_codec' ), null );
	assert.equal( resolveCodec( '', VIDEO_CODECS, 'video_codec' ), null );

	// The value reaches `-c:v`, so an unknown one is refused rather than relayed.
	assert.throws( () => resolveCodec( 'h264', VIDEO_CODECS, 'video_codec' ), /must be one of/ );
	assert.throws( () => resolveCodec( 'libx264 -y', VIDEO_CODECS, 'video_codec' ), /must be one of/ );
	assert.throws( () => resolveCodec( 'aac', VIDEO_CODECS, 'video_codec' ), /must be one of/ );

	assert.equal( resolveCodec( 'aac', AUDIO_CODECS, 'audio_codec' ), 'aac' );
	assert.throws( () => resolveCodec( 'libx264', AUDIO_CODECS, 'audio_codec' ), /must be one of/ );
} );

test( 'a bitrate is shaped, so it cannot arrive as a flag', () => {
	assert.equal( resolveBitrate( '2000k', 'video_bitrate' ), '2000k' );
	assert.equal( resolveBitrate( '5M', 'video_bitrate' ), '5m' );
	assert.equal( resolveBitrate( 2000, 'video_bitrate' ), '2000' );
	assert.equal( resolveBitrate( undefined, 'video_bitrate' ), null );
	assert.equal( resolveBitrate( '', 'video_bitrate' ), null );

	// The shape that would be read as another ffmpeg option.
	assert.throws( () => resolveBitrate( '-y', 'video_bitrate' ), /must look like/ );
	assert.throws( () => resolveBitrate( '2000k -y', 'video_bitrate' ), /must look like/ );
	assert.throws( () => resolveBitrate( '2.5m', 'video_bitrate' ), /must look like/ );
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

// ── Transitions ─────────────────────────────────────────────
//
// The offsets are the whole difficulty: `offset` is measured on the first
// input's timeline, and after chaining the first input is the previous result.

test( 'each xfade offset is the running sum less every overlap so far', () => {
	// Verified against ffmpeg 8.1.2: 2 + 3 + 4 s with a 0.5 s transition is an
	// 8.000 s result. The published formula is the same one.
	assert.deepEqual( xfadeOffsets( [ 2, 3, 4 ], 0.5 ), [ 1.5, 4 ] );

	// 10 + 10 + 10 with a 1 s transition: 9, then 18, and 29 s of output.
	assert.deepEqual( xfadeOffsets( [ 10, 10, 10 ], 1 ), [ 9, 18 ] );
} );

test( 'a chain of two clips has a single offset', () => {
	assert.deepEqual( xfadeOffsets( [ 5, 5 ], 2 ), [ 3 ] );
} );

test( 'a single clip has no boundary, so no offset', () => {
	assert.deepEqual( xfadeOffsets( [ 5 ], 1 ), [] );
	assert.deepEqual( xfadeOffsets( [], 1 ), [] );
} );

test( 'a clip shorter than the transition is reported, not clamped', () => {
	// The cut would have to start before the clip has a frame.
	assert.equal( xfadeOffsets( [ 1.5, 10 ], 2 ), null );
	assert.equal( xfadeOffsets( [ 0.2, 0.2, 10 ], 1 ), null );

	// Only the clips *before* a boundary enter its offset, so a long trailing
	// clip does not rescue a short opener — but a short trailing clip is not an
	// error either, because nothing is placed on it. The route refuses the
	// former with `transition_duration >= shortest`, and this is the second
	// line of that defence.
	assert.deepEqual( xfadeOffsets( [ 10, 0.1 ], 1 ), [ 9 ] );

	// A zero transition is not a transition; the route bounds it above zero, and
	// the arithmetic stays sane even if it did not.
	assert.deepEqual( xfadeOffsets( [ 2, 2 ], 0 ), [ 2 ] );
} );

test( 'offsets are rounded so float artefacts never reach the filter argument', () => {
	const offsets = xfadeOffsets( [ 0.1, 0.2, 0.3 ], 0.05 );

	assert.deepEqual( offsets, [ 0.05, 0.2 ] );

	for ( const offset of offsets ) {
		assert.match( String( offset ), /^\d+(\.\d{1,3})?$/ );
	}
} );

test( 'a transition name is an allowlist per operation', () => {
	assert.equal( resolveTransition( undefined, 'merge' ), 'none' );
	assert.equal( resolveTransition( '', 'images' ), 'none' );
	assert.equal( resolveTransition( 'FADE', 'merge' ), 'fade' );
	assert.equal( resolveTransition( ' dissolve ', 'merge' ), 'dissolve' );
	assert.equal( resolveTransition( 'slide', 'images' ), 'slide' );
} );

test( 'a transition one operation does not offer is refused there', () => {
	// `slide` is a slideshow transition; a merge has no such enum member.
	assert.throws( () => resolveTransition( 'slide', 'merge' ), /none, fade, dissolve/ );
	assert.throws( () => resolveTransition( 'dissolve', 'images' ), /none, fade, slide/ );

	// The WordPress enum lists `zoom`; it is `zoompan`, not an xfade.
	assert.throws( () => resolveTransition( 'zoom', 'images' ), /zoom/ );

	// The value reaches a filter argument, so an injection attempt is refused.
	assert.throws( () => resolveTransition( 'fade; rm -rf /', 'merge' ), /must be one of/ );
	assert.throws( () => resolveTransition( 'fade=1', 'merge' ), /must be one of/ );
} );

test( 'the transition table only maps names ffmpeg knows', () => {
	// `slide` is the tool's word; ffmpeg calls the filter `slideleft`.
	assert.equal( XFADE.slide, 'slideleft' );
	assert.equal( XFADE.fade, 'fade' );
	assert.equal( XFADE.dissolve, 'dissolve' );
	assert.equal( XFADE.zoom, undefined, 'zoom is zoompan, not a transition' );
} );

test( 'no operation offers a transition that cannot be mapped', () => {
	for ( const [ operation, names ] of Object.entries( TRANSITIONS ) ) {
		for ( const name of names ) {
			if ( 'none' === name ) {
				continue;
			}

			assert.ok( XFADE[ name ], `${ operation } offers ${ name } but XFADE has no filter for it` );
		}
	}
} );

test( 'the encoder follows the container, because H.264 cannot go in webm', () => {
	assert.deepEqual( videoEncoder( 'mp4' ), [ '-c:v', 'libx264', '-crf', '20' ] );
	assert.deepEqual( videoEncoder( 'mov' ), [ '-c:v', 'libx264', '-crf', '20' ] );
	assert.deepEqual( videoEncoder( 'mkv' ), [ '-c:v', 'libx264', '-crf', '20' ] );

	const webm = videoEncoder( 'webm' );

	assert.equal( webm[ 1 ], 'libvpx-vp9' );
	// Constant quality: a `-crf` without `-b:v 0` is ignored by libvpx.
	assert.ok( webm.includes( '-b:v' ), 'VP9 needs -b:v 0 for CRF to mean anything' );
} );
