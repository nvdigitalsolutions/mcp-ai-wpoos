/**
 * Document route — Excel + Word generation.
 *
 * Endpoints:
 *   POST /api/document/excel — generate Excel spreadsheet (exceljs)
 *   POST /api/document/word  — generate Word document (docx)
 */

import { Router } from 'express';
import fs from 'fs';
import path from 'path';
import { siteDirFor, pathGuard } from '../utils/site-paths.js';

export const documentRouter = Router();

function tempFile( req, ext ) {
	return path.join( siteDirFor( req.site, 'doc' ), `doc-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.${ ext }` );
}

// ── POST /excel — generate Excel spreadsheet ───────────────

/** A1-notation cell reference: A1, D2, AB12 — bounded so a typo cannot address
 *  a cell a spreadsheet has no room for. */
const CELL_REF = /^[A-Z]{1,3}[1-9][0-9]{0,6}$/;

documentRouter.post('/excel', async (req, res) => {
  try {
    const { sheets, outputPath, options } = req.body || {};
    if (!sheets) {
      return res.status(400).json({ success: false, error: 'Missing sheets data' });
    }

    const ExcelJS = (await import('exceljs')).default;
    const workbook = new ExcelJS.Workbook();

    if (options?.creator) workbook.creator = options.creator;
    if (options?.title) workbook.title = options.title;

    for (const sheetDef of sheets) {
      const ws = workbook.addWorksheet(sheetDef.name || 'Sheet1');

      // Columns
      if (sheetDef.columns) {
        ws.columns = sheetDef.columns.map((col) => ({
          header: typeof col === 'string' ? col : col.header,
          key: typeof col === 'string' ? col : col.key || col.header,
          width: typeof col === 'object' ? col.width || 15 : 15,
        }));
      }

      // Rows (array of arrays or array of objects)
      if (sheetDef.rows) {
        for (const row of sheetDef.rows) {
          ws.addRow(Array.isArray(row) ? row : row);
        }
      }

      // Freeze panes. `views` is ExcelJS's own shape, passed through
      // unchanged: [{ state: 'frozen', xSplit: 0, ySplit: 1, topLeftCell: 'A2' }]
      if (Array.isArray(sheetDef.views) && sheetDef.views.length > 0) {
        ws.views = sheetDef.views;
      }

      // Auto-filter. ExcelJS takes a range string ('A1:D1') or {from, to}.
      if (sheetDef.autoFilter) {
        ws.autoFilter = sheetDef.autoFilter;
      }

      // Formulas are an explicit channel, never inferred from a value.
      //
      // This is the injection boundary. OWASP's CSV-injection guidance and the
      // XLSX guidance both land on the same rule: a caller's *data* must be
      // written as a typed string, and a formula must be something the caller
      // asked for by name. Values arrive through `rows` above and are written
      // by ExcelJS as string cells, so `=1+1` in a data cell stays the text
      // `=1+1`. Anything in this map is deliberately executable, which is why
      // it is a separate argument and why the reference is validated.
      if (sheetDef.formulas && typeof sheetDef.formulas === 'object') {
        for (const [ref, formula] of Object.entries(sheetDef.formulas)) {
          if (!CELL_REF.test(String(ref))) {
            return res.status(400).json({
              success: false,
              error: `Invalid cell reference: ${ref}`,
            });
          }

          const body = String(formula == null ? '' : formula).replace(/^=/, '');

          if ('' === body || /[\x00-\x1f]/.test(body)) {
            return res.status(400).json({
              success: false,
              error: `Invalid formula at ${ref}`,
            });
          }

          ws.getCell(String(ref)).value = { formula: body };
        }
      }

      // Styling from options
      if (sheetDef.style) {
        const headerRow = ws.getRow(1);
        headerRow.font = { bold: true, ...sheetDef.style.headerFont };
        if (sheetDef.style.headerFill) {
          headerRow.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: sheetDef.style.headerFill },
          };
        }
      }
    }

    const outPath = pathGuard( req.site, outputPath ) || tempFile( req, 'xlsx' );
    await workbook.xlsx.writeFile(outPath);
    const stats = fs.statSync(outPath);

    res.json({
      success: true,
      output_path: outPath,
      size: stats.size,
      sheets: workbook.worksheets.length,
      // data_base64 is the plugin contract: output_path points at the
      // WORKER's filesystem and is unusable by the calling site.
      data_base64: fs.readFileSync(outPath).toString('base64'),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ── POST /word — generate Word document ────────────────────
documentRouter.post('/word', async (req, res) => {
  try {
    const { content, outputPath, options } = req.body || {};
    if (!content) {
      return res.status(400).json({ success: false, error: 'Missing content' });
    }

    const docxModule = await import('docx');
    const {
      Document, Packer, Paragraph, TextRun, HeadingLevel,
      Table, TableRow, TableCell, AlignmentType, WidthType,
      ImageRun, Header, Footer, PageNumber, NumberFormat,
    } = docxModule;

    const children = [];

    // Title
    if (content.title) {
      children.push(new Paragraph({
        text: content.title,
        heading: HeadingLevel.TITLE,
        alignment: AlignmentType.CENTER,
      }));
    }

    // Paragraphs
    if (content.paragraphs) {
      for (const para of content.paragraphs) {
        if (typeof para === 'string') {
          children.push(new Paragraph({ text: para, spacing: { after: 200 } }));
        } else if (para.heading) {
          children.push(new Paragraph({
            text: para.text || para.heading,
            heading: HeadingLevel[para.level] || HeadingLevel.HEADING_1,
            spacing: { before: 300, after: 150 },
          }));
        } else if (para.runs) {
          children.push(new Paragraph({
            children: para.runs.map((r) =>
              new TextRun({
                text: r.text || '',
                bold: r.bold,
                italics: r.italics,
                underline: r.underline,
                color: r.color,
                size: r.size,
                font: r.font,
              })
            ),
            spacing: { after: 200 },
            alignment: para.alignment ? AlignmentType[para.alignment] : undefined,
          }));
        } else {
          children.push(new Paragraph({
            text: para.text || '',
            spacing: { after: 200 },
            bullet: para.bullet ? { level: para.bulletLevel || 0 } : undefined,
          }));
        }
      }
    }

    // Table
    if (content.table) {
      const { headers, rows } = content.table;
      const tableRows = [];

      if (headers) {
        tableRows.push(new TableRow({
          children: headers.map((h) =>
            new TableCell({
              children: [new Paragraph({ text: String(h), bold: true })],
              width: { size: 100 / headers.length, type: WidthType.PERCENTAGE },
            })
          ),
        }));
      }

      for (const row of rows) {
        tableRows.push(new TableRow({
          children: (Array.isArray(row) ? row : [row]).map((cell) =>
            new TableCell({
              children: [new Paragraph({ text: String(cell) })],
            })
          ),
        }));
      }

      children.push(new Table({ rows: tableRows, width: { size: 100, type: WidthType.PERCENTAGE } }));
    }

    // Build document
    const doc = new Document({
      sections: [{
        properties: {},
        headers: content.header ? {
          default: new Header({
            children: [new Paragraph({ text: content.header, alignment: AlignmentType.RIGHT })],
          }),
        } : undefined,
        footers: content.footer ? {
          default: new Footer({
            children: [new Paragraph({
              children: [new TextRun({ text: content.footer, size: 18 }), new TextRun({ children: [PageNumber.CURRENT] })],
              alignment: AlignmentType.CENTER,
            })],
          }),
        } : undefined,
        children,
      }],
    });

    const buffer = await Packer.toBuffer(doc);
    const outPath = pathGuard( req.site, outputPath ) || tempFile( req, 'docx' );
    fs.writeFileSync(outPath, buffer);
    const stats = fs.statSync(outPath);

    res.json({
      success: true,
      output_path: outPath,
      size: stats.size,
      // data_base64 is the plugin contract: output_path points at the
      // WORKER's filesystem and is unusable by the calling site.
      data_base64: buffer.toString('base64'),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});
