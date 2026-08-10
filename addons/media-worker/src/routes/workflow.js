import { Router } from 'express';
import axios from 'axios';
import { getQueue } from '../queue.js';

const router = Router();

// Internal service URLs
const WORKER_URL = `http://localhost:${process.env.PORT || 3100}`;

/**
 * Helper: post to internal endpoints with retry logic.
 */
async function internalPost(endpoint, data, retries = 2) {
  for (let i = 0; i <= retries; i++) {
    try {
      const resp = await axios.post(`${WORKER_URL}${endpoint}`, data, {
        headers: { 'Content-Type': 'application/json' },
        timeout: 120000,
      });
      return resp.data;
    } catch (err) {
      if (i === retries) throw err;
      await new Promise((r) => setTimeout(r, 2000 * (i + 1)));
    }
  }
}

// ── Workflow: Blog Post → Social Media Package ─────────────
//
// When a blog post is published, this workflow:
//   1. Generates a featured image via AI
//   2. Optimizes it for each platform's aspect ratio
//   3. Generates platform-optimized captions
//   4. Publishes to selected social platforms
//
router.post('/social-package', async (req, res) => {
  try {
    const {
      title,
      content,
      platforms = ['twitter', 'linkedin'],
      image_style = 'editorial',
      tone = 'professional',
      async_mode = false,
      callback_url,
    } = req.body;

    if (!title) {
      return res.status(400).json({ error: 'Title is required' });
    }

    if (async_mode) {
      const queue = getQueue('workflow');
      const job = await queue.add('social-package', {
        title,
        content,
        platforms,
        image_style,
        tone,
        callback_url,
      });
      return res.json({
        success: true,
        async: true,
        job_id: job.id,
        message: 'Workflow queued. Poll /api/workflow/status for progress.',
      });
    }

    const results = {
      title,
      steps: {},
      errors: [],
    };

    const excerpt = (content || title).substring(0, 500);

    // Step 1: Generate featured image
    console.log(`[Workflow] Step 1: Generating image for "${title}"`);
    try {
      const imageResult = await internalPost('/api/image/generate', {
        prompt: `${image_style} style featured image for: ${title}. Professional, clean, modern design.`,
        model: process.env.OPENAI_API_KEY ? 'dall-e-3' : 'stable-diffusion',
        size: '1792x1024',
        style: 'vivid',
      });
      results.steps.image_generation = { success: true, ...imageResult };
    } catch (err) {
      results.errors.push({ step: 'image_generation', error: err.message });
      results.steps.image_generation = { success: false, error: err.message };
    }

    // Step 2: Optimize for platform sizes
    const platformSizes = {
      twitter: 1200,
      linkedin: 1200,
      facebook: 1200,
      instagram: 1080,
    };

    const imageUrl = results.steps.image_generation?.url;
    if (imageUrl) {
      console.log('[Workflow] Step 2: Generating platform-optimized sizes');
      results.steps.image_variants = {};
      for (const plat of platforms) {
        try {
          const width = platformSizes[plat] || 1200;
          const optResult = await internalPost('/api/image/optimize-url', {
            url: imageUrl,
            width,
            format: 'webp',
            quality: 85,
          });
          results.steps.image_variants[plat] = optResult;
        } catch (err) {
          results.errors.push({ step: `image_variant_${plat}`, error: err.message });
        }
      }
    }

    // Step 3: Generate social captions
    console.log('[Workflow] Step 3: Generating social captions');
    try {
      const contentResult = await internalPost('/api/social/generate-content', {
        topic: title,
        tone,
        platform: platforms,
        include_hashtags: true,
        count: 1,
      });
      results.steps.content_generation = contentResult;
    } catch (err) {
      results.errors.push({ step: 'content_generation', error: err.message });
    }

    // Step 4: Publish to each platform
    console.log(`[Workflow] Step 4: Publishing to ${platforms.join(', ')}`);
    results.steps.publishing = {};

    const captions = results.steps.content_generation?.content || [];
    for (const plat of platforms) {
      try {
        const variant = captions.find((c) => c.platform === plat);
        const caption = variant?.variants?.[0] || `${title}\n\n${excerpt}`;
        const platImage = results.steps.image_variants?.[plat]?.url || imageUrl;

        const publishResult = await internalPost('/api/social/post', {
          platform: plat,
          content: caption,
          media_url: platImage,
          media_type: 'image',
        });

        results.steps.publishing[plat] = publishResult;
        console.log(`[Workflow] Published to ${plat}: ${publishResult.success ? '✅' : '❌'}`);
      } catch (err) {
        results.errors.push({ step: `publish_${plat}`, error: err.message });
        results.steps.publishing[plat] = { success: false, error: err.message };
      }
    }

    // Callback
    if (callback_url) {
      try {
        await axios.post(callback_url, results, { timeout: 10000 });
      } catch (err) {
        console.warn('[Workflow] Callback failed:', err.message);
      }
    }

    res.json({
      success: results.errors.length === 0,
      workflow: 'social-package',
      title,
      platforms_published: platforms.filter((p) => results.steps.publishing[p]?.success).length,
      total_platforms: platforms.length,
      errors: results.errors,
      steps: results.steps,
    });
  } catch (err) {
    console.error('[Workflow:social-package]', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── Workflow: Batch Brand Asset Generation ─────────────────
//
// Generates a complete brand kit:
//   - Logo concepts (multiple styles)
//   - Social media avatars and banners
//   - Business card designs
//   - Color palette extraction
//
router.post('/brand-assets', async (req, res) => {
  try {
    const {
      brand_name,
      style = 'minimalist',
      color_palette = [],
      num_concepts = 3,
      async_mode = false,
      callback_url,
    } = req.body;

    if (!brand_name) {
      return res.status(400).json({ error: 'brand_name is required' });
    }

    if (async_mode) {
      const queue = getQueue('workflow');
      const job = await queue.add('brand-assets', {
        brand_name,
        style,
        color_palette,
        num_concepts,
        callback_url,
      });
      return res.json({
        success: true,
        async: true,
        job_id: job.id,
        message: `Brand asset generation queued for "${brand_name}".`,
      });
    }

    const results = {
      brand_name,
      style,
      assets: {},
      errors: [],
    };

    const colorHint = color_palette.length > 0
      ? `Use color palette: ${color_palette.join(', ')}.`
      : '';

    // Step 1: Generate logo concepts
    console.log(`[Workflow] Generating ${num_concepts} logo concepts for "${brand_name}"`);
    results.assets.logos = [];

    const logoPrompts = [
      `minimalist lettermark logo for "${brand_name}", clean lines, ${style} style. ${colorHint}`,
      `modern abstract icon logo for "${brand_name}", geometric shapes, ${style} style. ${colorHint}`,
      `elegant wordmark typography logo for "${brand_name}", sophisticated, ${style} style. ${colorHint}`,
    ];

    for (let i = 0; i < Math.min(num_concepts, logoPrompts.length); i++) {
      try {
        const logoResult = await internalPost('/api/image/generate', {
          prompt: logoPrompts[i],
          model: process.env.OPENAI_API_KEY ? 'dall-e-3' : 'stable-diffusion',
          size: '1024x1024',
        });
        results.assets.logos.push({
          concept: i + 1,
          style: ['Lettermark', 'Icon', 'Wordmark'][i],
          ...logoResult,
        });
      } catch (err) {
        results.errors.push({ step: `logo_${i + 1}`, error: err.message });
      }
    }

    // Step 2: Generate social avatar (400x400)
    if (results.assets.logos.length > 0) {
      const logoUrl = results.assets.logos[0]?.url;
      try {
        if (logoUrl) {
          const avatar = await internalPost('/api/image/optimize-url', {
            url: logoUrl,
            width: 400,
            height: 400,
            format: 'webp',
          });
          results.assets.social_avatar = avatar;
        }
      } catch (err) {
        results.errors.push({ step: 'social_avatar', error: err.message });
      }

      // Step 3: Generate social banner (1500x500)
      try {
        const banner = await internalPost('/api/image/generate', {
          prompt: `Horizontal banner for "${brand_name}", ${style} style, modern design, suitable for Twitter/LinkedIn header. ${colorHint}`,
          model: process.env.OPENAI_API_KEY ? 'dall-e-3' : 'stable-diffusion',
          size: '1792x1024',
        });

        if (banner?.url) {
          const bannerOptimized = await internalPost('/api/image/optimize-url', {
            url: banner.url,
            width: 1500,
            height: 500,
            format: 'webp',
          });
          results.assets.banner = bannerOptimized;
        }
      } catch (err) {
        results.errors.push({ step: 'banner', error: err.message });
      }
    }

    // Callback
    if (callback_url) {
      try {
        await axios.post(callback_url, results, { timeout: 10000 });
      } catch (err) {
        console.warn('[Workflow] Callback failed:', err.message);
      }
    }

    res.json({
      success: results.errors.length === 0,
      workflow: 'brand-assets',
      brand_name,
      assets_generated: Object.keys(results.assets).filter((k) => results.assets[k]?.length !== 0 || results.assets[k]?.url).length,
      total_assets: 3,
      errors: results.errors,
      assets: results.assets,
    });
  } catch (err) {
    console.error('[Workflow:brand-assets]', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── Workflow: Video Production Pipeline ────────────────────
//
// When a raw video is uploaded:
//   1. Generate AI thumbnail poster
//   2. Compress for web
//   3. Create platform-specific cuts (1:1, 9:16, 16:9)
//   4. Generate GIF preview
//
router.post('/video-pipeline', async (req, res) => {
  try {
    const {
      video_url,
      title,
      platforms = ['instagram', 'linkedin'],
      generate_gif = true,
      async_mode = true,  // Video is heavy, default to async
      callback_url,
    } = req.body;

    if (!video_url) {
      return res.status(400).json({ error: 'video_url is required' });
    }

    if (async_mode) {
      const queue = getQueue('workflow');
      const job = await queue.add('video-pipeline', {
        video_url,
        title,
        platforms,
        generate_gif,
        callback_url,
      });
      return res.json({
        success: true,
        async: true,
        job_id: job.id,
        message: 'Video pipeline queued. Processing may take several minutes.',
        tip: 'Video processing is CPU-intensive and may take 2-5 minutes per variant.',
      });
    }

    // Sync mode — process inline
    const results = {
      title,
      steps: {},
      errors: [],
    };

    // Step 1: Compress for web
    console.log('[Workflow] Step 1: Compressing video');
    results.steps.compression = {
      note: 'Compression requires file upload to /api/video/process. For URL-based workflows, the video will be downloaded and processed.',
      tip: 'Use /api/video/process with multipart upload for synchronous compression.',
    };

    // Step 2: Generate poster/thumbnail via AI
    if (title) {
      try {
        const poster = await internalPost('/api/image/generate', {
          prompt: `Video thumbnail poster: ${title}. Eye-catching, high contrast, professional YouTube-style thumbnail.`,
          model: process.env.OPENAI_API_KEY ? 'dall-e-3' : 'stable-diffusion',
          size: '1792x1024',
        });
        results.steps.poster = poster;
      } catch (err) {
        results.errors.push({ step: 'poster', error: err.message });
      }
    }

    // Step 3: Social cuts
    results.steps.social_cuts = {
      note: 'For social media video cuts (1:1, 9:16, 16:9), upload the video file to /api/video/process with the desired resize dimensions.',
      platforms: {
        instagram: { aspect: '1:1 or 4:5', size: '1080x1080' },
        tiktok_reels: { aspect: '9:16', size: '1080x1920' },
        youtube: { aspect: '16:9', size: '1920x1080' },
        linkedin: { aspect: '1:1 or 16:9', size: '1080x1080' },
      },
    };

    if (callback_url) {
      try {
        await axios.post(callback_url, results, { timeout: 10000 });
      } catch (err) {
        console.warn('[Workflow] Callback failed:', err.message);
      }
    }

    res.json({
      success: results.errors.length === 0,
      workflow: 'video-pipeline',
      title,
      errors: results.errors,
      steps: results.steps,
    });
  } catch (err) {
    console.error('[Workflow:video-pipeline]', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── Workflow Status ────────────────────────────────────────
router.get('/status', async (_req, res) => {
  try {
    const imageQueue = getQueue('image-generation');
    const socialQueue = getQueue('social-scheduled');
    const workflowQueue = getQueue('workflow');

    const [imageStats, socialStats, workflowStats] = await Promise.all([
      imageQueue.getStats(),
      socialQueue.getStats(),
      workflowQueue.getStats(),
    ]);

    res.json({
      status: 'ok',
      queues: {
        image_generation: imageStats,
        social_scheduled: socialStats,
        workflow: workflowStats,
      },
      worker: {
        uptime: process.uptime(),
        memory: process.memoryUsage(),
      },
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

export { router as workflowRouter };
