# Pro Plugin: Enhanced Vector Storage Tools

## Overview

The Pro plugin provides advanced file preprocessing and conversion tools that leverage professional-grade document processing libraries to optimize files for vector store ingestion.

## Why Pro-Exclusive Tools?

### WordPress.org Compliance
- **Base Plugin**: Complies with WordPress.org guidelines (no external dependencies, no composer/npm packages)
- **Pro Plugin**: Can use advanced libraries (PHPOffice, PDF parsers, OCR, etc.)

### Separation of Concerns
- **Base Plugin**: Validation, guidance, and basic recommendations
- **Pro Plugin**: Automatic conversion, OCR, advanced preprocessing

## Available Pro Tools

### 1. Prepare File for Vector Store (`prepare_file_for_vector_store`)

**Purpose**: Automatically converts and optimizes files for vector store upload.

**Capabilities**:
- ✅ **CSV/XLSX → Structured Text**: Converts spreadsheets to well-formatted markdown
- ✅ **Scanned PDF → Text**: OCR extraction with multiple providers (OpenAI, Gemini, Ollama, Tesseract)
- ✅ **Encoding Fixes**: Automatic UTF-8 conversion
- ✅ **Format Optimization**: Cleans headers, footers, excessive whitespace
- ✅ **Preview Generation**: Shows first chunk for validation

**Parameters**:
```json
{
  "attachment_id": 123,
  "output_format": "auto",  // or "pdf", "txt", "md"
  "enable_ocr": true,
  "preserve_structure": true,
  "clean_formatting": true,
  "generate_preview": true
}
```

**Example Response**:
```json
{
  "success": true,
  "message": "Successfully converted XLSX to structured TXT format optimized for vector store",
  "processed_file_id": 124,
  "original_file_id": 123,
  "conversion_applied": true,
  "output_format": "txt",
  "rows_extracted": 150,
  "preview": {
    "preview_text": "# Data Structure\n\nColumns: Name, Email, ...",
    "estimated_tokens": 450,
    "recommended_chunk_size": "256-512 tokens",
    "file_size": 15234
  }
}
```

### Conversion Capabilities

#### Spreadsheets (CSV, XLSX, XLS)

**Input**: Spreadsheet with data
```
Name        | Email              | Status
------------|--------------------|---------
John Doe    | john@example.com   | Active
Jane Smith  | jane@example.com   | Pending
```

**Output**: Structured Markdown
```markdown
# Data Structure

Columns: Name, Email, Status

---

## Record 1

**Name**: John Doe
**Email**: john@example.com
**Status**: Active

---

## Record 2

**Name**: Jane Smith
**Email**: jane@example.com
**Status**: Pending

---
```

**Why This Works Better**:
- Clear structure for RAG retrieval
- Each record is a semantic unit
- Headers provide context
- Markdown format is vector-store friendly

#### PDFs with OCR

**Scanned PDF** → **Text with OCR**

Automatically detects if PDF needs OCR and applies it:
1. Tries text extraction first (fast)
2. If no text found, applies OCR (OpenAI Vision, Gemini, Ollama, or Tesseract)
3. Returns extracted text

**Multi-Provider OCR**:
- **OpenAI Vision**: High accuracy, costs API credits
- **Google Gemini**: Good accuracy, costs API credits
- **Ollama**: Free, runs locally, needs local setup
- **Tesseract**: Free, open-source, good for English

#### Text Files

**Encoding Fixes**:
- Detects non-UTF-8 encoding
- Automatically converts to UTF-8
- Cleans formatting (excessive whitespace, line endings)

**Before**:
```
Some text with ��� weird encoding ���
Multiple     spaces    and


too many newlines
```

**After**:
```
Some text with correct encoding

Multiple spaces and

too many newlines
```

## Integration with Base Tools

### Workflow: Base + Pro

1. **User uploads file** (any format)
2. **Base Plugin** (`analyze_file_suitability`):
   - Checks format
   - If CSV/XLSX/PPTX → warns user
   - Recommends conversion

3. **Pro Plugin** (`prepare_file_for_vector_store`):
   - Automatically converts unreliable formats
   - Applies OCR if needed
   - Fixes encoding issues
   - Returns optimized file

4. **User uploads to vector store**:
   - Uses optimized file from step 3
   - Better extraction, better RAG results

### Example Usage

