import { Router } from 'express';
import multer from 'multer';
import { execFile } from 'child_process';
import { promisify } from 'util';
import path from 'path';
import fs from 'fs';
import axios from 'axios';
import { detectCapabilities } from '../utils/capabilities.js';
import { siteUploadDir, siteDirFor } from '../utils/site-paths.js';

const execFileAsync = promisify( execFile );
const router = Router();

// ── Bounds for the ffmpeg argument list ───────────────────
// This route used to build one shell string from request fields, so a `start`
// of `1; rm -rf /` was a command. Every argument is now an argv element handed
// to execFile, which never involves a shell; the values are still bounded so a
// malformed one is a 400 with a reason rather than an ffmpeg stack trace.
const PROCESS_FORMATS = new Set( [ 'mp4', 'webm', 'mov', 'mkv', 'gif', 'mp3', 'm4a', 'aac', 'jpg', 'jpeg', 'png', 'avi', 'flv' ] );
const MAX_DIMENSION = 8192;
const MAX_SECONDS = 24 * 60 * 60;
// Matches the `adjust_video_speed` tool's range, so the route refuses exactly
// what the tool refuses and a caller sees one consistent boundary.
const MIN_SPEED = 0.25;
const MAX_SPEED = 4;
// Encoders an agent may name. Anything outside these lists is refused rather
// than passed to ffmpeg as an `-c:v` argument.
const VIDEO_CODECS = new Set( [ 'libx264', 'libx265', 'libvpx', 'libvpx-vp9' ] );
const AUDIO_CODECS = new Set( [ 'aac', 'libmp3lame', 'libvorbis', 'libopus' ] );
// Which containers accept a stream untouched. A merge copies, so the output
// has to be one that already speaks the source codec.
const COPY_CONTAINERS = {
	h264: [ 'mp4', 'mov', 'mkv' ],
	hevc: [ 'mp4', 'mov', 'mkv' ],
	vp8: [ 'webm', 'mkv' ],
	vp9: [ 'webm', 'mkv' ],
	av1: [ 'mp4', 'webm', 'mkv' ],
};
// Without `-movflags +faststart` the moov atom lands at the end of the file and
// a browser must download the whole thing before the first frame paints.
const FASTSTART_FORMATS = new Set( [ 'mp4', 'mov' ] );
// A transition name is interpolated into a filter argument, so it is looked up
// in a table and never relayed. `slide` is the tool's name for what ffmpeg
// calls `slideleft`. This is the **boundary** table: every key here is a filter
// that joins two inputs, and its absence is what distinguishes a boundary
// transition from `zoom`, which is motion applied inside one still.
const XFADE = {
	fade: 'fade',
	dissolve: 'dissolve',
	slide: 'slideleft',
};
// The WordPress tools declare these enums, so the route refuses exactly what
// the tool refuses. `merge` copies by default, so its only transitions are the
// two that re-encode; `images` has no copy path at all. `zoom` is offered on
// `images` only: it is Ken Burns motion, which has no counterpart on a merge.
const TRANSITIONS = {
	merge: [ 'none', 'fade', 'dissolve' ],
	images: [ 'none', 'fade', 'slide', 'zoom' ],
};
// The WordPress `merge_videos` schema declares a 1 s default. `images` has no
// transition-duration parameter, so a shorter built-in suits stills that may be
// shown for only a second or two.
const DEFAULT_MERGE_TRANSITION_SECONDS = 1;
const IMAGES_TRANSITION_SECONDS = 0.5;
const MAX_TRANSITION_SECONDS = 5;
// Ken Burns motion. `zoompan` works in integer source pixels, so a slow zoom on
// a source the same size as the output steps a whole pixel at a time and
// shimmers. Upscaling the branch before the filter makes those steps a fraction
// of an output pixel. 12% over a still is the usual documentary drift; more
// than that reads as a push rather than a move.
const KEN_BURNS_MAX_ZOOM = 1.12;
const KEN_BURNS_UPSCALE_BUDGET = 8500000;
// Each extracted frame is its own ffmpeg invocation, so the list is bounded.
const MAX_EXTRACT_FRAMES = 120;

/**
 * Parse an optional bounded number from a request field.
 *
 * @param {*}      value Raw field value.
 * @param {number} min   Inclusive minimum.
 * @param {number} max   Inclusive maximum.
 * @param {string} label Field name for the message.
 * @return {number|null} The number, or null when the field was absent.
 * @throws {Error} When the value is present and out of range.
 */
function boundedNumber( value, min, max, label ) {
	if ( undefined === value || null === value || '' === value ) {
		return null;
	}

	const parsed = Number( value );

	if ( ! Number.isFinite( parsed ) || parsed < min || parsed > max ) {
		throw new Error( `${ label } must be a number between ${ min } and ${ max }.` );
	}

	return parsed;
}

/**
 * @param {*}      value Raw field value.
 * @param {string} label Field name.
 * @return {number|null}
 */
function boundedDimension( value, label ) {
	const parsed = boundedNumber( value, 1, MAX_DIMENSION, label );

	return null === parsed ? null : Math.round( parsed );
}

/**
 * Allowlist the output format.
 *
 * The value names the output file, so an unchecked one is a path traversal
 * (`../../evil`) as well as an unknown encoder.
 *
 * @param {*} value Raw field value.
 * @return {string|null} A known format, or null.
 */
function resolveFormat( value ) {
	const slug = String( value || 'mp4' ).toLowerCase();

	return PROCESS_FORMATS.has( slug ) ? slug : null;
}

/**
 * Allowlist a codec name.
 *
 * The value ends up in `-c:v` / `-c:a`, so an unknown one is not a typo to
 * relay — it is an argument. Absent is null (the route keeps the source
 * codec); unknown is refused.
 *
 * @param {*}          value   Raw field value.
 * @param {Set<string>} allowed Permitted names.
 * @param {string}     label   Field name for the message.
 * @return {string|null}
 * @throws {Error} When present and not allowed.
 */
function resolveCodec( value, allowed, label ) {
	if ( undefined === value || null === value || '' === value ) {
		return null;
	}

	const slug = String( value ).trim().toLowerCase();

	if ( ! allowed.has( slug ) ) {
		throw new Error( `${ label } must be one of ${ [ ...allowed ].join( ', ' ) }.` );
	}

	return slug;
}

/**
 * Allowlist a bitrate such as `2000k` or `5m`.
 *
 * A permissive string here would reach ffmpeg as an argument value, so the
 * shape is fixed: digits with an optional k/m suffix and nothing else. A
 * leading dash — the shape that could be read as another flag — cannot match.
 *
 * @param {*}      value Raw field value.
 * @param {string} label Field name for the message.
 * @return {string|null}
 * @throws {Error} When present and malformed.
 */
function resolveBitrate( value, label ) {
	if ( undefined === value || null === value || '' === value ) {
		return null;
	}

	const slug = String( value ).trim().toLowerCase();

	if ( ! /^[0-9]{1,6}[km]?$/.test( slug ) ) {
		throw new Error( `${ label } must look like 2000k or 5m.` );
	}

	return slug;
}

/**
 * Allowlist how a fitted frame is filled.
 *
 * `cover` scales until both sides are covered and then crops the overflow, so
 * the whole frame is picture. `contain` scales until the video fits and pads
 * the remainder, so nothing is cropped but there are bars. There is no third
 * behaviour worth guessing at, so an unknown value is refused rather than
 * defaulted.
 *
 * @param {*} value Raw field value.
 * @return {string|null} `cover`, `contain`, or null when unknown.
 */
function resolveFit( value ) {
	const slug = String( value || 'cover' ).toLowerCase();

	return [ 'cover', 'contain' ].includes( slug ) ? slug : null;
}

/**
 * Allowlist a transition per operation.
 *
 * An absent field means `none`, which is the operation's copy/plain path. A
 * value outside the operation's list is refused rather than defaulted, because
 * the name selects a filter argument: `zoom` on a merge has no meaning, and
 * silently fading instead would be a different video from the one asked for.
 *
 * @param {*}      value     Raw field value.
 * @param {string} operation `merge` or `images`.
 * @return {string} The transition name.
 * @throws {Error} When the name is not offered by that operation.
 */
function resolveTransition( value, operation ) {
	const allowed = TRANSITIONS[ operation ] || [ 'none' ];
	const slug = String( value ?? 'none' ).trim().toLowerCase();

	if ( ! slug ) {
		return 'none';
	}

	if ( ! allowed.includes( slug ) ) {
		throw new Error( `transition must be one of ${ allowed.join( ', ' ) } for ${ operation }; got "${ slug }".` );
	}

	return slug;
}

