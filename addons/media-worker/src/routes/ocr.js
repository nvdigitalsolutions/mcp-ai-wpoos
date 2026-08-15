/**
 * OCR route — optical character recognition via tesseract.js.
 *
 * Endpoints:
 *   POST /api/ocr/recognize — run OCR on an image
 */

import { Router } from 'express';
import multer from 'multer';
import fs from 'fs';
import Tesseract from 'tesseract.js';

export const ocrRouter = Router();

// Images are small — memory storage keeps the route stateless (no temp
// file cleanup needed). The legacy JSON `source` path remains supported
// for shared-volume deployments.
const upload = multer({
	storage: multer.memoryStorage(),
	limits: { fileSize: 15 * 1024 * 1024 },
});

// ── POST /recognize — OCR text from image ──────────────────
ocrRouter.post('/recognize', upload.single('file'), async (req, res) => {
  let worker = null;
  try {
    const { source, language, options } = req.body || {};

    // Multipart upload (plugin contract) takes precedence over the legacy
    // worker-side `source` path.
    let input = source || '';
    if (req.file && req.file.buffer) {
      input = req.file.buffer;
    }
    if (!input) {
      return res.status(400).json({ success: false, error: 'Missing source path or file upload' });
    }
    if (typeof input === 'string' && !fs.existsSync(input)) {
      return res.status(404).json({ success: false, error: `File not found: ${input}` });
    }

    const lang = language || 'eng';
    const workerOpts = { workerBlob: false };
    if (options?.verbose) {
      workerOpts.logger = (m) => {
        if (m.status === 'recognizing text') console.log(`[OCR] ${Math.round(m.progress * 100)}%`);
      };
    }
    worker = await Tesseract.createWorker(lang, 1, workerOpts);

    const { data } = await worker.recognize(input);

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
