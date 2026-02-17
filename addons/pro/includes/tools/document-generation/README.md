# Document Generation Toolkit

AI-powered PDF, Word, and Excel document generation for WordPress.

## Overview

The Document Generation Toolkit provides 13 professional document generation and manipulation tools:

### AI-Powered Document Generation (3)
- **pro_pdf_document** - Advanced AI-powered PDF generation with templates and formatting
- **pro_word_document** - Advanced AI-powered Word document generation
- **pro_excel_document** - Advanced AI-powered Excel spreadsheet generation

### Simplified Document Generation (3)
- **generate_pdf** - Quick PDF generation (simpler interface)
- **generate_word** - Quick Word document generation
- **generate_excel** - Quick Excel spreadsheet generation

### PDF Manipulation Tools (5)
- **extract_pdf_text** - Extract text content from PDF files
- **html_to_pdf** - Convert HTML content to PDF documents
- **merge_pdfs** - Combine multiple PDF files into one
- **add_watermark_to_pdf** - Add text or image watermarks to PDFs
- **generate_invoice_pdf** - Generate professional invoice PDFs

### Excel Data Tools (2)
- **excel_data_import** - Import data from Excel spreadsheets
- **excel_data_export** - Export data to Excel spreadsheets

## Requirements

### System Requirements
- WordPress 6.0+
- PHP 7.4+
- **Node.js 14+ installed on server**
- Pro addon installed and activated

### NPM Packages
The toolkit requires three NPM packages to be installed on the server:

```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/addons/pro
npm install
```

This installs:
- `pdfkit@0.17.2` - PDF generation
- `docx@9.5.1` - Word document generation
- `exceljs@4.4.0` - Excel spreadsheet generation

### AI Provider
Requires AI provider API credentials:
- OpenAI API key, OR
- Google Gemini API key, OR
- Ollama running locally

## Installation

### Combined/Cloned Repository
If you cloned the full repository:

1. Install NPM packages in pro addon:
   ```bash
   cd wp-content/plugins/mcp-ai-wpoos/addons/pro
   npm install
   ```

2. Enable the toolkit in WordPress admin:
   - Navigate to **Settings → NV oOS → Tools & Features**
   - Check "Enable Document Generation Toolkit"
   - Save settings

### Separate Pro Addon
If you installed base + pro as separate plugins:

1. Ensure both plugins are installed and activated
2. Install NPM packages in pro addon directory:
   ```bash
   cd wp-content/plugins/mcp-ai-wpoos-pro
   npm install
   ```

3. Enable the toolkit in WordPress admin (same as above)

## Tools

### AI-Powered Document Generation

These tools use AI to generate professional documents from natural language descriptions.

#### pro_pdf_document - PDF Document Generation

Generate professional PDF documents from AI-generated content.

**Operations:**
- `generate` - Create PDF from natural language description
- `structure` - Create multi-section document with headings
- `format` - Apply custom formatting to content

**Parameters:**
- `operation` (required) - Operation to perform
- `description` - Natural language description of document
- `title` - Document title
- `author` - Document author
- `page_size` - A4, Letter, Legal, A3, A5 (default: A4)
- `orientation` - portrait, landscape (default: portrait)
- `model` - AI model to use
- `upload` - Upload to media library (default: true)

**Example:**
```php
$result = $registry->execute_tool('pro_pdf', [
    'operation' => 'generate',
    'description' => 'Create a project proposal for a mobile app',
    'title' => 'Mobile App Proposal',
    'page_size' => 'Letter',
    'model' => 'gpt-4o-mini'
], $context);
```

#### pro_word_document - Word Document Generation

Generate Word documents with rich formatting and templates.

**Operations:**
- `generate` - Create document from description
- `structure` - Create multi-section document
- `format` - Apply rich text formatting
- `template` - Use predefined template

**Templates:**
- `business_letter`
- `report`
- `resume`
- `memo`
- `proposal`

**Parameters:**
- `operation` (required)
- `description` - Document description
- `title` - Document title
- `author` - Document author
- `template` - Template name (for template operation)
- `orientation` - portrait, landscape
- `model` - AI model
- `upload` - Upload to media library (default: true)

**Example:**
```php
$result = $registry->execute_tool('pro_word', [
    'operation' => 'template',
    'template' => 'business_letter',
    'description' => 'Write a letter requesting meeting about Q1 results',
    'title' => 'Q1 Results Meeting Request'
], $context);
```

### pro_excel_document - Excel Spreadsheet Generation

Generate Excel spreadsheets with data tables and formulas.

**Operations:**
- `generate` - Create spreadsheet from description
- `table` - Create data table with headers
- `multi_sheet` - Create workbook with multiple sheets
- `chart` - Add charts (not yet implemented)