/**
 * Evaluate an ffprobe rational such as `30000/1001` without `eval`.
 *
 * The value comes from the uploaded file, so it is untrusted text: `eval` here
 * was remote code execution reachable by any file whose `r_frame_rate` metadata
 * contained JavaScript.
 *
 * @param {*} value Rational string from ffprobe.
 * @return {number} Frames per second, or 0 when it cannot be read.
 */
function parseRational( value ) {
	const parts = String( value || '' ).split( '/' );
	const numerator = Number( parts[ 0 ] );
	const denominator = undefined === parts[ 1 ] ? 1 : Number( parts[ 1 ] );

	if ( ! Number.isFinite( numerator ) || ! Number.isFinite( denominator ) || 0 === denominator ) {
		return 0;
	}

	return numerator / denominator;
}

/**
 * Read a boolean from a multipart text field.
 *
 * Multipart bodies carry every field as a string, so `false` arrives as the
 * text "false" — which is truthy in JavaScript. Only the four spellings a
 * caller might send for "no" are read as false; anything else (including an
 * absent field) takes the fallback.
 *
 * @param {*}      value    Raw field value.
 * @param {boolean} fallback Value when the field is absent or empty.
 * @return {boolean}
 */
function boolField( value, fallback ) {
	if ( undefined === value || null === value || '' === value ) {
		return fallback;
	}

	return ! [ 'false', '0', 'no', 'off' ].includes( String( value ).trim().toLowerCase() );
}

/**
 * Build an `atempo` chain that multiplies to the requested factor.
 *
 * `atempo` accepts 0.5–100 per instance on ffmpeg 4.1+, and 0.5–2.0 before
 * that. Chaining by 0.5 downwards is valid on both, so a 0.25× request becomes
 * two stages rather than a silent refusal on an older binary. `atempo`
 * preserves pitch, which is what makes it the right filter here.
 *
 * @param {number} ratio Speed multiplier, 0.25–4.
 * @return {string} Comma-separated filter stages.
 */
function atempoChain( ratio ) {
	const stages = [];
	let remaining = ratio;

	while ( remaining < 0.5 ) {
		stages.push( 'atempo=0.5' );
		remaining /= 0.5;
	}

	while ( remaining > 100 ) {
		stages.push( 'atempo=100' );
		remaining /= 100;
	}

	stages.push( `atempo=${ Number( remaining.toFixed( 6 ) ) }` );

	return stages.join( ',' );
}

/**
 * The first audio stream's sample rate, or null when the file has no audio.
 *
 * `setpts` needs no probe, but `-af` fails outright on a file with no audio
 * stream, and the sample rate is only needed when the caller asks *not* to
 * keep the pitch. One ffprobe answers both questions.
 *
 * @param {string} inputPath Uploaded file.
 * @return {Promise<number|null>} Sample rate in Hz, or null.
 */
async function probeAudioStream( inputPath ) {
	try {
		const { stdout } = await execFileAsync(
			'ffprobe',
			[
				'-v', 'quiet',
				'-print_format', 'json',
				'-select_streams', 'a:0',
				'-show_entries', 'stream=sample_rate',
				inputPath,
			],
			{ timeout: 30000 }
		);
		const rate = parseInt( JSON.parse( stdout )?.streams?.[ 0 ]?.sample_rate, 10 );

		return Number.isFinite( rate ) && rate > 0 ? rate : null;
	} catch {
		return null;
	}
}

/**
 * Allowlist a watermark position.
 *
 * @param {*} value Raw field value.
 * @return {string|null} A known position, or null when unknown.
 */
function resolvePosition( value ) {
	const slug = String( value || 'bottom-right' ).toLowerCase();

	return [ 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ].includes( slug )
		? slug
		: null;
}

/**
 * The `overlay` x/y expression for a position.
 *
 * `main_w` / `main_h` are the video's dimensions and `overlay_w` /
 * `overlay_h` the mark's, so the expression stays correct whatever the two
 * happen to be. Only the gutter is computed here, which is why a 4K source and
 * a 720p one get the same visual margin.
 *
 * @param {string} position Known position.
 * @param {number} margin Gutter in pixels.
 * @return {string} `x=…:y=…`
 */
function positionExpression( position, margin ) {
	switch ( position ) {
		case 'top-left':
			return `x=${ margin }:y=${ margin }`;
		case 'top-right':
			return `x=main_w-overlay_w-${ margin }:y=${ margin }`;
		case 'bottom-left':
			return `x=${ margin }:y=main_h-overlay_h-${ margin }`;
		case 'center':
			return 'x=(main_w-overlay_w)/2:y=(main_h-overlay_h)/2';
		case 'bottom-right':
		default:
			return `x=main_w-overlay_w-${ margin }:y=main_h-overlay_h-${ margin }`;
	}
}

/**
 * Probe the properties a copy-based merge has to agree on.
 *
 * @param {string} inputPath Uploaded file.
 * @return {Promise<object|null>}
 */
async function probeMedia( inputPath ) {
	try {
		const { stdout } = await execFileAsync(
			'ffprobe',
			[ '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format', inputPath ],
			{ timeout: 30000 }
		);
		const parsed = JSON.parse( stdout );
		const streams = Array.isArray( parsed?.streams ) ? parsed.streams : [];
		const video = streams.find( ( stream ) => 'video' === stream.codec_type );
		const audio = streams.find( ( stream ) => 'audio' === stream.codec_type );

		if ( ! video ) {
			return null;
		}

		return {
			width: parseInt( video.width, 10 ) || 0,
			height: parseInt( video.height, 10 ) || 0,
			fps: parseRational( video.r_frame_rate ),
			vcodec: String( video.codec_name || '' ),
			hasAudio: Boolean( audio ),
			acodec: audio ? String( audio.codec_name || '' ) : '',
			sampleRate: audio ? parseInt( audio.sample_rate, 10 ) || 0 : 0,
			// A transition cut is placed by seconds, so the probe has to carry
			// one. `format.duration` is the container's own figure and the only
			// one already in seconds; a stream's `duration` is in its time base,
			// which is not.
			duration: parseFloat( parsed?.format?.duration ) || 0,
		};
	} catch {
		return null;
	}
}

/**
 * The first property two clips disagree about, or null when they match.
 *
 * This is not a convenience check. The concat demuxer does **not** fail on a
 * mismatch: joining a 25 fps clip to a 30 fps one under `-c copy` produced a
 * 4.8 s file instead of 5.0 s and still reported 25/1. So this comparison is
 * what stops a corrupt merge being answered as a success.
 *
 * @param {object|null} a First clip's probe.
 * @param {object|null} b Another clip's probe.
 * @return {string|null}
 */
function firstDifference( a, b ) {
	if ( ! a || ! b ) {
		return 'a clip whose video stream could not be read';
	}

	if ( a.width !== b.width || a.height !== b.height ) {
		return `frame size (${ a.width }x${ a.height } vs ${ b.width }x${ b.height })`;
	}

	if ( Math.abs( a.fps - b.fps ) > 0.01 ) {
		return `frame rate (${ a.fps.toFixed( 2 ) } vs ${ b.fps.toFixed( 2 ) })`;
	}

	if ( a.vcodec !== b.vcodec ) {
		return `video codec (${ a.vcodec } vs ${ b.vcodec })`;
	}

	if ( a.hasAudio !== b.hasAudio ) {
		return a.hasAudio ? 'a clip with no audio' : 'a clip with audio';
	}

	if ( a.hasAudio && b.hasAudio ) {
		if ( a.acodec !== b.acodec ) {
			return `audio codec (${ a.acodec } vs ${ b.acodec })`;
		}

		if ( a.sampleRate !== b.sampleRate ) {
			return `sample rate (${ a.sampleRate } vs ${ b.sampleRate })`;
		}
	}

	return null;
}

/**
 * The first clip that differs from the first, described for the caller.
 *
 * @param {Array<object|null>} probes
 * @return {string|null}
 */
function mergeMismatch( probes ) {
	for ( let index = 1; index < probes.length; index++ ) {
		const difference = firstDifference( probes[ 0 ], probes[ index ] );

		if ( difference ) {
			return `clip ${ index + 1 } differs from clip 1: ${ difference }`;
		}
	}

	return null;
}

/**
 * The `offset` of each xfade in a chain, or null when they would overlap.
 *
 * `offset` is measured on the **first** input's timeline, and after chaining the
 * first input is the previous result — so each offset is the running sum of the
 * clips behind it, less the overlap every earlier transition already removed:
 *
 *     offset_i = (d₁ + … + d_i) − i × T
 *
 * Verified against ffmpeg 8.1.2: clips of 2 + 3 + 4 s with T = 0.5 give
 * `[ 1.5, 4 ]`, and the encoded result is 8.000000 s — the 9 s of input less
 * the two half-second overlaps. With audio the container reads 8.024 s, because
 * AAC primes its first frame.
 *
 * A non-positive offset means a clip is shorter than the transition: the cross-
 * fade would have to start before that clip has a frame, so the chain would
 * either fail or silently drop it. That is reported rather than clamped.
 *
 * @param {number[]} durations          Clip lengths, in order.
 * @param {number}   transitionDuration Overlap per boundary.
 * @return {number[]|null} One offset per boundary, or null on overlap.
 */
