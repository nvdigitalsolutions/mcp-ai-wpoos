/**
 * OCR route — optical character recognition via tesseract.js.
 *
 * Endpoints:
 *   POST /api/ocr/recognize — run OCR on an image
 */

import { Router } from 'express';
import fs from 'fs';
import Tesseract from 'tesseract.js';

export const ocrRouter = Router();

// ── POST /recognize — OCR text from image ──────────────────
ocrRouter.post('/recognize', async (req, res) => {
  let worker = null;
  try {
    const { source, language, options } = req.body || {};
    if (!source) {
      return res.status(400).json({ success: false, error: 'Missing source path' });
    }
    if (!fs.existsSync(source)) {
      return res.status(404).json({ success: false, error: `File not found: ${source}` });
    }

    const lang = language || 'eng';
    const workerOpts = { workerBlob: false };
    if (options?.verbose) {
      workerOpts.logger = (m) => {
        if (m.status === 'recognizing text') console.log(`[OCR] ${Math.round(m.progress * 100)}%`);
      };
    }
    worker = await Tesseract.createWorker(lang, 1, workerOpts);

    const { data } = await worker.recognize(source);

    res.json({
      success: true,
      text: data.text,
      confidence: data.confidence,
      language: lang,
      words: data.words?.length || 0,
      lines: data.lines?.length || 0,
    });
  } catch (err) {
    console.error('[OCR] Error:', err.message);
    res.status(500).json({ success: false, error: err.message });
  } finally {
    if (worker) {
      await worker.terminate().catch(() => {});
    }
  }
});
