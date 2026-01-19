# Document Generation Toolkit

AI-powered PDF, Word, and Excel document generation for WordPress.

## Overview

The Document Generation Toolkit provides three pro-tier tools that leverage AI to create professional documents from natural language descriptions:

- **pro_pdf** - Generate PDF documents
- **pro_word** - Generate Word (.docx) documents  
- **pro_excel_document** - Generate Excel (.xlsx) spreadsheets

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

### pro_pdf - PDF Document Generation

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

### pro_word - Word Document Generation

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