function xfadeOffsets( durations, transitionDuration ) {
	const offsets = [];
	let elapsed = 0;

	for ( let index = 0; index < durations.length - 1; index++ ) {
		elapsed += durations[ index ];
		const offset = elapsed - ( index + 1 ) * transitionDuration;

		if ( ! Number.isFinite( offset ) || offset <= 0 ) {
			return null;
		}

		// Three decimals is one frame at 1000 fps and keeps `0.1 + 0.2`
		// artefacts out of the filter argument.
		offsets.push( Number( offset.toFixed( 3 ) ) );
	}

	return offsets;
}

/**
 * The encoder arguments that suit a container.
 *
 * The transition paths re-encode, so — unlike the copy path — they both choose
 * an encoder rather than inheriting one. H.264 is the default; VP9 is the only
 * one of these that a WebM container accepts.
 *
 * @param {string} format Resolved output format.
 * @return {string[]} Arguments for `-c:v` and its rate control.
 */
function videoEncoder( format ) {
	return 'webm' === format
		? [ '-c:v', 'libvpx-vp9', '-crf', '32', '-b:v', '0', '-row-mt', '1' ]
		: [ '-c:v', 'libx264', '-crf', '20' ];
}

/**
 * The timestamps an `extract_frames` run will seek to.
 *
 * Three modes, in precedence order.
 *
 *  - Explicit `timestamps` win outright: a caller who named the moments meant
 *    them.
 *  - A regular `interval` needs the duration, so it is only usable when ffprobe
 *    answered one. It starts at 0 — a contact sheet wants the first frame — and
 *    stops before the duration, because a seek past the end is an ffmpeg error
 *    rather than an empty frame.
 *  - Otherwise nothing is returned, and the caller spreads `count` frames across
 *    the duration with the `fps` filter in one pass.
 *
 * Every stamp is its own ffmpeg invocation, so the list is capped. The cap is
 * the same 120 the count mode already allowed.
 *
 * @param {object}      options
 * @param {number[]}    options.timestamps Explicit seconds.
 * @param {number|null} options.interval   Seconds between frames.
 * @param {number}      options.duration   Source length in seconds, 0 if unknown.
 * @return {number[]} Ordered, de-duplicated timestamps, or an empty list.
 */
function frameTimestamps( { timestamps = [], interval = null, duration = 0 } = {} ) {
	const explicit = timestamps
		.map( Number )
		.filter( ( value ) => Number.isFinite( value ) && value >= 0 )
		.slice( 0, MAX_EXTRACT_FRAMES );

	if ( explicit.length ) {
		return explicit;
	}

	if ( ! ( interval > 0 ) || ! ( duration > 0 ) ) {
		return [];
	}

	const stamps = [];

	for ( let index = 0; index * interval < duration && stamps.length < MAX_EXTRACT_FRAMES; index++ ) {
		// Multiplication rather than accumulation: 0.1 + 0.2 + 0.3 drifts, and
		// a seek time that drifts is a frame at the wrong moment.
		stamps.push( Number( ( index * interval ).toFixed( 3 ) ) );
	}

	return stamps;
}

/**
 * The audio codec an extraction run should use.
 *
 * A copy is lossless, so it is chosen whenever the target container already
 * speaks the source codec — AAC into `m4a`/`aac`, MP3 into `mp3`. Re-encoding an
 * AAC track into an m4a costs a generation for nothing, which is the whole bug
 * this rule exists to avoid.
 *
 * An unreadable or unknown source codec falls through to an encode rather than
 * to a copy ffmpeg would reject.
 *
 * @param {string} format      Resolved container: mp3, m4a or aac.
 * @param {string} sourceCodec The first audio stream's `codec_name`, or ''.
 * @return {{codec: string, copy: boolean}} An ffmpeg `-c:a` value.
 */
function audioExtractionCodec( format, sourceCodec ) {
	const wanted = 'mp3' === format ? 'mp3' : 'aac';

	if ( String( sourceCodec || '' ) === wanted ) {
		return { codec: 'copy', copy: true };
	}

	return { codec: 'mp3' === wanted ? 'libmp3lame' : 'aac', copy: false };
}

/**
 * How much a branch is upscaled before `zoompan`, as an integer factor.
 *
 * `zoompan` computes `x` and `y` in integer source pixels, so a source the same
 * size as the output moves a whole output pixel at a time and shimmers. A
 * doubled source halves every step. The budget stops a 4K still being doubled
 * into a 33-megapixel intermediate for no visible gain.
 *
 * @param {number} width  Output width.
 * @param {number} height Output height.
 * @return {number} 2 when the doubling fits the budget, otherwise 1.
 */
function kenBurnsUpscale( width, height ) {
	return 4 * width * height <= KEN_BURNS_UPSCALE_BUDGET ? 2 : 1;
}

/**
 * The `zoompan` filter that drifts one still from 1.0 to KEN_BURNS_MAX_ZOOM.
 *
 * Two traps, both measured on ffmpeg 8.1.2:
 *
 *  - `d` is a frame count per **input** frame. With `-loop 1 -t N` the input
 *    already carries N × fps frames, so `d` has to be 1; anything larger
 *    multiplies the clip's length instead of setting it.
 *  - `zoom` is a per-input-frame accumulator that starts again at 1, so the
 *    usual `zoom + 0.0015` expression never moves on a looped still. The
 *    expression is driven by `on` — the output frame number — instead. Verified:
 *    a 50-frame branch goes 1.000 → 1.0588 → 1.120 (first and last frames
 *    differ at 7.3 dB PSNR, so the drift is real and monotonic).
 *
 * `max(on-1,0)` holds the first frame at exactly 1.0 whichever of 0 or 1 `on`
 * starts at, and `min()` removes the overshoot if it starts at 1.
 *
 * @param {number} width     Output width.
 * @param {number} height    Output height.
 * @param {number} frameRate Output frames per second.
 * @param {number} seconds   How long the still is shown.
 * @return {string} A `zoompan=…` filter, quoted for `-filter_complex`.
 */
function kenBurnsZoompan( width, height, frameRate, seconds ) {
	// Two frames is the floor: one frame has no motion, and `frames - 1` is a
	// denominator.
	const frames = Math.max( 2, Math.round( frameRate * seconds ) );
	const step = ( KEN_BURNS_MAX_ZOOM - 1 ).toFixed( 4 );

	return (
		`zoompan=z='min(${ KEN_BURNS_MAX_ZOOM },1+${ step }*max(on-1,0)/${ frames - 1 })'` +
		`:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'` +
		`:d=1:s=${ width }x${ height }:fps=${ frameRate }`
	);
}

/**
 * The first audio stream's codec name.
 *
 * Three answers, because they mean three different things:
 *
 *  - a name (`aac`, `mp3`) — the stream exists and can be copied into a
 *    container that speaks it;
 *  - `''` — the probe succeeded and there is **no** audio stream, which is a
 *    400 rather than an ffmpeg failure four steps later;
 *  - `null` — the probe could not be read, so nothing is claimed about the file.
 *
 * `probeMedia()` cannot answer this: it returns null for a source with no video
 * stream, and an audio-only file is a legitimate input here.
 *
 * @param {string} inputPath Uploaded file.
 * @return {Promise<string|null>}
 */
async function probeAudioCodec( inputPath ) {
	try {
		const { stdout } = await execFileAsync(
			'ffprobe',
			[
				'-v', 'quiet',
				'-print_format', 'json',
				'-select_streams', 'a:0',
				'-show_entries', 'stream=codec_name',
				inputPath,
			],
			{ timeout: 30000 }
		);
		const streams = JSON.parse( stdout )?.streams;

		return Array.isArray( streams ) ? String( streams[ 0 ]?.codec_name || '' ) : null;
	} catch {
		return null;
	}
}

/**
 * The first video stream's width, or null.
 *
 * The watermark is a fraction of the video's width, so the pixel size has to
 * be resolved before the graph is built — `overlay` can compute positions from
 * its own inputs, but not the `scale` before it.
 *
 * @param {string} inputPath Uploaded file.
 * @return {Promise<number|null>}
 */
async function probeVideoWidth( inputPath ) {
	try {
		const { stdout } = await execFileAsync(
			'ffprobe',
			[
				'-v', 'quiet',
				'-print_format', 'json',
				'-select_streams', 'v:0',
				'-show_entries', 'stream=width',
				inputPath,
			],
			{ timeout: 30000 }
		);
		const width = parseInt( JSON.parse( stdout )?.streams?.[ 0 ]?.width, 10 );

		return Number.isFinite( width ) && width > 0 ? width : null;
	} catch {
		return null;
	}
}

