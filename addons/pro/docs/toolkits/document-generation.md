# Document Generation Toolkit

> PDF, Word, and Excel generation directly from WordPress, plus PDF text extraction, OCR,
> watermarking, and Docs Hub Markdown content management.

| | |
|---|---|
| **Activation setting** | `enable_document_generation_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Document Generation |
| **Tools** | 24 |
| **NPM** | `pdfkit`, `docx`, `exceljs`, plus Tesseract.js / canvas for OCR |
| **Available since** | Pro v1.1.0 |

---

## What it provides

Generate and process documents from AI workflows:

- **PDF** — `generate_pdf`, `generate_invoice_pdf`, `html_to_pdf`, `merge_pdfs`,
  `add_watermark_to_pdf`, plus the higher-level `pro_pdf` tool.
- **Word** — `generate_word`, `pro_word`.
- **Excel** — `generate_excel`, `pro_excel_document`, `excel_data_export`,
  `excel_data_import`.
- **Text extraction & OCR** — `extract_pdf_text`, `ocr_pdf_text`, `pro_document_ocr`
  (image OCR works out of the box; PDF OCR requires `canvas` native binaries — see
  [`README-PRO-DOCUMENT-OCR.md`](../../includes/tools/document-generation/README-PRO-DOCUMENT-OCR.md)).
- **Docs Hub content management** — `docs_hub_write_doc`, `docs_hub_read_doc`,
  `docs_hub_list_docs`, `docs_hub_delete_doc`, `docs_hub_rebuild`. These manage the
  Markdown/text files the standalone [NV oOS Docs Hub](https://wordpress.org/plugins/nvoos-docs-hub/)
  plugin publishes from `wp-content/uploads/nvoos-docs-hub/content/`. File writes
  require `manage_options`, trigger a Docs Hub index rebuild by default, and the
  `docs_hub_rebuild` tool additionally requires the Docs Hub plugin to be active.

Tool source: `addons/pro/includes/tools/document-generation/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Document Generation** under **NV oOS → Settings → Pro Features**.
3. (Optional) Run `npm install canvas@2` to enable PDF OCR.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/document-generation/README.md`](../../includes/tools/document-generation/README.md) — full tool reference
- [`addons/pro/includes/tools/document-generation/README-PRO-DOCUMENT-OCR.md`](../../includes/tools/document-generation/README-PRO-DOCUMENT-OCR.md) — OCR setup
