# Pro Document OCR Tool

## Overview

The **Pro Document OCR** tool is an advanced AI-powered OCR (Optical Character Recognition) system for extracting text from PDFs and images. It's optimized for document creation workflows and built on industry standards (ISO/IEC 42001:2023, NIST AI RMF).

## Key Features

### Enhanced Capabilities
- **Multi-page PDF Processing**: Extract text from PDFs with up to 50 pages
- **Batch Image Processing**: Process up to 20 images in a single call
- **Layout Preservation**: Maintain document structure and formatting
- **Multiple Output Formats**: Plain text, JSON, Markdown, or HTML
- **Structured Metadata**: Confidence scores, word counts, processing times
- **Quality Metrics**: Character/word counts, duration statistics
- **Save as Attachment**: Export extracted text as WordPress attachments

### AI Provider Support
- OpenAI GPT-4o Vision (recommended for highest accuracy)
- Google Gemini Vision  
- Anthropic Claude 3.5 Sonnet (recommended for complex layouts)
- Ollama (local AI models)
- Tesseract OCR (fallback)

### No Additional Dependencies
- Uses existing composer/npm packages
- Leverages built-in OCR service infrastructure
- No plugin size increase

## Usage

### Basic Text Extraction

```json
{
  "tool": "pro_document_ocr",
  "arguments": {
    "source": {
      "attachment_id": 123
    }
  }
}
```

### Multi-page PDF with Layout Preservation

```json
{
  "tool": "pro_document_ocr",
  "arguments": {
    "source": {
      "attachment_id": 456
    },
    "options": {
      "preserve_layout": true,
      "output_format": "markdown",
      "max_pages_per_pdf": 20,
      "provider": "anthropic"
    }
  }
}
```

### Batch Image Processing

```json
{
  "tool": "pro_document_ocr",
  "arguments": {
    "source": {
      "attachment_ids": [101, 102, 103, 104]
    },
    "options": {
      "output_format": "json",
      "include_metadata": true
    }
  }
}
```

### Multiple URL Processing

```json
{
  "tool": "pro_document_ocr",
  "arguments": {
    "source": {
      "urls": [
        "https://example.com/document1.pdf",
        "https://example.com/document2.pdf",
        "https://example.com/scan1.jpg",
        "https://example.com/scan2.png"
      ]
    },
    "options": {
      "output_format": "text",
      "include_metadata": true
    }
  }
}
```

### Structured Output with Export

```json
{
  "tool": "pro_document_ocr",
  "arguments": {
    "source": {
      "url": "https://example.com/document.pdf"
    },
    "options": {
      "output_format": "html",
      "preserve_layout": true
    },
    "export_options": {
      "save_as_attachment": true,
      "attachment_title": "Extracted Document Text"
    }
  }
}
```

## Parameters

### Source (required)

Specify the document source using ONE of:

- `attachment_id` (integer): Single WordPress attachment ID
- `attachment_ids` (array): Multiple attachment IDs for batch processing (up to 20)
- `url` (string): Single image/PDF URL
- `urls` (array): Multiple URLs for batch processing (up to 20)
- `file_ids` (array): OpenAI file IDs for batch processing

### Options

- **provider** (string, default: "auto"): AI provider to use
  - Options: `auto`, `openai`, `gemini`, `anthropic`, `ollama`, `tesseract`
  - Recommendation: `anthropic` for best OCR accuracy, `openai` for speed

- **preserve_layout** (boolean, default: false): Preserve document layout and formatting

- **output_format** (string, default: "text"): Output format
  - `text`: Plain text
  - `json`: Structured JSON with metadata
  - `markdown`: Markdown with formatting
  - `html`: HTML with semantic tags

- **max_pages_per_pdf** (integer, default: 10, max: 50): Pages to process per PDF

- **include_metadata** (boolean, default: true): Include extraction metadata

- **language** (string, default: "auto"): Document language code
  - Examples: `en`, `es`, `fr`, `de`, `auto`

- **preprocess** (boolean, default: true): Apply image preprocessing for better accuracy

### Export Options

- **save_as_attachment** (boolean, default: false): Save extracted text as attachment
- **attachment_title** (string): Title for saved attachment

## Response Format

### Text Output

```json
{
  "success": true,
  "text": "Extracted text content...",
  "documents_count": 1,
  "successful": 1,
  "failed": 0,
  "total_duration": 12.5,
  "metadata": {
    "total_words": 1234,
    "total_chars": 7890,
    "providers_used": ["openai"],
    "extraction_date": "2026-02-14 12:00:00",
    "quality_standard": "ISO/IEC 42001:2023"
  }
}
```

### JSON Output