// Uploads land in the caller's site namespace (multi-tenant) or the system
// temp dir (single-tenant, legacy behavior).
const uploadStorage = multer.diskStorage({
	destination: ( req, _file, cb ) => {
		try {
			cb( null, siteUploadDir( req.site ) );
		} catch ( err ) {
			cb( err );
		}
	},
});

const upload = multer({
	storage: uploadStorage,
	limits: { fileSize: 500 * 1024 * 1024 },
});

// Two inputs today (a video and a watermark), eight allowed so a `merge`
// operation can reuse this endpoint rather than inventing a third contract.
// `files` is explicit: multer caps nothing by default.
const composeUpload = multer({
	storage: uploadStorage,
	limits: { fileSize: 500 * 1024 * 1024, files: 8 },
});

// ── Replicate model version map ──────────────────────────
const REPLICATE_MODELS = {
  'stable-video-diffusion': 'stability-ai/stable-video-diffusion:3f0457e4619daac51203dedb472816fd4af51f3149fa7a9e0b5ffcf1b8172438',
  'zeroscope-v2': 'anotherjesse/zeroscope-v2-xl:9f747673945c62801b13b84701c783929c0ee784e4748ec062204894dda1a351',
  'zeroscope-v2-576': 'anotherjesse/zeroscope-v2-xl:71996d331e8ede8ef7bd76eba9fae076d31792e4ddf4ad057779b443d6aea62f',
  'cogvideox-5b': 'replicate/cogvideox-5b:a57c0f7bb57e1da534a5fd461cfb0af6dd56f2b6b64cadec42827d8047bb6609',
  'cogvideox-2b': 'cjwbw/cogvideox-2b:e5e1ce1d2470cb3ca8e38cbcc561b320f832b5e09db42058a83dbce5bca79469',
  'stable-video-diffusion-xt': 'stability-ai/stable-video-diffusion:3f0457e4619daac51203dedb4728abc123',
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── AI Video Generation ─────────────────────────────────
router.post('/generate', async (req, res) => {
  try {
    const {
      prompt,
      model = 'stable-video-diffusion',
      duration = 5,
      fps = 24,
      negative_prompt,
      seed,
      width = 1024,
      height = 576,
    } = req.body;

    if (!prompt) {
      return res.status(400).json({ error: 'Prompt is required' });
    }

    const modelVersion = REPLICATE_MODELS[model];
    if (!modelVersion) {
      return res.status(400).json({
        error: `Unknown model: ${model}`,
        available: Object.keys(REPLICATE_MODELS),
      });
    }

    if (!process.env.REPLICATE_API_KEY) {
      return res.status(503).json({
        error: 'Replicate API key not configured',
        tip: 'Set REPLICATE_API_KEY in .env',
        fallback_providers: {
          runway: 'https://runwayml.com — Gen-2, Gen-3 Alpha',
          pika: 'https://pika.art — Pika 1.0',
          haiper: 'https://haiper.ai — text-to-video',
        },
      });
    }

    console.log(`[Video Gen] Starting: ${model} — "${prompt.substring(0, 80)}..."`);

    // Create prediction on Replicate
    const input = {
      prompt,
      num_frames: duration * fps,
      fps,
      width,
      height,
    };
    if (negative_prompt) input.negative_prompt = negative_prompt;
    if (seed) input.seed = seed;

    const createResp = await axios.post(
      'https://api.replicate.com/v1/predictions',
      {
        version: modelVersion.split(':')[1],
        input,
      },
      {
        headers: {
          Authorization: `Bearer ${process.env.REPLICATE_API_KEY}`,
          'Content-Type': 'application/json',
        },
        timeout: 30000,
      }
    );

    const predictionId = createResp.data.id;
    let prediction = createResp.data;

    // Poll until complete
    const maxAttempts = 120; // 10 minutes at 5s intervals
    let attempts = 0;

    while (prediction.status !== 'succeeded' && prediction.status !== 'failed' && prediction.status !== 'canceled') {
      if (attempts >= maxAttempts) {
        return res.status(504).json({
          error: 'Video generation timed out',
          prediction_id: predictionId,
          tip: 'Check status at https://replicate.com/p/' + predictionId,
        });
      }

      await sleep(5000);
      attempts++;

      const pollResp = await axios.get(
        `https://api.replicate.com/v1/predictions/${predictionId}`,
        {
          headers: { Authorization: `Bearer ${process.env.REPLICATE_API_KEY}` },
        }
      );
      prediction = pollResp.data;

      console.log(`[Video Gen] ${predictionId}: ${prediction.status} (attempt ${attempts}/${maxAttempts})`);
    }

    if (prediction.status === 'failed') {
      return res.status(502).json({
        error: 'Video generation failed',
        detail: prediction.error,
        prediction_id: predictionId,
      });
    }

    if (prediction.status === 'canceled') {
      return res.status(499).json({
        error: 'Video generation was canceled',
        prediction_id: predictionId,
      });
    }

    // Success — return video URL
    const videoUrl = prediction.output;
    const outputUrl = Array.isArray(videoUrl) ? videoUrl[0] : videoUrl;

    res.json({
      success: true,
      model,
      prompt,
      prediction_id: predictionId,
      video_url: outputUrl,
      duration,
      fps,
      metrics: prediction.metrics,
      created_at: prediction.created_at,
      completed_at: prediction.completed_at,
    });
  } catch (err) {
    console.error('[Video Generate]', err.response?.data || err.message);
    res.status(500).json({
      error: 'Video generation error',
      detail: err.response?.data || err.message,
    });
  }
});

// ── Check prediction status ──────────────────────────────
router.get('/prediction/:id', async (req, res) => {
  try {
    if (!process.env.REPLICATE_API_KEY) {
      return res.status(503).json({ error: 'Replicate API key not configured' });
    }

    const { id } = req.params;
    const resp = await axios.get(`https://api.replicate.com/v1/predictions/${id}`, {
      headers: { Authorization: `Bearer ${process.env.REPLICATE_API_KEY}` },
    });

    const prediction = resp.data;
    res.json({
      id: prediction.id,
      status: prediction.status,
      model: prediction.version,
      video_url: prediction.output,
      error: prediction.error,
      metrics: prediction.metrics,
      created_at: prediction.created_at,
      completed_at: prediction.completed_at,
    });
  } catch (err) {
    console.error('[Video Status]', err.response?.data || err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── List available models ────────────────────────────────
router.get('/models', (_req, res) => {
  res.json({
    models: Object.keys(REPLICATE_MODELS).map((key) => ({
      id: key,
      version: REPLICATE_MODELS[key].split(':')[1],
      provider: 'Replicate',
      type: 'text-to-video',
      configured: !!process.env.REPLICATE_API_KEY,
    })),
    other_providers: {
      runway: { name: 'Runway Gen-3 Alpha', url: 'https://runwayml.com' },
      pika: { name: 'Pika 1.0', url: 'https://pika.art' },
      haiper: { name: 'Haiper AI', url: 'https://haiper.ai' },
      luma: { name: 'Luma Dream Machine', url: 'https://lumalabs.ai' },
      kling: { name: 'Kling AI', url: 'https://klingai.com' },
    },
  });
});

// ── Video Processing (FFmpeg) ─────────────────────────────
router.post('/process', upload.single('file'), async (req, res) => {
  // Unlink the multer temp upload on every path that leaves the handler
  // without processing (idempotent — safe to call repeatedly).
  const cleanupUpload = () => {
    if (req.file) {
      try { fs.unlinkSync(req.file.path); } catch {}
    }
  };
  try {
    const caps = await detectCapabilities();
    if (!caps.ffmpeg) {
      cleanupUpload();
      return res.status(503).json({ error: 'capability_unavailable', capability: 'video', message: 'ffmpeg is not installed on this server.' });
    }

    if (!req.file) {
      return res.status(400).json({ error: 'No file uploaded' });
    }

    const {
      operation = 'compress',
      width: rawWidth,
      height: rawHeight,
      format: rawFormat,
      fps: rawFps,
      start: rawStart,
      duration: rawDuration,
	      timestamps,
	      count,
	      quality: rawQuality,
	      interval: rawInterval,
	      speed: rawSpeed,
	      maintain_pitch: rawMaintainPitch,
	      fit: rawFit,
	      vcodec: rawVcodec,
	      acodec: rawAcodec,
	      video_bitrate: rawVideoBitrate,
	      audio_bitrate: rawAudioBitrate,
	      remove_audio: rawRemoveAudio,
	    } = req.body;
    const inputPath = req.file.path;
    const videoDir = siteDirFor(req.site, 'video');

    let width;
    let height;
    let start;
    let duration;
	    let fps;
	    let format;
	    let speed;
	    let fit;
	    let vcodec;
	    let acodec;
	    let videoBitrate;
	    let audioBitrate;
	    let reencoded = null;

    try {
      width = boundedDimension(rawWidth, 'width');
      height = boundedDimension(rawHeight, 'height');
      start = boundedNumber(rawStart, 0, MAX_SECONDS, 'start');
      duration = boundedNumber(rawDuration, 0.01, MAX_SECONDS, 'duration');
	      fps = boundedNumber(rawFps, 0.1, 120, 'fps');
	      speed = boundedNumber(rawSpeed, MIN_SPEED, MAX_SPEED, 'speed');
	      fit = resolveFit(rawFit);
	      vcodec = resolveCodec(rawVcodec, VIDEO_CODECS, 'video_codec');
	      acodec = resolveCodec(rawAcodec, AUDIO_CODECS, 'audio_codec');
	      videoBitrate = resolveBitrate(rawVideoBitrate, 'video_bitrate');
	      audioBitrate = resolveBitrate(rawAudioBitrate, 'audio_bitrate');
	      format = resolveFormat(rawFormat);
    } catch (argErr) {
      cleanupUpload();
      return res.status(400).json({ error: argErr.message });
    }

    if (!format) {
      cleanupUpload();
      return res.status(400).json({
        error: `Unknown output format: ${rawFormat}`,
        allowed_formats: [...PROCESS_FORMATS],
      });
    }

    const faststart = FASTSTART_FORMATS.has(format);

    // ── extract_frames (multi-output) — handled separately ──
    if ('extract_frames' === operation) {
      const requested = String(timestamps || '')
        .split(',')
        .map((s) => parseFloat(String(s).trim()))
        .filter((n) => Number.isFinite(n) && n >= 0);
      let quality;
      let interval;

      try {
        // The WordPress tool declares both of these, so the route reads them
        // rather than silently overriding them with its own defaults.
        quality = boundedNumber(rawQuality, 1, 31, 'quality') ?? 2;
        interval = boundedNumber(rawInterval, 0.1, 3600, 'interval');
      } catch (argErr) {
        cleanupUpload();
        return res.status(400).json({ error: argErr.message });
      }

      // One probe answers both questions: whether `interval` can expand into
      // stamps, and the rate at which the `count` mode spreads frames.
      const probe = await probeMedia(inputPath);
      const sourceDuration = probe ? probe.duration : 0;
      const stamps = frameTimestamps({ timestamps: requested, interval, duration: sourceDuration });

      const frameDirName = `frames_${Date.now()}`;
      const frameDir = path.join(videoDir, frameDirName);
      fs.mkdirSync(frameDir, { recursive: true });
      const scale = width ? `scale=${parseInt(width, 10)}:-1` : null;
      const files = [];

      if (stamps.length) {
        for (let i = 0; i < stamps.length; i++) {
          const name = `frame_${String(i).padStart(3, '0')}.jpg`;
          const out = path.join(frameDir, name);
          const args = ['-ss', String(stamps[i]), '-i', inputPath, '-frames:v', '1', '-q:v', String(quality)];

          if (scale) {
            args.push('-vf', scale);
          }

          args.push('-y', out);
          await execFileAsync('ffmpeg', args, { timeout: 300000 });
          files.push(`${frameDirName}/${name}`);
        }
      } else {
        const frameCount = Math.max(1, Math.min(MAX_EXTRACT_FRAMES, parseInt(count, 10) || 10));
        const fpsFilter = sourceDuration > 0
          ? `fps=${Math.max(0.01, frameCount / sourceDuration)}`
          : 'fps=1';
        const filters = [fpsFilter, scale].filter(Boolean).join(',');
        const outPattern = path.join(frameDir, 'frame_%03d.jpg');
        await execFileAsync(
          'ffmpeg',
          ['-i', inputPath, '-vf', filters, '-q:v', String(quality), '-y', outPattern],
          { timeout: 300000 }
        );
        for (let i = 1; i <= frameCount; i++) {
          files.push(`${frameDirName}/frame_${String(i).padStart(3, '0')}.jpg`);
        }
      }

      fs.unlinkSync(inputPath);
      // Frames need a longer retrieval window than single outputs.
      setTimeout(() => { try { fs.rmSync(path.join(videoDir, frameDirName), { recursive: true, force: true }); } catch {} }, 5 * 60 * 1000);

      return res.json({
        success: true,
        operation,
        frame_count: files.length,
        source_duration: sourceDuration,
        output_files: files,
      });
    }

    // ── Single-output operations ──
    const outputName = `processed_${Date.now()}.${format}`;
    const outputPath = path.join(videoDir, outputName);

    const args = ['-i', inputPath];

    switch (operation) {
      case 'resize':
        if (!width && !height) {
          cleanupUpload();
          return res.status(400).json({ error: 'Width or height required for resize' });
        }
        if (width && height) {
          args.push('-vf', `scale=${width}:${height}`);
        } else if (width) {
          args.push('-vf', `scale=${width}:-1`);
        } else {
          args.push('-vf', `scale=-1:${height}`);
        }
        break;

	      case 'crop':
	        if (!width || !height) {
	          cleanupUpload();
	          return res.status(400).json({ error: 'Width and height required for crop' });
	        }
	        args.push('-vf', `crop=${width}:${height}`);
	        break;

	      case 'fit': {
	        if (!width || !height) {
	          cleanupUpload();
	          return res.status(400).json({ error: 'Width and height required for fit' });
	        }

	        if (!fit) {
	          cleanupUpload();
	          return res.status(400).json({ error: 'fit must be either cover or contain' });
	        }

	        // `force_original_aspect_ratio` is what makes this different from
	        // `resize`: it scales to the *shorter* side for cover and the
	        // *longer* side for contain, so the output is exactly the frame
	        // asked for instead of one side being rounded to `-1`. `crop` and
	        // `pad` both centre by default.
	        if ('contain' === fit) {
	          args.push(
	            '-vf',
	            `scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2`
	          );
	        } else {
	          args.push(
	            '-vf',
	            `scale=${width}:${height}:force_original_aspect_ratio=increase,crop=${width}:${height}`
	          );
	        }
	        break;
	      }

      case 'trim':
        // Output-side `-ss` is frame-accurate; input-side is faster but lands on
        // the nearest keyframe. A trim that starts a second early is the more
        // surprising failure.
        if (start !== null) {
          args.push('-ss', String(start));
        }
        if (duration !== null) {
          args.push('-t', String(duration));
        }
        break;

      case 'convert':
        // Format change only
        break;

      case 'compress':
        args.push('-vcodec', 'libx264', '-crf', '28', '-preset', 'fast');
        break;

      case 'gif':
        args.push('-vf', `fps=${fps || 12},scale=${width || 480}:-1:flags=lanczos`, '-c:v', 'gif', '-loop', '0');
        break;

      case 'thumbnail':
        // Extract frame at a specific time.
        if (start !== null) {
          args.push('-ss', String(start));
        }
        args.push('-vframes', '1');
        if (width) {
          args.push('-vf', `scale=${width}:-1`);
        }
        break;

	      case 'extract_audio': {
	        // `-vn` drops the video. The codec is a copy whenever the container
	        // already speaks the source audio, so an m4a out of an AAC track is
	        // lossless instead of a second generation.
	        const sourceCodec = await probeAudioCodec( inputPath );

	        if ( '' === sourceCodec ) {
	          cleanupUpload();
	          return res.status(400).json({ error: 'This file has no audio track to extract.' });
	        }

	        const choice = audioExtractionCodec( format, sourceCodec || '' );

	        args.push('-vn', '-c:a', choice.codec);

	        // A bitrate describes an encoder, and a copy has none.
	        if (!choice.copy && audioBitrate) {
	          args.push('-b:a', audioBitrate);
	        }

	        reencoded = !choice.copy;
	        break;
	      }

	      case 'transcode': {
	        // Every value here was allowlisted above; this case only decides
	        // which of them reaches argv.
	        if (vcodec) {
	          args.push('-c:v', vcodec);
	        }

	        if (videoBitrate) {
	          args.push('-b:v', videoBitrate);
	        }

	        if (fps) {
	          args.push('-r', String(fps));
	        }

	        // `-2` rather than `-1` keeps the derived side even, which x264 and
	        // x265 require; an odd width is a hard encoder failure.
	        if (width && height) {
	          args.push('-vf', `scale=${width}:${height}`);
	        } else if (height) {
	          args.push('-vf', `scale=-2:${height}`);
	        } else if (width) {
	          args.push('-vf', `scale=${width}:-2`);
	        }

	        if (boolField(rawRemoveAudio, false)) {
	          args.push('-an');
	        } else {
	          if (acodec) {
	            args.push('-c:a', acodec);
	          }

	          if (audioBitrate) {
	            args.push('-b:a', audioBitrate);
	          }
	        }
	        break;
	      }

	      case 'speed': {
	        if ( null === speed ) {
	          cleanupUpload();
	          return res.status(400).json({ error: 'speed is required for the speed operation' });
	        }

	        // `setpts` takes a multiplier on the timestamps, so twice the speed
	        // is half the PTS. Audio is the reciprocal problem: `atempo` takes
	        // the speed itself.
	        args.push('-vf', `setpts=PTS/${speed}`);

	        const sampleRate = await probeAudioStream(inputPath);

	        if ( sampleRate ) {
	          if ( boolField(rawMaintainPitch, true) ) {
	            args.push('-af', atempoChain(speed));
	          } else {
	            // Deliberately *not* pitch-preserving: resampling the wrong way
	            // shifts the pitch with the speed. That is the "chipmunk"
	            // effect, and asking for it is the only reason to be here.
	            args.push(
	              '-af',
	              `asetrate=${ Math.round(sampleRate * speed) },aresample=${ sampleRate }`
	            );
	          }
	        }
	        break;
	      }

      default:
        cleanupUpload();
        return res.status(400).json({ error: `Unknown operation: ${operation}` });
    }

    if (faststart) {
      args.push('-movflags', '+faststart');
    }

    args.push('-y', outputPath);

    console.log(`[FFmpeg] ffmpeg ${args.join(' ')}`);
    await execFileAsync('ffmpeg', args, { timeout: 300000 }); // 5 min timeout

    const stats = fs.statSync(outputPath);
    const originalStats = fs.statSync(inputPath);

    res.json({
      success: true,
      operation,
      original_size: originalStats.size,
      output_size: stats.size,
      savings_percent: ((originalStats.size - stats.size) / originalStats.size * 100).toFixed(1) + '%',
      // Only extraction reports this: every other operation re-encodes by
      // definition, and a copy that says it is a copy is how a caller learns
      // the result is lossless.
      ...(null === reencoded ? {} : { reencoded }),
      output_file: outputName,
    });

    // Cleanup
    fs.unlinkSync(inputPath);
    setTimeout(() => { try { fs.unlinkSync(outputPath); } catch {} }, 60000); // keep output for 1 min
  } catch (err) {
    console.error('[Video Process]', err.message);
    if (req.file) fs.unlink(req.file.path, () => {});
    res.status(500).json({ error: err.message });
  }
});

// ── Compose two or more inputs ────────────────────────────
//
// The single-input `/process` route cannot watermark: an overlay needs the
// video and the mark as separate inputs. This endpoint takes them as an
// ordered `files` array so one contract covers every multi-input operation —
// `watermark` today, `merge` later — rather than one bespoke route per filter.
router.post('/compose', composeUpload.any(), async (req, res) => {
	const uploaded = Array.isArray(req.files) ? req.files : [];
	const cleanup = () => {
		for (const file of uploaded) {
			try { fs.unlinkSync(file.path); } catch {}
		}
	};

	// `MediaWorkerClient::uploadFiles()` sends `files[0]`, `files[1]`, … and a
	// hand-written client may send `files` twice. Ordering is carried in the
	// field name rather than trusted to arrival order — the video has to be
	// first, and a swapped pair would overlay the video onto the mark.
	const files = uploaded
		.filter( ( file ) => 'files' === file.fieldname || 0 === file.fieldname.indexOf( 'files[' ) )
		.sort( ( a, b ) => {
			const ai = parseInt( ( a.fieldname.match( /\[(\d+)\]/ ) || [ 0, 0 ] )[ 1 ], 10 );
			const bi = parseInt( ( b.fieldname.match( /\[(\d+)\]/ ) || [ 0, 0 ] )[ 1 ], 10 );

			return ai - bi;
		} );

	try {
		const caps = await detectCapabilities();
		if (!caps.ffmpeg) {
			cleanup();
			return res.status(503).json({ error: 'capability_unavailable', capability: 'video', message: 'ffmpeg is not installed on this server.' });
		}

		const {
			operation = 'watermark',
			position: rawPosition,
			opacity: rawOpacity,
			scale: rawScale,
			format: rawFormat,
			duration: rawDuration,
			width: rawWidth,
			height: rawHeight,
			fps: rawFps,
			transition: rawTransition,
			transition_duration: rawTransitionDuration,
		} = req.body;
		const videoDir = siteDirFor(req.site, 'video');

		// ── merge ──
		// By default a copy-based join: nothing is re-encoded, so nothing is lost.
		// The price is the precondition, and it is enforced because ffmpeg will
		// not. A transition cannot copy — every frame emitted during the overlap
		// is a blend of two frames — so asking for one switches to a re-encoding
		// path that normalises the clips instead of refusing them.
		if ( 'merge' === operation ) {
			if ( files.length < 2 ) {
				cleanup();
				return res.status(400).json({ error: 'merge needs at least two video files under the "files" field, in the order to join them.' });
			}

			const mergeFormat = resolveFormat(rawFormat);

			if ( ! mergeFormat ) {
				cleanup();
				return res.status(400).json({ error: `Unknown output format: ${ rawFormat }` });
			}

			let transition;
			let transitionDuration = DEFAULT_MERGE_TRANSITION_SECONDS;

			try {
				transition = resolveTransition( rawTransition, 'merge' );
				transitionDuration =
					boundedNumber( rawTransitionDuration, 0.05, MAX_TRANSITION_SECONDS, 'transition_duration' ) ??
					DEFAULT_MERGE_TRANSITION_SECONDS;
			} catch ( argErr ) {
				cleanup();
				return res.status( 400 ).json({ error: argErr.message });
			}

			const probes = [];

			for ( const file of files ) {
				probes.push( await probeMedia( file.path ) );
			}

			if ( 'none' === transition ) {
				const mismatch = mergeMismatch( probes );

				if ( mismatch ) {
					cleanup();
					return res.status(400).json({
						error: `${ mismatch }. A merge copies the streams, so every clip has to match; normalise the outliers first (the transcode operation does that), or ask for a fade, which re-encodes.`,
					});
				}

				const containers = COPY_CONTAINERS[ probes[ 0 ].vcodec ] || [ 'mkv' ];

				if ( ! containers.includes( mergeFormat ) ) {
					cleanup();
					return res.status(400).json({
						error: `A ${ probes[ 0 ].vcodec } stream cannot be copied into ${ mergeFormat }. Use one of: ${ containers.join( ', ' ) }.`,
					});
				}

				// The demuxer reads its list from disk, so the quoting is ffmpeg's
				// (single quotes, escaped), not a shell's.
				const listName = `concat_${ Date.now() }.txt`;
				const listPath = path.join( videoDir, listName );
				const lines = files.map(
					( file ) => `file '${ path.resolve( file.path ).replace( /'/g, "'\\''" ) }'`
				);

				fs.writeFileSync( listPath, lines.join( '\n' ) + '\n' );

				const copyOutputName = `processed_${ Date.now() }.${ mergeFormat }`;
				const copyOutputPath = path.join( videoDir, copyOutputName );

				try {
					const copyArgs = [ '-f', 'concat', '-safe', '0', '-i', listPath, '-c', 'copy' ];

					if ( FASTSTART_FORMATS.has( mergeFormat ) ) {
						copyArgs.push( '-movflags', '+faststart' );
					}

					copyArgs.push( '-y', copyOutputPath );

					console.log( `[FFmpeg] ffmpeg ${ copyArgs.join( ' ' ) }` );
					await execFileAsync( 'ffmpeg', copyArgs, { timeout: 300000 } );
				} finally {
					try { fs.unlinkSync( listPath ); } catch {}
				}

				const copyStats = fs.statSync( copyOutputPath );

				res.json({
					success: true,
					operation,
					transition,
					source_count: files.length,
					reencoded: false,
					output_size: copyStats.size,
					output_file: copyOutputName,
				});

				cleanup();
				setTimeout(() => { try { fs.unlinkSync( copyOutputPath ); } catch {} }, 60000);

				return;
			}

			// ── merge with a transition ──
			// xfade re-encodes, so the copy preconditions no longer apply — but the
			// inputs must still agree on a frame, a rate and a sample format, and
			// every clip needs a readable duration to place its cut.
			const unreadable = probes.findIndex( ( probe ) => ! probe );

			if ( -1 !== unreadable ) {
				cleanup();
				return res.status(400).json({ error: `clip ${ unreadable + 1 } has no readable video stream, so a transition cannot be placed. Merge with transition "none", or re-encode it first.` });
			}

			const withAudio = probes.every( ( probe ) => probe.hasAudio );

			// `acrossfade` needs audio on both sides of every boundary, so a set
			// that is only partly scored has no correct answer here. Dropping the
			// tracks and inventing silence are both decisions the caller should
			// make, not ones the route should make for them.
			if ( ! withAudio && probes.some( ( probe ) => probe.hasAudio ) ) {
				cleanup();
				return res.status(400).json({ error: 'A transition needs every clip to agree on audio: some clips have a track and some do not. Transcode the silent ones with a silent track first, or merge with transition "none".' });
			}

			const shortest = Math.min( ...probes.map( ( probe ) => probe.duration ) );

			if ( ! ( shortest > 0 ) ) {
				cleanup();
				return res.status(400).json({ error: 'A clip has no readable duration, so the transition cannot be placed. Merge with transition "none", or re-encode the clip first.' });
			}

			if ( transitionDuration >= shortest ) {
				cleanup();
				return res.status(400).json({ error: `The transition (${ transitionDuration } s) has to be shorter than the shortest clip (${ shortest.toFixed( 2 ) } s).` });
			}

			const offsets = xfadeOffsets( probes.map( ( probe ) => probe.duration ), transitionDuration );

			if ( ! offsets ) {
				cleanup();
				return res.status(400).json({ error: 'The transitions would overlap: the clips are too short for that transition duration.' });
			}

			const first = probes[ 0 ];
			const frameWidth = first.width;
			const frameHeight = first.height;
			const frameRate = first.fps > 0 ? first.fps : 25;
			const filter = XFADE[ transition ];
			const branches = [];

			// xfade refuses inputs that differ rather than adapting them, so each
			// clip is forced into the first clip's frame, rate and pixel format
			// before it reaches the filter. `decrease` + `pad` is `contain`: the
			// whole frame survives, where a crop would silently lose the edges.
			for ( let index = 0; index < files.length; index++ ) {
				branches.push(
					`[${ index }:v]scale=${ frameWidth }:${ frameHeight }:force_original_aspect_ratio=decrease,` +
					`pad=${ frameWidth }:${ frameHeight }:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=${ frameRate },format=yuv420p[v${ index }]`
				);
			}

			if ( withAudio ) {
				for ( let index = 0; index < files.length; index++ ) {
					branches.push(
						`[${ index }:a]aformat=sample_fmts=fltp:channel_layouts=stereo:sample_rates=48000[a${ index }]`
					);
				}
			}

			// Chain left to right: each xfade takes the previous result as its
			// first input, which is why the offsets are cumulative.
			let video = 'v0';

			for ( let index = 1; index < files.length; index++ ) {
				const output = index === files.length - 1 ? 'v' : `vx${ index }`;

				branches.push(
					`[${ video }][v${ index }]xfade=transition=${ filter }:duration=${ transitionDuration }:offset=${ offsets[ index - 1 ] }[${ output }]`
				);

				video = output;
			}

			if ( withAudio ) {
				let audio = 'a0';

				for ( let index = 1; index < files.length; index++ ) {
					const output = index === files.length - 1 ? 'a' : `ax${ index }`;

					branches.push(
						`[${ audio }][a${ index }]acrossfade=d=${ transitionDuration }:c1=tri:c2=tri[${ output }]`
					);

					audio = output;
				}
			}

			const mergeOutputName = `processed_${ Date.now() }.${ mergeFormat }`;
			const mergeOutputPath = path.join( videoDir, mergeOutputName );
			const args = [];

			for ( const file of files ) {
				args.push( '-i', file.path );
			}

			args.push( '-filter_complex', branches.join( ';' ), '-map', '[v]' );

			if ( withAudio ) {
				args.push( '-map', '[a]', '-c:a', 'webm' === mergeFormat ? 'libopus' : 'aac' );
			}

			args.push( ...videoEncoder( mergeFormat ), '-pix_fmt', 'yuv420p' );

			if ( FASTSTART_FORMATS.has( mergeFormat ) ) {
				args.push( '-movflags', '+faststart' );
			}

			args.push( '-y', mergeOutputPath );

			console.log( `[FFmpeg] ffmpeg ${ args.join( ' ' ) }` );
			await execFileAsync( 'ffmpeg', args, { timeout: 300000 } );

			const mergeStats = fs.statSync( mergeOutputPath );
			const total = probes.reduce( ( sum, probe ) => sum + probe.duration, 0 );

			res.json({
				success: true,
				operation,
				transition,
				source_count: files.length,
				reencoded: true,
				// The overlaps every boundary removes from the running length.
				duration: Number( ( total - ( files.length - 1 ) * transitionDuration ).toFixed( 3 ) ),
				output_size: mergeStats.size,
				output_file: mergeOutputName,
			});

			cleanup();
			setTimeout(() => { try { fs.unlinkSync( mergeOutputPath ); } catch {} }, 60000);

			return;
		}

		// ── images ──
		// A slideshow: every still is looped for a fixed duration, fitted into
		// one frame, then either cross-faded (`fade`/`slide`), given Ken Burns
		// motion (`zoom`), or joined with hard cuts. `contain` rather than `cover`
		// because cropping a photo is worse than bars around it.
		if ( 'images' === operation ) {
			if ( ! files.length ) {
				cleanup();
				return res.status(400).json({ error: 'images needs at least one image under the "files" field, in the order to show them.' });
			}

			let perImage;
			let frameWidth;
			let frameHeight;
			let frameRate;
			let imagesFormat;
			let transition;
			let transitionDuration = IMAGES_TRANSITION_SECONDS;

			try {
				perImage = boundedNumber( rawDuration, 0.1, 60, 'duration' ) ?? 3;
				frameWidth = boundedDimension( rawWidth, 'width' );
				frameHeight = boundedDimension( rawHeight, 'height' );
				frameRate = boundedNumber( rawFps, 1, 120, 'fps' ) ?? 25;
				imagesFormat = resolveFormat( rawFormat );
				transition = resolveTransition( rawTransition, 'images' );
				transitionDuration =
					boundedNumber( rawTransitionDuration, 0.05, MAX_TRANSITION_SECONDS, 'transition_duration' ) ??
					IMAGES_TRANSITION_SECONDS;
			} catch ( argErr ) {
				cleanup();
				return res.status(400).json({ error: argErr.message });
			}

			if ( ! imagesFormat ) {
				cleanup();
				return res.status(400).json({ error: `Unknown output format: ${ rawFormat }` });
			}

			if ( ! frameWidth || ! frameHeight ) {
				cleanup();
				return res.status(400).json({ error: 'width and height are required: the frame every still is fitted into.' });
			}

			// One still has no boundary to put a transition on, so the requested
			// transition only has an effect from the second image onward.
			const boundaries = Math.max( 0, files.length - 1 );
			const usedTransition = boundaries > 0 ? transition : 'none';
			// Only a filter in `XFADE` joins two inputs. `zoom` is motion inside a
			// still, so it has no boundary — and therefore no overlap to shorten
			// the timeline or to check against the per-image duration.
			const boundaryTransition = XFADE[ usedTransition ] ? usedTransition : null;

			if ( boundaryTransition && transitionDuration >= perImage ) {
				cleanup();
				return res.status(400).json({ error: `A ${ boundaryTransition } transition lasts ${ transitionDuration } s, so each image has to be shown for longer than that; got ${ perImage } s.` });
			}

			const offsets = boundaryTransition
				? xfadeOffsets( files.map( () => perImage ), transitionDuration )
				: null;

			if ( boundaryTransition && ! offsets ) {
				cleanup();
				return res.status(400).json({ error: 'The transitions would overlap: the images are too short for that transition duration.' });
			}

			const args = [];

			// `-t` before `-i` bounds how much of the looped still is read, which
			// is what gives each image its duration.
			for ( const file of files ) {
				args.push( '-loop', '1', '-t', String( perImage ), '-i', file.path );
			}

			// `zoompan` sits between the fit and the pixel-format conversion because
			// it steps in source pixels — see `kenBurnsUpscale()`. Everything else
			// keeps the fitted branch exactly as it was.
			const upscaleFactor = 'zoom' === usedTransition ? kenBurnsUpscale( frameWidth, frameHeight ) : 1;
			const motion =
				'zoom' === usedTransition
					? `,scale=${ frameWidth * upscaleFactor }:${ frameHeight * upscaleFactor },` +
						kenBurnsZoompan( frameWidth, frameHeight, frameRate, perImage )
					: '';

			const branches = files.map(
				( _file, index ) =>
					`[${ index }:v]scale=${ frameWidth }:${ frameHeight }:force_original_aspect_ratio=decrease,` +
					`pad=${ frameWidth }:${ frameHeight }:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=${ frameRate }${ motion },format=yuv420p[v${ index }]`
			);

			let graph;

			if ( offsets && offsets.length ) {
				// The same chain a transitioning merge uses, over stills: each
				// branch is already the same frame, rate and SAR, so the only thing
				// left to decide is where each cut lands.
				const filter = XFADE[ usedTransition ];
				let video = 'v0';

				for ( let index = 1; index < files.length; index++ ) {
					const output = index === files.length - 1 ? 'v' : `vx${ index }`;

					branches.push(
						`[${ video }][v${ index }]xfade=transition=${ filter }:duration=${ transitionDuration }:offset=${ offsets[ index - 1 ] }[${ output }]`
					);

					video = output;
				}

				graph = branches.join( ';' );
			} else {
				const joined = files.map( ( _file, index ) => `[v${ index }]` ).join( '' );
				graph = `${ branches.join( ';' ) };${ joined }concat=n=${ files.length }:v=1:a=0[v]`;
			}

			const imagesOutputName = `processed_${ Date.now() }.${ imagesFormat }`;
			const imagesOutputPath = path.join( videoDir, imagesOutputName );

			// `videoEncoder()` rather than a hardcoded libx264: `webm` is one of the
			// formats the route accepts, and H.264 cannot be written into it.
			args.push(
				'-filter_complex', graph,
				'-map', '[v]',
				...videoEncoder( imagesFormat ),
				'-pix_fmt', 'yuv420p'
			);

			if ( FASTSTART_FORMATS.has( imagesFormat ) ) {
				args.push( '-movflags', '+faststart' );
			}

			args.push( '-y', imagesOutputPath );

			console.log( `[FFmpeg] ffmpeg ${ args.join( ' ' ) }` );
			await execFileAsync( 'ffmpeg', args, { timeout: 300000 } );

			const imagesStats = fs.statSync( imagesOutputPath );

			res.json({
				success: true,
				operation,
				transition: usedTransition,
				source_count: files.length,
				// Every overlap shortens the running length by one transition.
				duration: files.length * perImage - ( offsets && offsets.length ? boundaries * transitionDuration : 0 ),
				output_size: imagesStats.size,
				output_file: imagesOutputName,
			});

			cleanup();
			setTimeout(() => { try { fs.unlinkSync( imagesOutputPath ); } catch {} }, 60000);

			return;
		}

		let opacity;
		let scaleFactor;
		let position;
		let format;

		try {
			opacity = boundedNumber(rawOpacity, 0, 1, 'opacity');
			scaleFactor = boundedNumber(rawScale, 0.01, 1, 'scale');
			position = resolvePosition(rawPosition);
			format = resolveFormat(rawFormat);
		} catch (argErr) {
			cleanup();
			return res.status(400).json({ error: argErr.message });
		}

		if (!position) {
			cleanup();
			return res.status(400).json({ error: 'position must be one of top-left, top-right, bottom-left, bottom-right, center' });
		}

		if (!format) {
			cleanup();
			return res.status(400).json({ error: `Unknown output format: ${rawFormat}` });
		}

		if ('watermark' !== operation) {
			cleanup();
			return res.status(400).json({ error: `Unknown compose operation: ${operation}` });
		}

		const video = files[0];
		const mark = files[1];

		if (!video || !mark) {
			cleanup();
			return res.status(400).json({ error: 'watermark needs two files under the "files" field: the video first, then the watermark image.' });
		}

		const videoWidth = await probeVideoWidth(video.path);

		if (!videoWidth) {
			cleanup();
			return res.status(400).json({ error: 'The video width could not be read, so the watermark cannot be sized.' });
		}

		// The mark is a fraction of the video's width and the gutter a smaller
		// one, so a 4K source and a 720p one get the same visual weight.
		const markWidth = Math.max(2, Math.round(videoWidth * (scaleFactor ?? 0.2)));
		const margin = Math.max(2, Math.round(videoWidth * 0.02));

		// `format=rgba` first because a JPEG mark has no alpha channel and
		// `colorchannelmixer` would have nothing to multiply.
		const graph =
			`[1:v]format=rgba,scale=${markWidth}:-2,colorchannelmixer=aa=${opacity ?? 0.7}[wm];` +
			`[0:v][wm]overlay=${positionExpression(position, margin)}:format=auto[v]`;

		const outputName = `processed_${Date.now()}.${format}`;
		const outputPath = path.join(videoDir, outputName);

		const args = [
			'-i', video.path,
			'-i', mark.path,
			'-filter_complex', graph,
			'-map', '[v]',
			// `0:a?` maps the audio when there is one and stays quiet when not;
			// without the `?` a silent video fails the whole run.
			'-map', '0:a?',
			'-c:v', 'libx264',
			'-crf', '20',
			'-pix_fmt', 'yuv420p',
			'-c:a', 'webm' === format ? 'libopus' : 'aac',
		];

		if (FASTSTART_FORMATS.has(format)) {
			args.push('-movflags', '+faststart');
		}

		args.push('-y', outputPath);

		console.log(`[FFmpeg] ffmpeg ${args.join(' ')}`);
		await execFileAsync('ffmpeg', args, { timeout: 300000 });

		const stats = fs.statSync(outputPath);

		res.json({
			success: true,
			operation,
			position,
			output_size: stats.size,
			output_file: outputName,
		});

		cleanup();
		setTimeout(() => { try { fs.unlinkSync(outputPath); } catch {} }, 60000);
	} catch (err) {
		console.error('[Video Compose]', err.message);
		cleanup();
		res.status(500).json({ error: err.message });
	}
});

// ── Download processed output ──────────────────────────────
// Express 4 wildcard (*) so multi-segment names (frames_*/frame_000.jpg)
// match too; the strict regex below re-validates the whole name.
router.get('/download/*', (req, res) => {
  const name = String(req.params[0] || '');
  // Every path segment must be filename-safe; the first segment must be
  // one of the namespaces produced by /process. This prevents traversal
  // AND serving unrelated scratch files (e.g. other sites' uploads).
  if (!/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/.test(name)) {
    return res.status(400).json({ error: 'invalid_filename' });
  }
  const first = name.split('/')[0];
  if (!first.startsWith('processed_') && !first.startsWith('frames_')) {
    return res.status(403).json({ error: 'path_not_allowed' });
  }
  const base = siteDirFor(req.site, 'video');
  const resolved = path.resolve(base, name);
  if (resolved !== base && !resolved.startsWith(base + path.sep)) {
    return res.status(403).json({ error: 'path_not_allowed' });
  }
  try {
    if (!fs.statSync(resolved).isFile()) {
      return res.status(404).json({ error: 'file_not_found' });
    }
  } catch {
    return res.status(404).json({ error: 'file_not_found' });
  }
  res.download(resolved, path.basename(resolved));
});

// ── Video Info ────────────────────────────────────────────
router.post('/info', upload.single('file'), async (req, res) => {
  try {
    const caps = await detectCapabilities();
    if (!caps.ffprobe) {
      return res.status(503).json({ error: 'capability_unavailable', capability: 'video', message: 'ffprobe is not installed on this server.' });
    }

    if (!req.file) {
      return res.status(400).json({ error: 'No file uploaded' });
    }

    const inputPath = req.file.path;

    const { stdout } = await execFileAsync(
      'ffprobe',
      ['-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', inputPath],
      { timeout: 30000 }
    );
    const info = JSON.parse(stdout);

    const videoStream = info.streams?.find((s) => s.codec_type === 'video');
    const audioStream = info.streams?.find((s) => s.codec_type === 'audio');

    res.json({
      success: true,
      format: info.format?.format_name,
      duration: parseFloat(info.format?.duration || 0),
      size: parseInt(info.format?.size || 0),
      bitrate: parseInt(info.format?.bit_rate || 0),
      video: videoStream
        ? {
            codec: videoStream.codec_name,
            width: videoStream.width,
            height: videoStream.height,
            fps: parseRational(videoStream.r_frame_rate),
            bitrate: parseInt(videoStream.bit_rate || 0),
            pixel_format: videoStream.pix_fmt,
          }
        : null,
      audio: audioStream
        ? {
            codec: audioStream.codec_name,
            channels: audioStream.channels,
            sample_rate: audioStream.sample_rate,
            bitrate: parseInt(audioStream.bit_rate || 0),
          }
        : null,
    });

    fs.unlinkSync(inputPath);
  } catch (err) {
    console.error('[Video Info]', err.message);
    if (req.file) fs.unlink(req.file.path, () => {});
    res.status(500).json({ error: err.message });
  }
});

export {
	router as videoRouter,
	boundedNumber,
	boundedDimension,
	resolveFormat,
	resolveFit,
	resolveTransition,
	resolveCodec,
	resolveBitrate,
	resolvePosition,
	positionExpression,
	firstDifference,
	mergeMismatch,
	xfadeOffsets,
	videoEncoder,
	kenBurnsUpscale,
	kenBurnsZoompan,
	frameTimestamps,
	audioExtractionCodec,
	parseRational,
	boolField,
	atempoChain,
	VIDEO_CODECS,
	AUDIO_CODECS,
	PROCESS_FORMATS,
	XFADE,
	TRANSITIONS,
};
