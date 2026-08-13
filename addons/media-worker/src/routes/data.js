/**
 * Data utilities — translation, language detection, QR codes, phone formatting,
 * CSV processing, markdown, math evaluation, regression, currency, validation.
 *
 * Endpoints:
 *   POST /api/data/translate        — translate text (google-translate-api-x)
 *   POST /api/data/language-detect  — detect language (franc)
 *   POST /api/data/phone-format     — format/validate phone (libphonenumber-js)
 *   POST /api/data/qrcode           — generate QR code (qrcode)
 *   POST /api/data/csv-parse        — parse CSV string (csv-parse)
 *   POST /api/data/csv-generate     — generate CSV from JSON (csv-stringify)
 *   POST /api/data/markdown         — markdown → HTML (marked)
 *   POST /api/data/math             — evaluate math expressions (mathjs)
 *   POST /api/data/regression       — statistical regression (regression)
 *   POST /api/data/currency         — currency conversion/format (currency.js)
 *   POST /api/data/validate         — string validation (validator)
 */

import { Router } from 'express';
import fs from 'fs';
import path from 'path';
import { siteBaseDir, pathGuard } from '../utils/site-paths.js';

export const dataRouter = Router();

function tempFile( req, ext ) {
	return path.join( siteBaseDir( req.site ), `data-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.${ ext }` );
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

  // ── POST /phone-format — format/validate phone number ───────
  dataRouter.post('/phone-format', async (req, res) => {
    try {
      const { phone, country_code } = req.body || {};
      if (!phone) {
        return res.status(400).json({ success: false, error: 'Missing phone' });
      }

      const { parsePhoneNumber } = await import('libphonenumber-js');
      const parsed = parsePhoneNumber(phone, (country_code || 'US').toUpperCase());

      if (!parsed || !parsed.isValid()) {
        return res.json({
          success: true,
          formatted: phone,
          national: phone.replace(/[^0-9]/g, ''),
          international: phone,
          valid: false,
          country: country_code || 'US',
        });
      }

      res.json({
        success: true,
        formatted: parsed.formatInternational(),
        national: parsed.formatNational(),
        international: parsed.formatInternational(),
        valid: true,
        country: parsed.country || country_code,
        type: parsed.getType(),
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

    const outPath = pathGuard( req.site, outputPath ) || tempFile( req, qrOpts.type );

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
    const outPath = tempFile( req, 'ics' );
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
    const outPath = tempFile( req, 'png' );
    fs.writeFileSync(outPath, image);
    res.json({ success: true, output_path: outPath, size: image.length, width, height });
  } catch (err) {
    if ('ERR_MODULE_NOT_FOUND' === err.code || 'ERR_DLOPEN_FAILED' === err.code) {
      return res.status(503).json({
        error: 'capability_unavailable',
        capability: 'chart-rendering',
        message: 'Chart rendering is unavailable: chartjs-node-canvas or its native canvas dependency is not installed on this server.',
      });
    }
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

// ── POST /csv-parse — parse CSV string to JSON ──────────────
dataRouter.post('/csv-parse', async (req, res) => {
  try {
    const { csv, options } = req.body || {};
    if (!csv) {
      return res.status(400).json({ success: false, error: 'Missing csv' });
    }
    const { parse } = await import('csv-parse');
    const records = [];
    const parser = parse({
      columns: options?.columns !== false,
      skip_empty_lines: true,
      trim: true,
      ...options,
    });
    parser.write(csv);
    parser.end();
    for await (const record of parser) {
      records.push(record);
    }
    res.json({ success: true, rows: records, count: records.length });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /csv-generate — generate CSV from JSON array ───────
dataRouter.post('/csv-generate', async (req, res) => {
  try {
    const { data, columns, options } = req.body || {};
    if (!data || !Array.isArray(data)) {
      return res.status(400).json({ success: false, error: 'Missing data array' });
    }
    const { stringify } = await import('csv-stringify');
    const csv = await new Promise((resolve, reject) => {
      stringify(data, { header: true, columns: columns || undefined, ...options }, (err, output) => {
        if (err) reject(err);
        else resolve(output);
      });
    });
    const outPath = tempFile( req, 'csv' );
    fs.writeFileSync(outPath, csv);
    res.json({ success: true, csv, output_path: outPath, rows: data.length });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /markdown — convert Markdown to HTML ───────────────
dataRouter.post('/markdown', async (req, res) => {
  try {
    const { text, options } = req.body || {};
    if (!text) {
      return res.status(400).json({ success: false, error: 'Missing text' });
    }
    const { marked } = await import('marked');
    const html = await marked.parse(text, {
      gfm: true,
      breaks: false,
      ...options,
    });
    res.json({ success: true, html, markdown: text });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /math — evaluate math expression ───────────────────
dataRouter.post('/math', async (req, res) => {
  try {
    const { expression, scope } = req.body || {};
    if (!expression) {
      return res.status(400).json({ success: false, error: 'Missing expression' });
    }
    const mathjs = await import('mathjs');
    const result = mathjs.evaluate(expression, scope || {});
    res.json({ success: true, expression, result, formatted: mathjs.format(result, { precision: 14 }) });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /regression — statistical regression analysis ──────
dataRouter.post('/regression', async (req, res) => {
  try {
    const { data, type, options } = req.body || {};
    if (!data || !Array.isArray(data)) {
      return res.status(400).json({ success: false, error: 'Missing data array of [x,y] pairs' });
    }
    const regression = await import('regression');
    const method = regression[type] || regression.linear;
    const result = method(data, { order: options?.order || 2, precision: options?.precision || 4 });
    res.json({
      success: true,
      type: type || 'linear',
      equation: result.string,
      points: result.points,
      r2: result.r2,
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /currency — format currencies ──────────────────────
dataRouter.post('/currency', async (req, res) => {
  try {
    const { amount, format: fmt } = req.body || {};
    if (amount === undefined) {
      return res.status(400).json({ success: false, error: 'Missing amount' });
    }
    const currency = (await import('currency.js')).default;
    const result = currency(amount, { fromCents: false });
    res.json({
      success: true,
      amount,
      formatted: fmt ? result.format() : result.toString(),
      value: result.value,
      intValue: result.intValue,
      cents: result.cents(),
      dollars: result.dollars(),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /validate — validate strings (email, URL, ISBN, etc.) ─
dataRouter.post('/validate', async (req, res) => {
  try {
    const { value, type, options } = req.body || {};
    if (!value || !type) {
      return res.status(400).json({ success: false, error: 'Missing value or type' });
    }
    const validator = await import('validator');
    const fn = validator.default[type] || validator[type];
    if (typeof fn !== 'function') {
      return res.status(400).json({ success: false, error: `Unknown validator: ${type}` });
    }
    const valid = fn(String(value), options);
    res.json({ success: true, type, valid, value });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});
