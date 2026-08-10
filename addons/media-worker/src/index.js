import express from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import { imageRouter } from './routes/image.js';
import { videoRouter } from './routes/video.js';
import { socialRouter } from './routes/social.js';
import { workflowRouter } from './routes/workflow.js';
import { pdfRouter } from './routes/pdf.js';
import { documentRouter } from './routes/document.js';
import { ocrRouter } from './routes/ocr.js';
import { emailRouter } from './routes/email.js';
import { codeRouter } from './routes/code.js';
import { dataRouter } from './routes/data.js';
import { browserRouter } from './routes/browser.js';

dotenv.config();

const app = express();
const PORT = process.env.PORT || 3100;

// Middleware
app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// ── Routes ─────────────────────────────────────────────────
app.use('/api/image', imageRouter);
app.use('/api/video', videoRouter);
app.use('/api/social', socialRouter);
app.use('/api/workflow', workflowRouter);
app.use('/api/pdf', pdfRouter);
app.use('/api/document', documentRouter);
app.use('/api/ocr', ocrRouter);
app.use('/api/email', emailRouter);
app.use('/api/code', codeRouter);
app.use('/api/data', dataRouter);
app.use('/api/browser', browserRouter);

// ── Health check ────────────────────────────────────────────
app.get('/api/health', (_req, res) => {
  const providers = {
    openai:     !!process.env.OPENAI_API_KEY,
    gemini:     !!process.env.GEMINI_API_KEY,
    stability:  !!process.env.STABILITY_API_KEY,
    replicate:  !!process.env.REPLICATE_API_KEY,
    midjourney: !!process.env.MIDJOURNEY_API_KEY,
    leonardo:   !!process.env.LEONARDO_API_KEY,
    ideogram:   !!process.env.IDEOGRAM_API_KEY,
    getimg:     !!process.env.GETIMG_API_KEY,
    deepai:     !!process.env.DEEPAI_API_KEY,
    firefly:    !!(process.env.FIREFLY_CLIENT_ID && process.env.FIREFLY_CLIENT_SECRET),
    clipdrop:   !!process.env.STABILITY_API_KEY,
    anthropic:  !!process.env.ANTHROPIC_API_KEY,
  };

  const social = {
    twitter:   !!(process.env.TWITTER_API_KEY && process.env.TWITTER_ACCESS_TOKEN),
    facebook:  !!process.env.FACEBOOK_PAGE_TOKEN,
    instagram: !!process.env.INSTAGRAM_ACCESS_TOKEN,
    linkedin:  !!process.env.LINKEDIN_TOKEN,
  };

  res.json({
    status: 'ok',
    service: 'design-media-worker',
    version: '2.1.0',
    uptime: process.uptime(),
    environment: process.env.NODE_ENV || 'development',
    capabilities: {
      // AI generation
      ai_image_generation: Object.values(providers).some(Boolean),
      ai_providers: providers,
      ai_content: !!process.env.OPENAI_API_KEY,

      // Media processing
      image_optimization: true,
      video_generation: !!process.env.REPLICATE_API_KEY,
      video_processing: true,

      // Document pipeline
      pdf_extraction: true,
      pdf_generation: true,
      pdf_rendering: true,
      document_excel: true,
      document_word: true,
      document_ocr: true,

      // Utilities
      code_formatting: true,
      email: true,
      translation: true,
      language_detection: true,
      qrcode: true,
      math_rendering: true,
      calendar_ics: true,
      chart_rendering: true,
      geospatial: true,
      browser_automation: true,

      // Social
      social_publishing: Object.values(social).some(Boolean),
      social_platforms: social,

      // Infrastructure
      workflows: true,
      job_queue: !!process.env.REDIS_URL,
    },
    endpoints: {
      image:    ['/api/image/generate', '/api/image/optimize', '/api/image/optimize-batch', '/api/image/providers'],
      video:    ['/api/video/generate', '/api/video/process', '/api/video/info', '/api/video/models', '/api/video/prediction/:id'],
      social:   ['/api/social/post', '/api/social/generate-content', '/api/social/accounts'],
      workflow: ['/api/workflow/social-package', '/api/workflow/brand-assets', '/api/workflow/video-pipeline', '/api/workflow/status'],
      pdf:      ['/api/pdf/extract', '/api/pdf/render', '/api/pdf/generate', '/api/pdf/merge', '/api/pdf/watermark'],
      document: ['/api/document/excel', '/api/document/word'],
      ocr:      ['/api/ocr/recognize'],
      email:    ['/api/email/send', '/api/email/compile-mjml'],
      code:     ['/api/code/format', '/api/code/check-syntax'],
      data:     ['/api/data/translate', '/api/data/language-detect', '/api/data/qrcode', '/api/data/render-math', '/api/data/generate-ics', '/api/data/render-chart', '/api/data/analyze-geospatial'],
      browser:  ['/api/browser/screenshot', '/api/browser/pdf'],
    },
  });
});

// ── Error handler ───────────────────────────────────────────
app.use((err, _req, res, _next) => {
  console.error('[Worker Error]', err.message);
  res.status(err.status || 500).json({
    error: err.message || 'Internal server error',
  });
});

// ── Startup ─────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`[Design Worker] Running on http://localhost:${PORT}`);
  console.log('');
  console.log('[Design Worker] AI Image providers:');
  console.log('  OpenAI:',     process.env.OPENAI_API_KEY ? '✅ DALL·E 3' : '❌');
  console.log('  Gemini:',     process.env.GEMINI_API_KEY ? '✅ Imagen / Flash' : '❌');
  console.log('  Stability:',  process.env.STABILITY_API_KEY ? '✅ SDXL / SD3' : '❌');
  console.log('  Replicate:',  process.env.REPLICATE_API_KEY ? '✅ Flux / SDXL / Video' : '❌');
  console.log('  Midjourney:', process.env.MIDJOURNEY_API_KEY ? '✅ v6 / Niji' : '❌');
  console.log('');
  console.log('[Design Worker] Document pipeline:');
  console.log('  PDF extract:   ✅ pdf-parse + pdfjs-dist');
  console.log('  PDF generate:  ✅ puppeteer + pdfkit');
  console.log('  Excel/Word:    ✅ exceljs + docx');
  console.log('  OCR:           ✅ tesseract.js');
  console.log('  Browser:       ✅ puppeteer (chromium)');
  console.log('  Code format:   ✅ prettier');
  console.log('  Email:         ✅ nodemailer + mjml');
  console.log('');
  console.log('[Design Worker] Social platforms:');
  console.log('  Twitter:',   process.env.TWITTER_ACCESS_TOKEN ? '✅' : '❌');
  console.log('  Facebook:',  process.env.FACEBOOK_PAGE_TOKEN ? '✅' : '❌');
  console.log('  Instagram:', process.env.INSTAGRAM_ACCESS_TOKEN ? '✅' : '❌');
  console.log('  LinkedIn:',  process.env.LINKEDIN_TOKEN ? '✅' : '❌');
  console.log('');
  console.log('[Design Worker] Job Queue:', process.env.REDIS_URL ? '✅ Redis' : '⚠️  in-memory');
});
