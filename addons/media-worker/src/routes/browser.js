/**
 * Browser route — Puppeteer screenshots + PDF generation.
 *
 * Endpoints:
 *   POST /api/browser/screenshot — capture webpage screenshot
 *   POST /api/browser/pdf        — render webpage to PDF
 *
 * Security: uses the hardened launcher (sandbox kept enabled) and validates
 * every user-supplied URL before navigation (SSRF guard).
 */

import { Router } from 'express';
import fs from 'fs';
import path from 'path';
import os from 'os';
import { launchHardenedBrowser, hardenPage } from '../utils/browser.js';
import { validatePublicUrl } from '../utils/safe-url.js';

export const browserRouter = Router();

function tempFile(ext) {
  return path.join(os.tmpdir(), `browser-${Date.now()}-${Math.random().toString(36).slice(2)}.${ext}`);
}

// ── POST /screenshot — capture webpage screenshot ───────────
browserRouter.post('/screenshot', async (req, res) => {
  let browser;
  try {
    const { url, html, outputPath, options } = req.body || {};
    if (!url && !html) {
      return res.status(400).json({ success: false, error: 'Missing url or html' });
    }

    // SSRF guard: user-supplied URLs must be publicly routable.
    if (url) {
      validatePublicUrl(url);
    }

    browser = await launchHardenedBrowser();
    const page = await browser.newPage();
    await hardenPage(page);

    // Viewport
    if (options?.width || options?.height) {
      await page.setViewport({
        width: options.width || 1280,
        height: options.height || 800,
      });
    }

    // Navigate or set content
    if (url) {
      await page.goto(url, { waitUntil: 'networkidle0', timeout: options?.timeout || 30000 });
    } else {
      await page.setContent(html, { waitUntil: 'networkidle0' });
    }

    const screenshotOpts = {
      type: options?.format || 'png',
      fullPage: options?.fullPage !== false,
      ...(options?.quality ? { quality: options.quality } : {}),
      ...(options?.clip ? { clip: options.clip } : {}),
    };

    const outPath = outputPath || tempFile(screenshotOpts.type);
    await page.screenshot({ path: outPath, ...screenshotOpts });
    await browser.close();

    const stats = fs.statSync(outPath);
    res.json({ success: true, output_path: outPath, size: stats.size, format: screenshotOpts.type });
  } catch (err) {
    if (browser) await browser.close().catch(() => {});
    res.status(err.status && err.status >= 400 && err.status < 600 ? err.status : 500)
      .json({ success: false, error: err.message });
  }
});

// ── POST /pdf — render webpage to PDF ───────────────────────
browserRouter.post('/pdf', async (req, res) => {
  let browser;
  try {
    const { url, html, outputPath, options } = req.body || {};
    if (!url && !html) {
      return res.status(400).json({ success: false, error: 'Missing url or html' });
    }

    // SSRF guard: user-supplied URLs must be publicly routable.
    if (url) {
      validatePublicUrl(url);
    }

    browser = await launchHardenedBrowser();
    const page = await browser.newPage();
    await hardenPage(page);

    if (url) {
      await page.goto(url, { waitUntil: 'networkidle0', timeout: options?.timeout || 30000 });
    } else {
      await page.setContent(html, { waitUntil: 'networkidle0' });
    }

    const pdfOpts = {
      format: options?.format || 'A4',
      printBackground: options?.printBackground !== false,
      landscape: options?.landscape || false,
      margin: options?.margin || { top: '20mm', bottom: '20mm', left: '15mm', right: '15mm' },
      ...options,
    };

    const outPath = outputPath || tempFile('pdf');
    await page.pdf({ path: outPath, ...pdfOpts });
    await browser.close();

    const stats = fs.statSync(outPath);
    res.json({ success: true, output_path: outPath, size: stats.size });
  } catch (err) {
    if (browser) await browser.close().catch(() => {});
    res.status(err.status && err.status >= 400 && err.status < 600 ? err.status : 500)
      .json({ success: false, error: err.message });
  }
});
