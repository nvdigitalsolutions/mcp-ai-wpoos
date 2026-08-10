/**
 * Data utilities — translation, language detection, QR codes.
 *
 * Endpoints:
 *   POST /api/data/translate        — translate text (google-translate-api-x)
 *   POST /api/data/language-detect  — detect language (franc)
 *   POST /api/data/qrcode           — generate QR code (qrcode)
 */

import { Router } from 'express';
import fs from 'fs';
import path from 'path';
import os from 'os';

export const dataRouter = Router();

function tempFile(ext) {
  return path.join(os.tmpdir(), `data-${Date.now()}-${Math.random().toString(36).slice(2)}.${ext}`);
}

// ── POST /translate ─────────────────────────────────────────
dataRouter.post('/translate', async (req, res) => {
  try {
    const { text, to, from } = req.body || {};
    if (!text || !to) {
      return res.status(400).json({ success: false, error: 'Missing text or to language' });
    }

    const { translate } = await import('google-translate-api-x');

    const result = await translate(text, {
      to,
      from: from || undefined,
      autoCorrect: true,
    });

    res.json({
      success: true,
      translated: result.text,
      from: result.from?.language?.iso || from || 'auto',
      to,
      original: text,
      alternatives: result.raw?.alternative_translations || [],
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /language-detect ──────────────────────────────────
dataRouter.post('/language-detect', async (req, res) => {
  try {
    const { text } = req.body || {};
    if (!text) {
      return res.status(400).json({ success: false, error: 'Missing text' });
    }

    const { franc } = await import('franc');
    const iso6391 = await import('iso-639-1');

    const langCode = franc(text, { minLength: 3 });
    const langName = iso6391.default.getName(langCode) || langCode;
    const langNative = iso6391.default.getNativeName(langCode) || langCode;

    res.json({
      success: true,
      code: langCode,
      name: langName,
      native: langNative,
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /qrcode — generate QR code ────────────────────────
dataRouter.post('/qrcode', async (req, res) => {
  try {
    const { text, outputPath, options } = req.body || {};
    if (!text) {
      return res.status(400).json({ success: false, error: 'Missing text' });
    }

    const QRCode = (await import('qrcode')).default;

    const qrOpts = {
      type: options?.format || 'png',
      width: options?.width || 256,
      margin: options?.margin || 2,
      color: {
        dark: options?.colorDark || '#000000',
        light: options?.colorLight || '#ffffff',
      },
      errorCorrectionLevel: options?.errorCorrection || 'M',
    };

    const outPath = outputPath || tempFile(qrOpts.type);

    if (qrOpts.type === 'svg') {
      const svg = await QRCode.toString(text, { ...qrOpts, type: 'svg' });
      fs.writeFileSync(outPath, svg);
    } else {
      await QRCode.toFile(outPath, text, qrOpts);
    }

    const stats = fs.statSync(outPath);

    res.json({
      success: true,
      output_path: outPath,
      size: stats.size,
      format: qrOpts.type,
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /render-math — KaTeX equation rendering ────────────
dataRouter.post('/render-math', async (req, res) => {
  try {
    const { latex, options } = req.body || {};
    if (!latex) {
      return res.status(400).json({ success: false, error: 'Missing latex' });
    }
    const katex = (await import('katex')).default;
    const html = katex.renderToString(latex, {
      throwOnError: false,
      displayMode: options?.displayMode ?? true,
      output: options?.output || 'html',
    });
    res.json({ success: true, html, latex });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /generate-ics — Calendar ICS file generation ───────
dataRouter.post('/generate-ics', async (req, res) => {
  try {
    const { events, options } = req.body || {};
    if (!events || !Array.isArray(events)) {
      return res.status(400).json({ success: false, error: 'Missing events array' });
    }
    const icsLib = await import('ics');
    const { error, value } = icsLib.createEvents(events);
    if (error) {
      return res.status(400).json({ success: false, error: error.message || 'ICS generation failed' });
    }
    const outPath = tempFile('ics');
    fs.writeFileSync(outPath, value);
    res.json({ success: true, ics: value, output_path: outPath, event_count: events.length });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /render-chart — Chart.js chart rendering ───────────
dataRouter.post('/render-chart', async (req, res) => {
  try {
    const { type, data, options } = req.body || {};
    if (!type || !data) {
      return res.status(400).json({ success: false, error: 'Missing type or data' });
    }
    const { ChartJSNodeCanvas } = await import('chartjs-node-canvas');
    const width = options?.width || 800;
    const height = options?.height || 400;
    const renderer = new ChartJSNodeCanvas({ width, height, backgroundColour: options?.background || 'white' });
    const config = { type, data, options: options?.chartOptions || {} };
    const image = await renderer.renderToBuffer(config);
    const outPath = tempFile('png');
    fs.writeFileSync(outPath, image);
    res.json({ success: true, output_path: outPath, size: image.length, width, height });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /analyze-geospatial — Turf.js geospatial analysis ──
dataRouter.post('/analyze-geospatial', async (req, res) => {
  try {
    const { operation, geojson, params } = req.body || {};
    if (!operation || !geojson) {
      return res.status(400).json({ success: false, error: 'Missing operation or geojson' });
    }
    const turf = await import('@turf/turf');
    const fn = turf[operation];
    if (typeof fn !== 'function') {
      return res.status(400).json({ success: false, error: `Unknown turf operation: ${operation}` });
    }
    const args = params || [];
    const result = fn(geojson, ...(Array.isArray(args) ? args : [args]));
    res.json({ success: true, operation, result });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});
