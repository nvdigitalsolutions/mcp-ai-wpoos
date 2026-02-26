# Document Generation Tools - Quick Reference

| # | Tool Slug | Tool Name | Type | AI Required | Dependencies | Capability Flags |
|---|-----------|-----------|------|-------------|--------------|------------------|
| 1 | `pro_pdf_document` | Pro PDF | Core Pro | ✅ Yes | Node.js, pdfkit | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 2 | `pro_word_document` | Pro Word | Core Pro | ✅ Yes | Node.js, docx | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 3 | `pro_excel_document` | Pro Excel Document | Core Pro | ✅ Yes | Node.js, exceljs | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 4 | `generate_pdf` | Generate PDF | Simplified | ✅ Yes | Delegates to Pro_PDF | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 5 | `generate_word` | Generate Word | Simplified | ✅ Yes | Delegates to Pro_Word | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 6 | `generate_excel` | Generate Excel | Simplified | ✅ Yes | Delegates to Pro_Excel | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 7 | `extract_pdf_text` | Extract PDF Text | PDF Tool | ❌ No | pdftotext (poppler-utils) | pro, requires-capability, read-only, local-only |
| 8 | `html_to_pdf` | HTML to PDF | PDF Tool | ❌ No | DomPDF OR wkhtmltopdf | pro, requires-capability, write, state-changing, local-only |
| 9 | `merge_pdfs` | Merge PDFs | PDF Tool | ❌ No | pdftk OR TCPDF | pro, requires-capability, write, state-changing, local-only |
| 10 | `add_watermark_to_pdf` | Add Watermark to PDF | PDF Tool | ❌ No | TCPDF | pro, requires-capability, write, state-changing, local-only |
| 11 | `generate_invoice_pdf` | Generate Invoice PDF | PDF Tool | ❌ No | DomPDF | pro, requires-capability, requires-model, consumes-tokens, write, state-changing |
| 12 | `excel_data_import` | Excel Data Import | Excel Tool | ❌ No | PHPSpreadsheet | pro, requires-capability, read-only, local-only |
| 13 | `excel_data_export` | Excel Data Export | Excel Tool | ❌ No | PHPSpreadsheet | pro, requires-capability, write, state-changing, local-only |

## Tool Categories

### 🤖 AI-Powered Tools (6)
Require AI model and consume API tokens:
- Core Pro: `pro_pdf_document`, `pro_word_document`, `pro_excel_document`
- Simplified: `generate_pdf`, `generate_word`, `generate_excel`

### 📄 PDF Manipulation Tools (5)
Work with existing PDFs, no AI required:
- `extract_pdf_text` - Read text from PDFs
- `html_to_pdf` - Convert HTML → PDF
- `merge_pdfs` - Combine PDFs
- `add_watermark_to_pdf` - Brand/secure PDFs
- `generate_invoice_pdf` - Create invoices

### 📊 Excel Data Tools (2)
Import/export Excel data, no AI required:
- `excel_data_import` - Read Excel files
- `excel_data_export` - Write Excel files

## Dependency Installation

### PHP Libraries (via Composer)
```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/addons/pro
composer require dompdf/dompdf
composer require tecnickcom/tcpdf
composer require phpoffice/phpspreadsheet
```

### Command-line Tools (Ubuntu/Debian)
```bash
apt-get install poppler-utils  # For extract_pdf_text
apt-get install wkhtmltopdf    # For html_to_pdf fallback
apt-get install pdftk          # For merge_pdfs fallback
```

### Command-line Tools (macOS)
```bash
brew install poppler     # For extract_pdf_text
brew install wkhtmltopdf # For html_to_pdf fallback
brew install pdftk-java  # For merge_pdfs fallback
```

### Node.js Packages (for AI-powered tools)
```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/addons/pro
npm install
```

## WordPress Capabilities Required

| Capability | Tools Requiring It |
|------------|-------------------|
| `upload_files` | All generation tools (1-11, 13) |
| `read` | extract_pdf_text, excel_data_import |

## Response Format

