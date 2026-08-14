import { Router } from 'express';
import multer from 'multer';
import { exec } from 'child_process';
import { promisify } from 'util';
import path from 'path';
import fs from 'fs';
import axios from 'axios';
import { detectCapabilities } from '../utils/capabilities.js';
import { siteUploadDir, siteDirFor } from '../utils/site-paths.js';

const execAsync = promisify(exec);
const router = Router();

// Uploads land in the caller's site namespace (multi-tenant) or the system
// temp dir (single-tenant, legacy behavior).
const upload = multer({
	storage: multer.diskStorage({
		destination: ( req, _file, cb ) => {
			try {
				cb( null, siteUploadDir( req.site ) );
			} catch ( err ) {
				cb( err );
			}
		},
	}),
	limits: { fileSize: 500 * 1024 * 1024 },
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
  try {
    const caps = await detectCapabilities();
    if (!caps.ffmpeg) {
      return res.status(503).json({ error: 'capability_unavailable', capability: 'video', message: 'ffmpeg is not installed on this server.' });
    }

    if (!req.file) {
      return res.status(400).json({ error: 'No file uploaded' });
    }

    const { operation = 'compress', width, height, format = 'mp4', fps, start, duration, timestamps, count } = req.body;
    const inputPath = req.file.path;
    const videoDir = siteDirFor(req.site, 'video');

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
          const vf = scale ? ` -vf "${scale}"` : '';
          await execAsync(`ffmpeg -ss ${stamps[i]} -i "${inputPath}" -frames:v 1 -q:v 2${vf} -y "${out}"`, { timeout: 300000 });
          files.push(`${frameDirName}/${name}`);
        }
      } else {
        const frameCount = Math.max(1, Math.min(120, parseInt(count, 10) || 10));
        let fpsFilter = 'fps=1';
        try {
          const { stdout } = await execAsync(`ffprobe -v quiet -print_format json -show_format "${inputPath}"`, { timeout: 30000 });
          const meta = JSON.parse(stdout);
          const videoDuration = parseFloat(meta?.format?.duration || 0);
          if (videoDuration > 0) {
            fpsFilter = `fps=${Math.max(0.01, frameCount / videoDuration)}`;
          }
        } catch {
          // Duration unknown — fall back to 1 fps.
        }
        const vf = [fpsFilter, scale].filter(Boolean).join(',');
        const outPattern = path.join(frameDir, 'frame_%03d.jpg');
        await execAsync(`ffmpeg -i "${inputPath}" -vf "${vf}" -q:v 2 -y "${outPattern}"`, { timeout: 300000 });
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

    let ffmpegCmd = `ffmpeg -i "${inputPath}"`;

    switch (operation) {
      case 'resize':
        if (!width && !height) return res.status(400).json({ error: 'Width or height required for resize' });
        if (width && height) {
          ffmpegCmd += ` -vf "scale=${width}:${height}"`;
        } else if (width) {
          ffmpegCmd += ` -vf "scale=${width}:-1"`;
        } else {
          ffmpegCmd += ` -vf "scale=-1:${height}"`;
        }
        break;

      case 'crop':
        if (!width || !height) return res.status(400).json({ error: 'Width and height required for crop' });
        ffmpegCmd += ` -vf "crop=${width}:${height}"`;
        break;

      case 'trim':
        if (start) ffmpegCmd += ` -ss ${start}`;
        if (duration) ffmpegCmd += ` -t ${duration}`;
        break;

      case 'convert':
        // Format change only
        break;

      case 'compress':
        ffmpegCmd += ` -vcodec libx264 -crf 28 -preset fast`;
        break;

      case 'gif':
        if (fps) ffmpegCmd += ` -vf "fps=${fps},scale=${width || 480}:-1:flags=lanczos"`;
        ffmpegCmd += ` -c:v gif -loop 0`;
        break;

      case 'thumbnail':
        // Extract frame at specific time
        if (start) ffmpegCmd += ` -ss ${start}`;
        ffmpegCmd += ` -vframes 1`;
        if (width) ffmpegCmd += ` -vf "scale=${width}:-1"`;
        break;

      case 'extract_audio':
        // Strip the video stream; codec follows the requested format.
        {
          const codec = 'mp3' === format ? 'libmp3lame' : ('m4a' === format || 'aac' === format ? 'aac' : 'copy');
          ffmpegCmd += ` -vn -c:a ${codec}`;
        }
        break;

      default:
        return res.status(400).json({ error: `Unknown operation: ${operation}` });
    }

    ffmpegCmd += ` -y "${outputPath}"`;

    console.log(`[FFmpeg] ${ffmpegCmd}`);
    await execAsync(ffmpegCmd, { timeout: 300000 }); // 5 min timeout

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

    const { stdout } = await execAsync(
      `ffprobe -v quiet -print_format json -show_format -show_streams "${inputPath}"`
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
            fps: eval?.(`(${videoStream.r_frame_rate || '0/1'})`) || 0,
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

export { router as videoRouter };
