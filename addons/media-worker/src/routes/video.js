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
      const stamps = String(timestamps || '')
        .split(',')
        .map((s) => parseFloat(String(s).trim()))
        .filter((n) => Number.isFinite(n) && n >= 0);
      const frameDirName = `frames_${Date.now()}`;
      const frameDir = path.join(videoDir, frameDirName);
      fs.mkdirSync(frameDir, { recursive: true });
      const scale = width ? `scale=${parseInt(width, 10)}:-1` : null;
      const files = [];

      if (stamps.length) {
        for (let i = 0; i < stamps.length; i++) {
          const name = `frame_${String(i).padStart(3, '0')}.jpg`;
          const out = path.join(frameDir, name);
          const args = ['-ss', String(stamps[i]), '-i', inputPath, '-frames:v', '1', '-q:v', '2'];

          if (scale) {
            args.push('-vf', scale);
          }

          args.push('-y', out);
          await execFileAsync('ffmpeg', args, { timeout: 300000 });
          files.push(`${frameDirName}/${name}`);
        }
      } else {
        const frameCount = Math.max(1, Math.min(120, parseInt(count, 10) || 10));
        let fpsFilter = 'fps=1';
        try {
          const { stdout } = await execFileAsync(
            'ffprobe',
            ['-v', 'quiet', '-print_format', 'json', '-show_format', inputPath],
            { timeout: 30000 }
          );
          const meta = JSON.parse(stdout);
          const videoDuration = parseFloat(meta?.format?.duration || 0);
          if (videoDuration > 0) {
            fpsFilter = `fps=${Math.max(0.01, frameCount / videoDuration)}`;
          }
        } catch {
          // Duration unknown — fall back to 1 fps.
        }
        const filters = [fpsFilter, scale].filter(Boolean).join(',');
        const outPattern = path.join(frameDir, 'frame_%03d.jpg');
        await execFileAsync(
          'ffmpeg',
          ['-i', inputPath, '-vf', filters, '-q:v', '2', '-y', outPattern],
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
	        const codec = 'mp3' === format ? 'libmp3lame' : ('m4a' === format || 'aac' === format ? 'aac' : 'copy');
	        args.push('-vn', '-c:a', codec);
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
		} = req.body;
		const videoDir = siteDirFor(req.site, 'video');

		// ── merge ──
		// A copy-based join: nothing is re-encoded, so nothing is lost. The price
		// is the precondition, and it is enforced because ffmpeg will not.
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

			const probes = [];

			for ( const file of files ) {
				probes.push( await probeMedia( file.path ) );
			}

			const mismatch = mergeMismatch( probes );

			if ( mismatch ) {
				cleanup();
				return res.status(400).json({
					error: `${ mismatch }. A merge copies the streams, so every clip has to match; normalise the outliers first (the transcode operation does that).`,
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

			const mergeOutputName = `processed_${ Date.now() }.${ mergeFormat }`;
			const mergeOutputPath = path.join( videoDir, mergeOutputName );

			try {
				const args = [ '-f', 'concat', '-safe', '0', '-i', listPath, '-c', 'copy' ];

				if ( FASTSTART_FORMATS.has( mergeFormat ) ) {
					args.push( '-movflags', '+faststart' );
				}

				args.push( '-y', mergeOutputPath );

				console.log( `[FFmpeg] ffmpeg ${ args.join( ' ' ) }` );
				await execFileAsync( 'ffmpeg', args, { timeout: 300000 } );
			} finally {
				try { fs.unlinkSync( listPath ); } catch {}
			}

			const mergeStats = fs.statSync( mergeOutputPath );

			res.json({
				success: true,
				operation,
				source_count: files.length,
				reencoded: false,
				output_size: mergeStats.size,
				output_file: mergeOutputName,
			});

			cleanup();
			setTimeout(() => { try { fs.unlinkSync( mergeOutputPath ); } catch {} }, 60000);

			return;
		}

		// ── images ──
		// A slideshow: every still is looped for a fixed duration, fitted into
		// one frame, then concatenated. `contain` rather than `cover` because
		// cropping a photo is worse than bars around it.
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

			try {
				perImage = boundedNumber( rawDuration, 0.1, 60, 'duration' ) ?? 3;
				frameWidth = boundedDimension( rawWidth, 'width' );
				frameHeight = boundedDimension( rawHeight, 'height' );
				frameRate = boundedNumber( rawFps, 1, 120, 'fps' ) ?? 25;
				imagesFormat = resolveFormat( rawFormat );
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

			const args = [];

			// `-t` before `-i` bounds how much of the looped still is read, which
			// is what gives each image its duration.
			for ( const file of files ) {
				args.push( '-loop', '1', '-t', String( perImage ), '-i', file.path );
			}

			const branches = files.map(
				( _file, index ) =>
					`[${ index }:v]scale=${ frameWidth }:${ frameHeight }:force_original_aspect_ratio=decrease,` +
					`pad=${ frameWidth }:${ frameHeight }:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=${ frameRate },format=yuv420p[v${ index }]`
			);

			const joined = files.map( ( _file, index ) => `[v${ index }]` ).join( '' );
			const graph = `${ branches.join( ';' ) };${ joined }concat=n=${ files.length }:v=1:a=0[v]`;

			const imagesOutputName = `processed_${ Date.now() }.${ imagesFormat }`;
			const imagesOutputPath = path.join( videoDir, imagesOutputName );

			args.push(
				'-filter_complex', graph,
				'-map', '[v]',
				'-c:v', 'libx264',
				'-crf', '20',
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
				source_count: files.length,
				duration: files.length * perImage,
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
	resolveCodec,
	resolveBitrate,
	resolvePosition,
	positionExpression,
	firstDifference,
	mergeMismatch,
	parseRational,
	boolField,
	atempoChain,
	VIDEO_CODECS,
	AUDIO_CODECS,
	PROCESS_FORMATS,
};
