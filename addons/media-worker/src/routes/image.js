import { Router } from 'express';
import multer from 'multer';
import sharp from 'sharp';
import OpenAI from 'openai';
import { GoogleGenerativeAI } from '@google/generative-ai';
import axios from 'axios';
import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify(exec);
const router = Router();
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024 } });

// ── Provider Registry ─────────────────────────────────────
const PROVIDERS = {
  openai:       { name: 'OpenAI (DALL·E)',       key: 'OPENAI_API_KEY' },
  gemini:       { name: 'Google (Gemini/Imagen)', key: 'GEMINI_API_KEY' },
  stability:    { name: 'Stability AI (SDXL)',     key: 'STABILITY_API_KEY' },
  replicate:    { name: 'Replicate (Flux/SD)',     key: 'REPLICATE_API_KEY' },
  midjourney:   { name: 'Midjourney',              key: 'MIDJOURNEY_API_KEY' },
  leonardo:     { name: 'Leonardo.ai',             key: 'LEONARDO_API_KEY' },
  ideogram:     { name: 'Ideogram',                key: 'IDEOGRAM_API_KEY' },
  getimg:       { name: 'Getimg.ai',               key: 'GETIMG_API_KEY' },
  deepai:       { name: 'DeepAI',                  key: 'DEEPAI_API_KEY' },
  firefly:      { name: 'Adobe Firefly',           key: 'FIREFLY_CLIENT_ID' },
  clipdrop:     { name: 'Clipdrop (Stability)',    key: 'STABILITY_API_KEY' },
};

// ── GET /api/image/providers ── List available providers ──
router.get('/providers', (_req, res) => {
  const list = Object.entries(PROVIDERS).map(([id, p]) => ({
    id,
    name: p.name,
    configured: !!process.env[p.key],
    models: MODELS_BY_PROVIDER[id] || [],
  }));
  res.json({ providers: list });
});

// ── Model → Provider mapping ──────────────────────────────
const MODELS_BY_PROVIDER = {
  openai:     ['dall-e-3', 'dall-e-2'],
  gemini:     ['gemini-2.5-flash-image', 'nano-banana', 'gemini-3-pro-image'],
  stability:  ['stable-diffusion-xl', 'sd3', 'sd3-turbo', 'stable-diffusion-1.6', 'sdxl-1024'],
  replicate:  ['flux-schnell', 'flux-pro', 'flux-dev', 'sdxl', 'stable-diffusion-3', 'playground-v2.5', 'kandinsky-3', 'sdxl-lightning'],
  midjourney: ['midjourney-v6', 'midjourney-v6.1', 'midjourney-v5.2', 'niji-v6'],
  leonardo:   ['leonardo-xl', 'leonardo-vision', 'leonardo-phoenix', 'leonardo-anime'],
  ideogram:   ['ideogram-v2', 'ideogram-v1', 'ideogram-v1-turbo'],
  getimg:     ['stable-diffusion-xl', 'sd3', 'realistic-vision', 'dreamshaper', 'juggernaut-xl', 'flux-schnell'],
  deepai:     ['deepai-text2img', 'deepai-fantasy', 'deepai-cyberpunk'],
  firefly:    ['firefly-v3', 'firefly-v2'],
  clipdrop:   ['clipdrop-sdxl', 'clipdrop-sd3'],
};