```php
// Step 1: Analyze file (base plugin)
$analysis = $tool_registry->execute_tool(
    'analyze_file_suitability',
    [
        'file_id' => 123,
        'purpose' => 'assistants'
    ]
);

// If file needs conversion, use pro tool
if (!$analysis['suitable']) {
    // Step 2: Prepare file (pro plugin)
    $prepared = $tool_registry->execute_tool(
        'prepare_file_for_vector_store',
        [
            'attachment_id' => 123,
            'output_format' => 'auto',
            'enable_ocr' => true
        ]
    );
    
    // Step 3: Upload prepared file to OpenAI
    $file_id = openai_upload_file($prepared['processed_file_id']);
    
    // Step 4: Add to vector store
    $result = openai_add_to_vector_store($vector_store_id, [$file_id]);
}
```

## Libraries Used

### PHP (Composer)
- **phpoffice/phpspreadsheet**: Excel file reading/writing
- **phpoffice/phpword**: Word document processing
- **smalot/pdfparser**: PDF text extraction
- **thiagoalessio/tesseract_ocr**: OCR integration
- **dompdf/dompdf** & **tecnickcom/tcpdf**: PDF generation

### JavaScript (NPM)
- **pdf-parse**: PDF text extraction
- **exceljs**: Excel file processing
- **docx**: Word document processing
- **tesseract.js**: Browser-based OCR
- **pdf-lib**: PDF manipulation
- **turndown**: HTML to Markdown

## Best Practices

### When to Use Base vs Pro

**Use Base Tools When**:
- Validating file formats
- Getting preprocessing recommendations
- Learning best practices
- Files already in optimal format (PDF, TXT, DOCX, MD, JSON, HTML)

**Use Pro Tools When**:
- Need to convert CSV/XLSX/PPTX
- Need OCR for scanned documents
- Need automatic encoding fixes
- Want one-click optimization
- Processing multiple files in batch

### Performance Considerations

**Spreadsheet Conversion**:
- Fast for < 1000 rows
- For larger files, consider chunking

**OCR Processing**:
- Resource-intensive
- Limit to 10 pages by default
- Use `max_pages` parameter for large documents

**Text Cleaning**:
- Very fast, minimal overhead
- Safe for all file sizes

## Future Enhancements

### Planned Pro Tools

1. **Batch File Preparation** (`batch_prepare_files_for_vector_store`)
   - Process multiple files at once
   - Progress tracking
   - Parallel processing

2. **Advanced Validation** (`validate_vector_store_file_pro`)
   - Content quality scoring
   - Structure analysis
   - Chunk visualization

3. **Smart Chunking** (`chunk_file_for_vector_store`)
   - Semantic chunking
   - Optimal size detection
   - Preview all chunks

4. **Format-Specific Converters**
   - PPTX extraction (full support)
   - HTML to clean Markdown
   - Code file optimization

## Comparison: Base vs Pro

| Feature | Base Plugin | Pro Plugin |
|---------|-------------|------------|
| **File Validation** | ✅ Yes | ✅ Yes (enhanced) |
| **Format Recommendations** | ✅ Yes | ✅ Yes |
| **Best Practices Docs** | ✅ Yes | ✅ Yes |
| **CSV/XLSX Conversion** | ❌ No | ✅ Yes (auto) |
| **OCR for Scanned PDFs** | ❌ No | ✅ Yes (multi-provider) |
| **Encoding Auto-fix** | ❌ No | ✅ Yes |
| **Structure Optimization** | ❌ No | ✅ Yes |
| **Chunk Preview** | ❌ No | ✅ Yes |
| **Batch Processing** | ❌ No | 🔄 Coming soon |
| **WordPress.org Compliant** | ✅ Yes | N/A (Pro only) |

## Installation & Setup

### Requirements
- Pro plugin activated
- Composer dependencies installed: `composer install` in `addons/pro/`
- PHP 8.1+ (for PHPOffice libraries)

### Activation
Pro tools are automatically registered when pro plugin is active. No additional configuration needed.

### Verification
```php
// Check if pro tool is available
$available = $tool_registry->tool_exists('prepare_file_for_vector_store');

if ($available) {
    echo "Pro vector storage tools are active!";
}
```

## Support & Documentation

- **Base Plugin Docs**: `docs/tools/VECTOR_STORAGE_BEST_PRACTICES.md`
- **Pro Plugin Docs**: This file
- **Quick Reference**: `docs/tools/VECTOR_STORAGE_QUICK_REFERENCE.md`
- **Tool Reference**: See individual tool documentation

## Summary

Pro plugin enhances vector storage workflows by:
- ✅ Automating format conversion (CSV/XLSX → TXT/PDF)
- ✅ Providing OCR for scanned documents
- ✅ Fixing encoding issues automatically
- ✅ Optimizing document structure
- ✅ Generating previews for validation

All while keeping the base plugin WordPress.org compliant and the pro features as value-added enhancements.
