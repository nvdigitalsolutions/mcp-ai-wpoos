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
import { siteBaseDir, pathGuard } from '../utils/site-paths.js';

export const documentRouter = Router();

function tempFile( req, ext ) {
	return path.join( siteBaseDir( req.site ), `doc-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.${ ext }` );
}

// ── POST /excel — generate Excel spreadsheet ───────────────
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

    res.json({ success: true, output_path: outPath, size: stats.size });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});