**Parameters:**
- `operation` (required)
- `description` - Spreadsheet description
- `title` - Spreadsheet title
- `author` - Document author
- `data` - Array of data rows
- `headers` - Column headers
- `formulas` - Array of formulas to add
- `model` - AI model
- `upload` - Upload to media library (default: true)

**Example:**
```php
$result = $registry->execute_tool('pro_excel_document', [
    'operation' => 'table',
    'description' => 'Create a sales report for Q4 2025',
    'title' => 'Q4 2025 Sales Report',
    'headers' => ['Month', 'Revenue', 'Expenses', 'Profit'],
    'data' => [
        ['October', 50000, 30000, 20000],
        ['November', 55000, 32000, 23000],
        ['December', 60000, 35000, 25000]
    ],
    'formulas' => [
        ['cell' => 'D2', 'formula' => '=B2-C2'],
        ['cell' => 'D3', 'formula' => '=B3-C3'],
        ['cell' => 'D4', 'formula' => '=B4-C4']
    ]
], $context);
```

### Simplified Document Generation

These tools provide simpler interfaces for quick document generation by delegating to the advanced Pro tools.

#### generate_pdf - Quick PDF Generation

Generate PDF documents with a simplified parameter set.

**Parameters:**
- `content` (required) - Content to include in the PDF
- `title` - Document title

**Example:**
```php
$result = $registry->execute_tool('generate_pdf', [
    'content' => 'This is a simple PDF document.',
    'title' => 'My Document'
], $context);
```

#### generate_word - Quick Word Generation

Generate Word documents with basic formatting.

**Parameters:**
- `content` (required) - Content to include
- `title` - Document title

#### generate_excel - Quick Excel Generation

Generate Excel spreadsheets from data.

**Parameters:**
- `data` (required) - Data to include
- `title` - Spreadsheet title

### PDF Manipulation Tools

These tools work with existing PDF files without requiring AI.

#### extract_pdf_text - PDF Text Extraction

Extract text content from PDF documents for processing or analysis.

**Requirements:** `pdftotext` command-line tool (poppler-utils package)

**Parameters:**
- `attachment_id` - WordPress attachment ID of PDF file
- `url` - URL of PDF file (alternative to attachment_id)
- `max_pages` - Maximum number of pages to extract

**Example:**
```php
$result = $registry->execute_tool('extract_pdf_text', [
    'attachment_id' => 123,
    'max_pages' => 10
], $context);
```

#### html_to_pdf - HTML to PDF Conversion

Convert HTML content into PDF documents with CSS styling support.

**Requirements:** DomPDF (Composer) OR wkhtmltopdf command-line tool

**Parameters:**
- `html` (required) - HTML content to convert
- `title` - PDF document title
- `filename` - Output filename (without extension)
- `page_size` - a4, letter, legal (default: a4)
- `orientation` - portrait, landscape (default: portrait)

**Example:**
```php
$result = $registry->execute_tool('html_to_pdf', [
    'html' => '<h1>Hello World</h1><p>This is a test.</p>',
    'title' => 'Test Document',
    'page_size' => 'letter'
], $context);
```

#### merge_pdfs - PDF Merging

Combine multiple PDF files into a single document.

**Requirements:** pdftk command-line tool OR TCPDF library

**Parameters:**
- `attachment_ids` (required) - Array of WordPress attachment IDs
- `title` - Title for merged document
- `filename` - Output filename (without extension)

**Example:**
```php
$result = $registry->execute_tool('merge_pdfs', [
    'attachment_ids' => [123, 124, 125],
    'title' => 'Combined Report'
], $context);
```

#### add_watermark_to_pdf - PDF Watermarking

Add text watermarks to PDF documents for branding or security.

**Requirements:** TCPDF library

**Parameters:**
- `attachment_id` (required) - WordPress attachment ID of PDF
- `text` (required) - Watermark text
- `opacity` - Watermark opacity (0.0 to 1.0, default: 0.3)
- `position` - center, diagonal, top, bottom (default: diagonal)

**Example:**
```php
$result = $registry->execute_tool('add_watermark_to_pdf', [
    'attachment_id' => 123,
    'text' => 'CONFIDENTIAL',
    'opacity' => 0.5,
    'position' => 'diagonal'
], $context);
```

#### generate_invoice_pdf - Invoice Generation

Generate professional invoice PDFs with itemized billing and calculations.

**Requirements:** DomPDF library

**Parameters:**
- `invoice_number` (required) - Invoice number or ID
- `items` (required) - Array of invoice items
- `date` - Invoice date (YYYY-MM-DD)
- `due_date` - Payment due date
- `bill_to` - Billing recipient information
- `subtotal` - Subtotal amount
- `tax_rate` - Tax rate percentage
- `total` - Total amount
- `currency` - Currency code (default: USD)

