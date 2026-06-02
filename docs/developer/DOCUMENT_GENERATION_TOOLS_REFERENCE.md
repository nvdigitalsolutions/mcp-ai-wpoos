# Document Generation Tools - Complete Reference

## Overview

The Document Generation toolkit now displays a comprehensive list of both actual tool classes and their available operations/capabilities in the settings page.

## Tool Structure

### Base Plugin Tool (NOT in Document Generation)

**pro_excel** - Excel Formula Generation
- **Location**: `includes/tools/class-wp-mcp-ai-tool-pro-excel.php`
- **Slug**: `pro_excel`
- **Purpose**: AI-powered Excel formula generation and manipulation
- **Capabilities**:
  - Generate Excel formulas from natural language
  - Create custom LAMBDA functions
  - Explain complex formulas
  - Debug problematic formulas
  - Document formula logic
  - Convert multi-step calculations into LAMBDA functions
- **Note**: This is a DIFFERENT tool from `pro_excel_document`. This one generates formulas, not files.

### Pro Addon Tools (Document Generation Toolkit)

#### 1. pro_pdf - PDF Document Generation
- **Location**: `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php`
- **Slug**: `pro_pdf`
- **NPM Dependency**: pdfkit (500K downloads/week)
- **Operations**:
  - generate (create PDF from description)
  - structure (create structured document with sections)
  - format (apply formatting to content)
- **Capabilities Listed**:
  - `generate_pdf` → Generate PDF Document
  - `html_to_pdf` → Convert HTML to PDF
  - `merge_pdfs` → Merge Multiple PDFs
  - `add_watermark_to_pdf` → Add Watermark to PDF
  - `extract_pdf_text` → Extract Text from PDF

#### 2. pro_word - Word Document Generation
- **Location**: `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php`
- **Slug**: `pro_word`
- **NPM Dependency**: docx (2M downloads/week)
- **Operations**:
  - generate (create Word document from description)
  - template (apply document templates)
  - format (apply styling and formatting)
- **Capabilities Listed**:
  - `generate_word` → Generate Word Document

#### 3. pro_excel_document - Excel File Generation
- **Location**: `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php`
- **Slug**: `pro_excel_document`
- **NPM Dependency**: exceljs (2M downloads/week)
- **Operations**:
  - generate (create spreadsheet from description)
  - table (create data table)
  - multi_sheet (multiple worksheets)
  - chart (add charts and visualizations)
- **Capabilities Listed**:
  - `generate_excel` → Generate Excel Spreadsheet
  - `excel_data_import` → Import Data from Excel
  - `excel_data_export` → Export Data to Excel

## Settings Page Tools List

The Document Generation settings page (Available Tools tab) now shows **13 items**:

### Primary Tools (3)
1. `pro_pdf` → Pro PDF Document
2. `pro_word` → Pro Word Document
3. `pro_excel_document` → Pro Excel Document

### Document Operations & Capabilities (10)
4. `generate_pdf` → Generate PDF Document
5. `generate_word` → Generate Word Document
6. `generate_excel` → Generate Excel Spreadsheet
7. `html_to_pdf` → Convert HTML to PDF
8. `merge_pdfs` → Merge Multiple PDFs
9. `add_watermark_to_pdf` → Add Watermark to PDF
10. `extract_pdf_text` → Extract Text from PDF
11. `excel_data_import` → Import Data from Excel
12. `excel_data_export` → Export Data to Excel
13. `generate_invoice_pdf` → Generate Invoice PDF

## Important Distinctions

### pro_excel vs pro_excel_document

| Feature | pro_excel | pro_excel_document |
|---------|-----------|-------------------|
| **Location** | Base plugin | Pro addon |
| **Purpose** | Formula generation | File generation |
| **Output** | Excel formulas (text) | Excel files (.xlsx) |
| **NPM Dependency** | None | exceljs |
| **Use Case** | Create/debug formulas | Create spreadsheets |
| **Toolkit** | N/A (base tool) | Document Generation |

### Actual Tools vs Operations

**Actual Tools** are PHP classes with `get_slug()` methods:
- `pro_pdf`
- `pro_word`
- `pro_excel_document`

**Operations** are capabilities within those tools, not separate classes:
- `generate_pdf` - operation within `pro_pdf`
- `merge_pdfs` - operation within `pro_pdf`
- `html_to_pdf` - operation within `pro_pdf`
- etc.

The operations are listed in the settings page for **user reference and documentation**, showing what capabilities are available.

## Why List Both?

1. **Completeness**: Shows users all available functionality
2. **Backward Compatibility**: Matches old toolkit settings page expectations
3. **Documentation**: Clearly describes what operations users can perform
4. **Discoverability**: Helps users find the right tool/operation for their needs

## User Workflow

### To Generate a PDF:
1. Use assistant with Document Generation toolkit enabled
2. Call the `pro_pdf` tool
3. Specify operation: "generate" for new PDF, "structure" for sections, etc.

### To Work with Excel Formulas:
1. Use the `pro_excel` tool (base plugin)
2. Generate formulas from natural language descriptions
3. Get formula text to use in spreadsheets

### To Create Excel Files:
1. Use the `pro_excel_document` tool (pro addon)
2. Specify operation: "generate" for new file, "table" for data tables, etc.
3. Get actual .xlsx file as output

## NPM Requirements

All three document generation tools require Node.js and NPM packages:

```bash
cd addons/pro/
npm install
```

This installs:
- **pdfkit**: PDF generation
- **docx**: Word document generation
- **exceljs**: Excel spreadsheet generation

The settings page shows installation status and provides instructions if packages are missing.

## Conclusion

The Document Generation toolkit now has complete documentation of:
- ✅ 3 primary tool classes
- ✅ 10 specific operations/capabilities
- ✅ Clear separation from base plugin tools
- ✅ Comprehensive user reference

This provides users with full transparency about what's possible with the toolkit while maintaining accurate technical implementation details.