// ── POST /api/image/generate ──────────────────────────────
router.post('/generate', async (req, res) => {
  try {
    const {
      prompt,
      model = 'dall-e-3',
      provider,
      size = '1024x1024',
      style = 'natural',
      count = 1,
      negative_prompt,
      aspect_ratio,
      seed,
      steps,
      cfg_scale,
    } = req.body;

    if (!prompt) {
      return res.status(400).json({ error: 'Prompt is required' });
    }

    // Auto-detect provider from model name if not specified
    let providerId = provider;
    if (!providerId) {
      for (const [pid, models] of Object.entries(MODELS_BY_PROVIDER)) {
        if (models.includes(model)) { providerId = pid; break; }
      }
    }
    if (!providerId) {
      return res.status(400).json({
        error: `Unknown model: ${model}`,
        available_models: MODELS_BY_PROVIDER,
        tip: 'Specify a provider param or use GET /api/image/providers to list options',
      });
    }

    const providerCfg = PROVIDERS[providerId];
    if (!providerCfg) {
      return res.status(400).json({ error: `Unknown provider: ${providerId}` });
    }

    if (!process.env[providerCfg.key]) {
      return res.status(503).json({
        error: `${providerCfg.name} API key not configured`,
        env_var: providerCfg.key,
        tip: `Set ${providerCfg.key} in .env`,
      });
    }

    const images = await generateWithProvider(providerId, model, {
      prompt, size, style, count, negative_prompt, aspect_ratio, seed, steps, cfg_scale,
    });

    res.json({ success: true, provider: providerId, model, images, prompt });
  } catch (err) {
    console.error('[Image Generate]', err.message);
    const status = err.response?.status || err.status || 500;
    res.status(status).json({
      error: err.message,
      provider_error: err.response?.data || null,
    });
  }
});

// ── Provider Implementations ─────────────────────────────

