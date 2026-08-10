/**
 * Code route — formatting + syntax validation.
 *
 * Endpoints:
 *   POST /api/code/format       — format code (prettier)
 *   POST /api/code/check-syntax — validate syntax
 */

import { Router } from 'express';

export const codeRouter = Router();

// ── POST /format — format code with Prettier ────────────────
codeRouter.post('/format', async (req, res) => {
  try {
    const { code, options } = req.body || {};
    if (!code) {
      return res.status(400).json({ success: false, error: 'Missing code' });
    }

    const prettier = await import('prettier');

    const formatOptions = {
      parser: options?.parser || 'babel',
      printWidth: options?.printWidth || 80,
      tabWidth: options?.tabWidth || 2,
      useTabs: options?.useTabs ?? true,
      semi: options?.semi ?? true,
      singleQuote: options?.singleQuote ?? true,
      trailingComma: options?.trailingComma || 'es5',
      bracketSpacing: options?.bracketSpacing ?? true,
      arrowParens: options?.arrowParens || 'always',
      ...options,
    };

    const formatted = await prettier.format(code, formatOptions);

    res.json({ success: true, formatted });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /check-syntax — validate code syntax ──────────────
codeRouter.post('/check-syntax', async (req, res) => {
  try {
    const { code, parser } = req.body || {};
    if (!code) {
      return res.status(400).json({ success: false, error: 'Missing code' });
    }

    const prettier = await import('prettier');
    const valid = await prettier.check(code, { parser: parser || 'babel' });

    res.json({ success: true, valid });
  } catch (err) {
    // Syntax error returns valid: false instead of throwing
    res.json({ success: true, valid: false, error: err.message });
  }
});