All tools return consistent structure:
```php
array(
    'attachment_id' => 123,              // WordPress media ID
    'url'           => 'https://...',     // Public URL
    'filename'      => 'document.pdf',    // Filename
    'mime_type'     => 'application/pdf', // MIME type
    'size'          => 12345,             // File size in bytes
    'text'          => 'Success message'  // Human-readable
)
```

## Tool Registration Location

File: `addons/pro/mcp-ai-wpoos-pro.php`  
Function: `wp_mcp_ai_pro_register_tools()`  
Lines: 1083-1105

Enabled when: `wp_mcp_ai_settings['enable_document_generation_toolkit']` is true

## Usage Examples

### Extract Text from PDF
```php
$result = $registry->execute_tool('extract_pdf_text', [
    'attachment_id' => 123,
    'max_pages' => 10
], $context);
```

### Convert HTML to PDF
```php
$result = $registry->execute_tool('html_to_pdf', [
    'html' => '<h1>Report</h1><p>Content here...</p>',
    'title' => 'Monthly Report',
    'page_size' => 'a4'
], $context);
```

### Merge Multiple PDFs
```php
$result = $registry->execute_tool('merge_pdfs', [
    'attachment_ids' => [101, 102, 103],
    'title' => 'Combined Document'
], $context);
```

### Add Watermark
```php
$result = $registry->execute_tool('add_watermark_to_pdf', [
    'attachment_id' => 123,
    'text' => 'CONFIDENTIAL',
    'opacity' => 0.3,
    'position' => 'diagonal'
], $context);
```

### Generate Invoice
```php
$result = $registry->execute_tool('generate_invoice_pdf', [
    'invoice_number' => 'INV-2025-001',
    'items' => [
        ['description' => 'Service', 'quantity' => 1, 'rate' => 1000, 'amount' => 1000]
    ],
    'currency' => 'USD'
], $context);
```

### Export to Excel
```php
$result = $registry->execute_tool('excel_data_export', [
    'data' => [
        ['John', 'Doe', 'john@example.com'],
        ['Jane', 'Smith', 'jane@example.com']
    ],
    'headers' => ['First', 'Last', 'Email'],
    'filename' => 'contacts'
], $context);
```

### Generate PDF with AI
```php
$result = $registry->execute_tool('pro_pdf_document', [
    'operation' => 'generate',
    'description' => 'Create a professional business proposal',
    'title' => 'Business Proposal',
    'model' => 'gpt-4o-mini'
], $context);
```

## Security Considerations

✅ **Implemented:**
- Capability checks before execution
- Input sanitization (sanitize_text_field, absint)
- Output escaping (esc_html, esc_url)
- File type validation (MIME types)
- Path traversal prevention
- Temporary file cleanup
- Rate limiting support

⚠️ **Recommendations:**
- Monitor resource usage for large files
- Implement file size limits
- Add operation timeouts
- Enable audit logging
- Consider background processing for heavy operations

## Troubleshooting

### "pdftotext not found"
Install poppler-utils: `apt-get install poppler-utils`

### "DomPDF class not found"
Install via Composer: `composer require dompdf/dompdf`

### "Node.js not installed"
For AI-powered tools only. Install Node.js 14+ and run `npm install`

### "Permission denied"
Check WordPress upload directory permissions: `wp-content/uploads/`

### Tools not appearing
1. Verify Pro addon activated
2. Enable toolkit in Settings → NV oOS → Tools & Features
3. Check `enable_document_generation_toolkit` setting

## Performance Notes

**Resource-Intensive Operations:**
- PDF text extraction (memory scales with PDF size)
- PDF merging (slow with many/large files)
- Excel import/export (memory-intensive for large sheets)
- Watermarking (requires page-by-page processing)

**Optimization Tips:**
- Use max_pages/max_rows limits
- Implement chunking for bulk operations
- Consider background processing
- Add progress indicators
- Cache results when appropriate

## Related Documentation

- **Full Review:** `docs/DOCUMENT_GENERATION_TOOLS_REVIEW.md`
- **Toolkit README:** `addons/pro/includes/tools/document-generation/README.md`
- **Tool Reference:** `docs/tool-reference.md`
- **Deployment Guide:** `docs/deployment-troubleshooting.md`

---

**Last Updated:** 2026-02-13  
**Total Tools:** 13  
**Status:** All tools verified and registered ✅