async function generateWithProvider(providerId, model, opts) {
  switch (providerId) {

    // ═══════════════════════════════════════════════
    // OpenAI — DALL·E 3 / DALL·E 2
    // ═══════════════════════════════════════════════
    case 'openai': {
      const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });
      const dalleModel = model === 'dall-e-2' ? 'dall-e-2' : 'dall-e-3';
      const response = await openai.images.generate({
        model: dalleModel,
        prompt: opts.prompt,
        n: opts.count || 1,
        size: opts.size || '1024x1024',
        style: dalleModel === 'dall-e-3' ? (opts.style || 'natural') : undefined,
        response_format: 'url',
      });
      return response.data.map((img) => ({
        url: img.url,
        model: dalleModel,
        revised_prompt: img.revised_prompt || null,
      }));
    }

    // ═══════════════════════════════════════════════
    // Google — Gemini / Imagen
    // ═══════════════════════════════════════════════
    case 'gemini': {
      const genAI = new GoogleGenerativeAI(process.env.GEMINI_API_KEY);

      // ── Imagen via REST API ──────────────────────
      if (model.startsWith('imagen')) {
        const imagenModelMap = {
          'imagen-4.0':       'imagen-4.0-generate-001',
          'imagen-4.0-ultra': 'imagen-4.0-ultra-generate-001',
          'imagen-4.0-fast':  'imagen-4.0-fast-generate-001',
        };
        const imagenModel = imagenModelMap[model] || 'imagen-4.0-generate-001';
        const sizeParts = parseSize(opts.size);
        // Imagen expects 1K (1024) or 2K (2048)
        const imagenSize = sizeParts.width >= 2048 ? '2K' : '1K';
        const resp = await axios.post(
          `https://generativelanguage.googleapis.com/v1beta/models/${imagenModel}:predict`,
          {
            instances: [{ prompt: opts.prompt }],
            parameters: imagenModel.includes('fast')
              ? { sampleCount: opts.count || 1 }
              : { sampleCount: opts.count || 1, sampleImageSize: imagenSize },
          },
          {
            headers: { 'Content-Type': 'application/json' },
            params: { key: process.env.GEMINI_API_KEY },
            timeout: 60000,
          }
        );

        return (resp.data.predictions || []).map((pred) => ({
          b64_json: pred.bytesBase64Encoded,
          model: imagenModel,
          mime: pred.mimeType || 'image/png',
        }));
      }

      // ── Gemini Flash/Pro image gen ────────────────
      const geminiModelMap = {
        'gemini-2.5-flash-image': 'gemini-2.5-flash-image',
        'gemini-3-pro-image':     'gemini-3-pro-image',
        'nano-banana':            'nano-banana-pro-preview',
      };
      const geminiModel = geminiModelMap[model] || 'gemini-2.5-flash-image';

      const genModel = genAI.getGenerativeModel({
        model: geminiModel,
        generationConfig: {
          responseModalities: ['Text', 'Image'],
        },
      });

      const sizeParts = parseSize(opts.size);
      const aspectHint = `Generate an image with dimensions ${sizeParts.width}x${sizeParts.height}. `;
      const countHint = opts.count > 1 ? `Generate ${opts.count} separate images. ` : '';

      const result = await genModel.generateContent(
        `${aspectHint}${countHint}${opts.prompt}`
      );

      const images = [];
      const response = result.response;

      for (const part of response.candidates?.[0]?.content?.parts || []) {
        if (part.inlineData) {
          images.push({
            b64_json: part.inlineData.data,
            model: geminiModel,
            mime: part.inlineData.mimeType || 'image/png',
          });
        }
      }

      if (images.length === 0) {
        const text = response.text();
        if (text) {
          throw new Error(`Gemini returned text instead of image: "${text.substring(0, 200)}"`);
        }
        throw new Error('Gemini did not return any images. Try a more visual prompt.');
      }

      return images;
    }

    // ═══════════════════════════════════════════════
    // Stability AI — SDXL, SD3, SD1.6
    // ═══════════════════════════════════════════════
    case 'stability': {
      const engineMap = {
        'stable-diffusion-xl':  'stable-diffusion-xl-1024-v1-0',
        'sdxl-1024':            'stable-diffusion-xl-1024-v1-0',
        'sd3':                  'stable-diffusion-3.5-large',
        'sd3-turbo':            'stable-diffusion-3.5-large-turbo',
        'stable-diffusion-1.6': 'stable-diffusion-v1-6',
      };
      const engineId = engineMap[model] || 'stable-diffusion-xl-1024-v1-0';

      if (model === 'sd3' || model === 'sd3-turbo') {
        // Stability AI v2 API (multipart form)
        const form = new FormData();
        form.append('prompt', opts.prompt);
        form.append('output_format', 'jpeg');
        if (opts.negative_prompt) form.append('negative_prompt', opts.negative_prompt);
        if (opts.seed) form.append('seed', String(opts.seed));
        if (opts.cfg_scale) form.append('cfg_scale', String(opts.cfg_scale));
        form.append('mode', 'text-to-image');

        const resp = await axios.post(
          `https://api.stability.ai/v2beta/stable-image/generate/sd3`,
          form,
          {
            headers: {
              Authorization: `Bearer ${process.env.STABILITY_API_KEY}`,
              Accept: 'application/json',
            },
            responseType: 'json',
          }
        );
        return [{
          url: resp.data.image,
          model,
          seed: resp.data.seed,
          finish_reason: resp.data.finish_reason,
        }];
      }

      // Stability AI v1 API
      const resp = await axios.post(
        `https://api.stability.ai/v1/generation/${engineId}/text-to-image`,
        {
          text_prompts: [
            { text: opts.prompt, weight: 1 },
            ...(opts.negative_prompt ? [{ text: opts.negative_prompt, weight: -1 }] : []),
          ],
          cfg_scale: opts.cfg_scale || 7,
          height: parseSize(opts.size).height || 1024,
          width: parseSize(opts.size).width || 1024,
          steps: opts.steps || 30,
          samples: opts.count || 1,
          seed: opts.seed || 0,
        },
        {
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${process.env.STABILITY_API_KEY}`,
          },
        }
      );
      return resp.data.artifacts.map((art) => ({
        b64_json: art.base64,
        model: engineId,
        seed: art.seed,
        finish_reason: art.finishReason,
      }));
    }

    // ═══════════════════════════════════════════════
    // Replicate — Flux, SDXL, Playground, Kandinsky
    // ═══════════════════════════════════════════════
    case 'replicate': {
      const versionMap = {
        'flux-schnell':       'black-forest-labs/flux-schnell',
        'flux-pro':           'black-forest-labs/flux-pro',
        'flux-dev':           'black-forest-labs/flux-dev',
        'sdxl':               'stability-ai/sdxl:39ed52f2a78e934b3ba6e2a89f5b1c712de7dfea535525255b1aa35c5565e08b',
        'stable-diffusion-3': 'stability-ai/stable-diffusion-3.5-large',
        'playground-v2.5':    'playgroundai/playground-v2.5-1024px-aesthetic',
        'kandinsky-3':        'ai-forever/kandinsky-3',
        'sdxl-lightning':     'bytedance/sdxl-lightning-4step',
      };
      const version = versionMap[model] || versionMap['flux-schnell'];

      const resp = await axios.post(
        'https://api.replicate.com/v1/predictions',
        {
          version,
          input: {
            prompt: opts.prompt,
            negative_prompt: opts.negative_prompt || undefined,
            num_outputs: opts.count || 1,
            width: parseSize(opts.size).width || 1024,
            height: parseSize(opts.size).height || 1024,
            seed: opts.seed || undefined,
            num_inference_steps: opts.steps || undefined,
            guidance_scale: opts.cfg_scale || undefined,
          },
        },
        {
          headers: {
            Authorization: `Bearer ${process.env.REPLICATE_API_KEY}`,
            'Content-Type': 'application/json',
          },
        }
      );

      // Poll for completion (Replicate is async)
      const predictionId = resp.data.id;
      let prediction = resp.data;

      while (prediction.status !== 'succeeded' && prediction.status !== 'failed' && prediction.status !== 'canceled') {
        await sleep(1000);
        const pollResp = await axios.get(
          `https://api.replicate.com/v1/predictions/${predictionId}`,
          { headers: { Authorization: `Bearer ${process.env.REPLICATE_API_KEY}` } }
        );
        prediction = pollResp.data;
      }

      if (prediction.status === 'failed') {
        throw new Error(`Replicate generation failed: ${prediction.error}`);
      }

      return prediction.output.map((url) => ({
        url,
        model,
        prediction_id: predictionId,
      }));
    }

    // ═══════════════════════════════════════════════
    // Midjourney (via API services)
    // ═══════════════════════════════════════════════
    case 'midjourney': {
      if (!process.env.MIDJOURNEY_API_KEY) {
        throw Object.assign(new Error('Midjourney API key not configured'), { status: 503 });
      }

      // Supports: midjourney-api.com, thenextleg.io, or goapi.ai proxies
      const resp = await axios.post(
        'https://api.midjourneyapi.xyz/v2/imagine',
        {
          prompt: opts.prompt,
          process_mode: 'fast',
          aspect_ratio: opts.aspect_ratio || '1:1',
        },
        {
          headers: {
            'Content-Type': 'application/json',
            'X-API-KEY': process.env.MIDJOURNEY_API_KEY,
          },
          timeout: 120000,
        }
      );

      const taskId = resp.data.task_id;
      // Poll until complete
      let result = resp.data;
      for (let i = 0; i < 60; i++) {
        if (result.status === 'finished' || result.status === 'failed') break;
        await sleep(3000);
        const poll = await axios.get(
          `https://api.midjourneyapi.xyz/v2/result?task_id=${taskId}`,
          { headers: { 'X-API-KEY': process.env.MIDJOURNEY_API_KEY } }
        );
        result = poll.data;
      }

      if (result.status === 'failed') {
        throw new Error(`Midjourney generation failed: ${result.message}`);
      }

      return [{
        url: result.image_url || result.output?.image_url,
        model,
        task_id: taskId,
        upscaled_urls: result.upscaled_photo_url_list || [],
      }];
    }

    // ═══════════════════════════════════════════════
    // Leonardo.ai
    // ═══════════════════════════════════════════════
    case 'leonardo': {
      const modelMap = {
        'leonardo-xl':     '6b3b3f6c-3c7e-4c8a-8e98-f3d6f7c3c5e0',
        'leonardo-vision': '5c962b8e-68fb-412d-a627-16afc7c9c68b',
        'leonardo-phoenix':'c5304db3-5420-4b10-8d7d-78f6f5f5e23f',
        'leonardo-anime':  'e79a9983-41c2-4c8a-b952-1a2a09620c21',
      };
      const modelId = modelMap[model] || modelMap['leonardo-xl'];

      // Start generation
      let genResp;
      try {
        genResp = await axios.post(
          'https://cloud.leonardo.ai/api/rest/v1/generations',
          {
            prompt: opts.prompt,
            negative_prompt: opts.negative_prompt || undefined,
            modelId,
            num_images: opts.count || 1,
            width: parseSize(opts.size).width || 1024,
            height: parseSize(opts.size).height || 1024,
            presetStyle: opts.style === 'vivid' ? 'DYNAMIC' : 'LEONARDO',
          },
          {
            headers: {
              Authorization: `Bearer ${process.env.LEONARDO_API_KEY}`,
              'Content-Type': 'application/json',
            },
          }
        );
      } catch (apiErr) {
        const detail = apiErr.response?.data?.error || apiErr.message;
        throw Object.assign(new Error(`Leonardo.ai: ${detail}`), { status: apiErr.response?.status || 502 });
      }

      // Poll for completion
      const generationId = genResp.data.sdGenerationJob?.generationId;
      let generation = genResp.data.sdGenerationJob;

      for (let i = 0; i < 30; i++) {
        if (generation.status === 'COMPLETE' || generation.status === 'FAILED') break;
        await sleep(2000);
        const poll = await axios.get(
          `https://cloud.leonardo.ai/api/rest/v1/generations/${generationId}`,
          { headers: { Authorization: `Bearer ${process.env.LEONARDO_API_KEY}` } }
        );
        generation = poll.data.generations_by_pk;
      }

      if (generation.status === 'FAILED') {
        throw new Error(`Leonardo.ai generation failed: ${generation.failureReason || 'unknown'}`);
      }

      return generation.generated_images.map((img) => ({
        url: img.url,
        model,
        generation_id: generationId,
        nsfw: img.nsfw || false,
      }));
    }

    // ═══════════════════════════════════════════════
    // Ideogram
    // ═══════════════════════════════════════════════
    case 'ideogram': {
      const modelMap = {
        'ideogram-v2':       'V_2',
        'ideogram-v1':       'V_1',
        'ideogram-v1-turbo': 'V_1_TURBO',
      };
      const ideogramModel = modelMap[model] || 'V_2';

      const resp = await axios.post(
        'https://api.ideogram.ai/generate',
        {
          image_request: {
            prompt: opts.prompt,
            model: ideogramModel,
            aspect_ratio: opts.aspect_ratio || 'ASPECT_1_1',
            num_images: opts.count || 1,
            magic_prompt_option: 'AUTO',
            negative_prompt: opts.negative_prompt || undefined,
            seed: opts.seed || undefined,
          },
        },
        {
          headers: {
            'Api-Key': process.env.IDEOGRAM_API_KEY,
            'Content-Type': 'application/json',
          },
        }
      );

      return resp.data.data.map((img) => ({
        url: img.url,
        model: ideogramModel,
        seed: img.seed,
        is_safe: img.is_image_safe,
      }));
    }

    // ═══════════════════════════════════════════════
    // Getimg.ai
    // ═══════════════════════════════════════════════
    case 'getimg': {
      const pipelineMap = {
        'stable-diffusion-xl': 'stable-diffusion-xl',
        'sd3':                 'stable-diffusion-3',
        'realistic-vision':    'realistic-vision-v6',
        'dreamshaper':         'dreamshaper-xl',
        'juggernaut-xl':       'juggernaut-xl',
        'flux-schnell':        'flux-schnell',
      };
      const pipeline = pipelineMap[model] || 'stable-diffusion-xl';

      const resp = await axios.post(
        'https://api.getimg.ai/v1/stable-diffusion-xl/text-to-image',
        {
          model: pipeline,
          prompt: opts.prompt,
          negative_prompt: opts.negative_prompt || undefined,
          width: parseSize(opts.size).width || 1024,
          height: parseSize(opts.size).height || 1024,
          steps: opts.steps || 25,
          guidance: opts.cfg_scale || 7.5,
          output_format: 'jpeg',
          response_format: 'url',
        },
        {
          headers: {
            Authorization: `Bearer ${process.env.GETIMG_API_KEY}`,
            'Content-Type': 'application/json',
          },
        }
      );

      return [{
        url: resp.data.url || resp.data.image,
        model,
        seed: resp.data.seed,
      }];
    }

    // ═══════════════════════════════════════════════
    // DeepAI
    // ═══════════════════════════════════════════════
    case 'deepai': {
      const styleMap = {
        'deepai-text2img':  'text2img',
        'deepai-fantasy':   'fantasy-world-generator',
        'deepai-cyberpunk': 'cyberpunk-generator',
      };
      const styleId = styleMap[model] || 'text2img';

      const resp = await axios.post(
        `https://api.deepai.org/api/${styleId}`,
        {
          text: opts.prompt,
          grid_size: opts.count > 1 ? '2' : '1',
        },
        {
          headers: {
            'Api-Key': process.env.DEEPAI_API_KEY,
            'Content-Type': 'application/x-www-form-urlencoded',
          },
        }
      );

      return [{
        url: resp.data.output_url,
        model,
      }];
    }

    // ═══════════════════════════════════════════════
    // Adobe Firefly
    // ═══════════════════════════════════════════════
    case 'firefly': {
      // Step 1: Get access token
      const tokenResp = await axios.post(
        'https://ims-na1.adobelogin.com/ims/token/v3',
        new URLSearchParams({
          grant_type: 'client_credentials',
          client_id: process.env.FIREFLY_CLIENT_ID,
          client_secret: process.env.FIREFLY_CLIENT_SECRET,
          scope: 'openid,AdobeID,firefly_api,ff_apis',
        }).toString(),
        { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
      );
      const token = tokenResp.data.access_token;

      // Step 2: Generate image
      const resp = await axios.post(
        'https://firefly-api.adobe.io/v3/images/generate',
        {
          prompt: opts.prompt,
          n: opts.count || 1,
          size: { width: parseSize(opts.size).width || 1024, height: parseSize(opts.size).height || 1024 },
          contentClass: 'photo',
        },
        {
          headers: {
            Authorization: `Bearer ${token}`,
            'X-Api-Key': process.env.FIREFLY_CLIENT_ID,
            'Content-Type': 'application/json',
          },
        }
      );

      return resp.data.outputs.map((out) => ({
        url: out.image?.url,
        model,
        seed: out.seed,
        content_class: out.contentClass,
      }));
    }

    // ═══════════════════════════════════════════════
    // Clipdrop (Stability AI subsidiary)
    // ═══════════════════════════════════════════════
    case 'clipdrop': {
      const resp = await axios.post(
        'https://clipdrop-api.co/text-to-image/v1',
        {
          prompt: opts.prompt,
          negative_prompt: opts.negative_prompt || undefined,
        },
        {
          headers: {
            'x-api-key': process.env.STABILITY_API_KEY,
            'Content-Type': 'application/json',
          },
          responseType: 'arraybuffer',
        }
      );
      const b64 = Buffer.from(resp.data).toString('base64');
      return [{
        b64_json: b64,
        model: 'clipdrop-sdxl',
        mime: resp.headers['content-type'] || 'image/png',
      }];
    }

    default:
      throw Object.assign(new Error(`Unsupported provider: ${providerId}`), { status: 400 });
  }
}