```json
{
  "success": true,
  "documents": [
    {
      "source": 123,
      "type": "pdf",
      "text": "Extracted text...",
      "duration": 8.3,
      "metadata": {
        "word_count": 456,
        "char_count": 2890,
        "provider": "anthropic",
        "language": "en",
        "processed_at": "2026-02-14 12:00:00",
        "processing_sec": 8.3,
        "max_pages": 10
      }
    }
  ],
  "documents_count": 1,
  "successful": 1,
  "failed": 0,
  "total_duration": 8.5,
  "metadata": {
    "total_words": 456,
    "total_chars": 2890,
    "providers_used": ["anthropic"],
    "extraction_date": "2026-02-14 12:00:00",
    "quality_standard": "ISO/IEC 42001:2023"
  }
}
```

## Best Practices

### Provider Selection

1. **Anthropic Claude**: Best for complex layouts, handwriting, tables
2. **OpenAI GPT-4o**: Best for speed and mixed-content documents
3. **Google Gemini**: Good balance of accuracy and cost
4. **Auto**: Automatically selects best available provider

### Performance Optimization

- Use batch processing for multiple images (up to 20 at once)
- Limit `max_pages_per_pdf` for large documents (default: 10)
- Enable `preprocess` for scanned documents (improves accuracy)
- Use `text` format for fastest processing
- Enable caching for repeated extractions

### Output Format Selection

- **Plain Text**: Fastest, best for simple text extraction
- **Markdown**: Good for documents with headings and lists
- **HTML**: Best for preserving complex layouts
- **JSON**: Best for programmatic processing and metadata

### Quality Assurance

- Always review extracted text for accuracy
- Compare results from different providers for critical documents
- Use `include_metadata` to check confidence scores
- Enable `preserve_layout` for structured documents

## Error Handling

### Common Errors

1. **Missing Source**: Must provide at least one source document
2. **Invalid Attachment**: Attachment ID doesn't exist or file not found
3. **Permission Denied**: User lacks `upload_files` capability
4. **File Too Large**: PDF exceeds 50MB limit
5. **Provider Error**: AI provider API call failed

### Troubleshooting

- Check that Document Generation Toolkit is enabled in settings
- Verify AI provider API keys are configured
- Ensure attachments exist and are accessible
- Check file size limits (50MB max)
- Review WordPress error logs for details

## Integration with Document Creation

The Pro Document OCR tool integrates seamlessly with document generation workflows:

1. **Extract Text from Template**: Use OCR to extract text from document templates
2. **Batch Process Scans**: Convert scanned documents to searchable text
3. **Generate Structured Data**: Export as JSON for further processing
4. **Create WordPress Content**: Save extracted text as posts/pages
5. **Build Document Libraries**: Batch process and catalog documents

## Compliance and Standards

Built on international AI standards:

- **ISO/IEC 42001:2023**: AI management system standard
- **NIST AI Risk Management Framework**: Trustworthy AI principles
- **EU AI Act**: Transparency and risk categorization
- **GDPR/CCPA**: Privacy-compliant data handling

## Technical Details

### Architecture

- **Class**: `WP_MCP_AI_Tool_Pro_Document_OCR`
- **Location**: `addons/pro/includes/tools/document-generation/`
- **Dependencies**: Uses existing OCR service, no new packages
- **Interfaces**: `WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`
- **Traits**: `WP_MCP_AI_Tool_Chat_Response`, `WP_MCP_AI_Tool_Document_Response`, `WP_MCP_AI_Attachment_File_Resolver`

### Capability Flags

- `pro`: Pro tier feature
- `requires-credentials`: Requires AI provider API keys
- `requires-capability`: Requires `upload_files` capability
- `requires-vision-model`: Uses vision-capable AI models
- `read-only`: Only reads/analyzes data
- `external-api`: Makes external API calls
- `network-dependent`: Requires internet connectivity
- `consumes-tokens`: Uses AI model tokens
- `model-dependent`: Quality varies by model
- `async`: May take significant time
- `rate-limited`: Subject to API rate limits
- `cacheable`: Results can be cached

### Performance

- **Single Page PDF**: ~2-5 seconds
- **10 Page PDF**: ~15-30 seconds
- **Single Image**: ~1-3 seconds
- **Batch (20 images)**: ~30-60 seconds

Times vary based on provider, document complexity, and image quality.

## Version History

- **1.4.0** (2026-02-14): Initial release
  - Multi-page PDF processing
  - Batch image processing
  - Multiple output formats
  - Layout preservation
  - Structured metadata
  - Export to attachments

## See Also

- [OCR PDF Text Tool](class-wp-mcp-ai-tool-ocr-pdf-text.php) - Basic OCR for scanned PDFs
- [Extract Image Text Tool](../../../includes/tools/class-wp-mcp-ai-tool-extract-image-text.php) - Base OCR for images
- [Extract PDF Text Tool](class-wp-mcp-ai-tool-extract-pdf-text.php) - Text extraction from digital PDFs
- [Document Generation Toolkit](../document-generation-toolkit-init.php) - Main toolkit initialization