**Example:**
```php
$result = $registry->execute_tool('generate_invoice_pdf', [
    'invoice_number' => 'INV-2025-001',
    'items' => [
        ['description' => 'Web Design', 'quantity' => 1, 'rate' => 5000, 'amount' => 5000],
        ['description' => 'Development', 'quantity' => 40, 'rate' => 150, 'amount' => 6000]
    ],
    'date' => '2025-01-15',
    'due_date' => '2025-02-15',
    'currency' => 'USD'
], $context);
```

### Excel Data Tools

Tools for importing and exporting Excel data without AI.

#### excel_data_import - Excel Import

Import data from Excel spreadsheets for processing or database import.

**Requirements:** PHPSpreadsheet library

**Parameters:**
- `attachment_id` (required) - WordPress attachment ID of Excel file
- `sheet_index` - Sheet index to import (0-based, default: 0)
- `has_headers` - Whether first row contains headers (default: true)
- `max_rows` - Maximum number of rows to import

**Example:**
```php
$result = $registry->execute_tool('excel_data_import', [
    'attachment_id' => 123,
    'sheet_index' => 0,
    'has_headers' => true,
    'max_rows' => 1000
], $context);
```

#### excel_data_export - Excel Export

Export data arrays to Excel spreadsheets for reporting and data sharing.

**Requirements:** PHPSpreadsheet library

**Parameters:**
- `data` (required) - Array of data rows
- `headers` - Column headers array
- `title` - Spreadsheet title
- `filename` - Output filename (without extension)

**Example:**
```php
$result = $registry->execute_tool('excel_data_export', [
    'data' => [
        ['John', 'Doe', 'john@example.com'],
        ['Jane', 'Smith', 'jane@example.com']
    ],
    'headers' => ['First Name', 'Last Name', 'Email'],
    'title' => 'Contact List',
    'filename' => 'contacts-export'
], $context);
```

## Response Format

All tools return consistent response structure:

```php
[
    'operation' => 'generate',          // Operation performed
    'title' => 'Document Title',         // Document title
    'file_url' => 'https://...',         // WordPress media URL
    'file_path' => '/path/to/file',      // Server file path
    'attachment_id' => 123,              // WordPress attachment ID
    'text' => 'Generated PDF document: Document Title'  // Human-readable message
]
```

## Permissions

- Requires `upload_files` capability
- Users must be logged in
- Respects WordPress multisite permissions

## AI Providers

The toolkit works with all supported AI providers:

**OpenAI:**
- Models: gpt-4, gpt-4o, gpt-4o-mini, gpt-3.5-turbo
- Requires API key in Settings → API Configuration

**Google Gemini:**
- Models: gemini-pro, gemini-1.5-pro, gemini-1.5-flash
- Requires API key in Settings → API Configuration

**Ollama (Local):**
- Models: llama3, mistral, qwen2.5, codellama
- Requires Ollama running on localhost:11434

## Troubleshooting

### "Node.js is not installed or not found in PATH"
- Install Node.js 14+ on your server
- Ensure `node` is in system PATH
- Test: `which node` should return path to node binary

### "Excel document generation failed"
- Verify NPM packages installed: `cd addons/pro && npm list`
- Check Node.js can access packages: `node -e "require('exceljs')"`
- Ensure server has write permissions to WordPress uploads directory

### "AI provider error"
- Verify API keys configured in Settings → API Configuration
- Check AI provider status (OpenAI/Gemini APIs operational)
- For Ollama, verify service running: `curl http://localhost:11434`

### Tools not appearing in assistant
- Verify Pro addon installed and activated
- Enable toolkit in Settings → Tools & Features
- Check setting key: `enable_document_generation_toolkit`
- Refresh assistant edit page

## Development

### File Locations

**Tools:**
```
addons/pro/includes/tools/document-generation/
├── class-wp-mcp-ai-tool-pro-pdf.php
├── class-wp-mcp-ai-tool-pro-word.php
└── class-wp-mcp-ai-tool-pro-excel-document.php
```

**Dependencies:**
```
addons/pro/package.json
addons/pro/node_modules/
```

**Settings:**
```
includes/admin/sections/class-wp-mcp-ai-section-tools.php
```

### Adding New Document Types

To add a new document generator:

1. Create tool class in `addons/pro/includes/tools/document-generation/`
2. Implement `WP_MCP_AI_Tool_Interface`
3. Add to `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`
4. Add tool group mapping in `wp_mcp_ai_pro_tool_group_map()`
5. Add NPM dependencies to `addons/pro/package.json`

## Support

- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Support: https://nvdigitalsolutions.com/support

## License

This feature is part of the Pro addon. See main plugin LICENSE file.