// ── Helpers ──────────────────────────────────────────────
function parseSize(size) {
  if (!size || typeof size !== 'string') return { width: 1024, height: 1024 };
  const [w, h] = size.split('x').map(Number);
  return { width: w || 1024, height: h || 1024 };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// ── Image Optimization ────────────────────────────────────
router.post('/optimize', upload.single('file'), async (req, res) => {
  try {
    if (!req.file) {
      return res.status(400).json({ error: 'No file uploaded' });
    }

    const { width, format = 'webp', quality = 80 } = req.body;
    let pipeline = sharp(req.file.buffer);

    const metadata = await pipeline.metadata();

    if (width) {
      pipeline = pipeline.resize(parseInt(width, 10), undefined, {
        fit: 'inside',
        withoutEnlargement: true,
      });
    }

    let outputBuffer;
    switch (format) {
      case 'webp':
        outputBuffer = await pipeline.webp({ quality: parseInt(quality, 10) }).toBuffer();
        break;
      case 'avif':
        outputBuffer = await pipeline.avif({ quality: parseInt(quality, 10) }).toBuffer();
        break;
      case 'jpeg':
      case 'jpg':
        outputBuffer = await pipeline.jpeg({ quality: parseInt(quality, 10) }).toBuffer();
        break;
      case 'png':
        outputBuffer = await pipeline.png({ quality: parseInt(quality, 10) }).toBuffer();
        break;
      default:
        outputBuffer = await pipeline.webp({ quality: 80 }).toBuffer();
    }

    const outputBase64 = outputBuffer.toString('base64');
    const savings = req.file.size - outputBuffer.length;
    const savingsPercent = ((savings / req.file.size) * 100).toFixed(1);

    res.json({
      success: true,
      original_size: req.file.size,
      optimized_size: outputBuffer.length,
      savings_bytes: savings,
      savings_percent: `${savingsPercent}%`,
      format,
      width: width || metadata.width,
      b64: outputBase64,
    });
  } catch (err) {
    console.error('[Image Optimize]', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── Batch Image Processing ────────────────────────────────
router.post('/batch', upload.array('files', 20), async (req, res) => {
  try {
    if (!req.files || req.files.length === 0) {
      return res.status(400).json({ error: 'No files uploaded' });
    }

    const { format = 'webp', quality = 80, width } = req.body;
    const results = [];

    for (const file of req.files) {
      let pipeline = sharp(file.buffer);
      if (width) {
        pipeline = pipeline.resize(parseInt(width, 10), undefined, {
          fit: 'inside',
          withoutEnlargement: true,
        });
      }

      let outputBuffer;
      switch (format) {
        case 'webp':
          outputBuffer = await pipeline.webp({ quality: parseInt(quality, 10) }).toBuffer();
          break;
        case 'avif':
          outputBuffer = await pipeline.avif({ quality: parseInt(quality, 10) }).toBuffer();
          break;
        default:
          outputBuffer = await pipeline.webp({ quality: 80 }).toBuffer();
      }

      results.push({
        filename: file.originalname,
        original_size: file.size,
        optimized_size: outputBuffer.length,
        savings_percent: ((file.size - outputBuffer.length) / file.size * 100).toFixed(1) + '%',
      });
    }

    const totalSaved = results.reduce((sum, r) => sum + (r.original_size - r.optimized_size), 0);

    res.json({
      success: true,
      processed: results.length,
      total_bytes_saved: totalSaved,
      results,
    });
  } catch (err) {
    console.error('[Batch Optimize]', err.message);
    res.status(500).json({ error: err.message });
  }
});

export { router as imageRouter };
