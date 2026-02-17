# OpenAI Vector Storage Best Practices

## Overview

This guide provides industry-standard best practices for using OpenAI vector stores effectively with the MCP AI WordPress plugin (mcp-ai-wpoos). These recommendations are based on 2024 research and real-world RAG (Retrieval-Augmented Generation) implementations.

**🎯 Pro Plugin Users**: See [Pro Plugin Vector Storage Tools](../../addons/pro/docs/VECTOR_STORAGE_PRO_TOOLS.md) for automatic file conversion and advanced preprocessing capabilities.

## Table of Contents

1. [Supported File Formats](#supported-file-formats)
2. [File Preprocessing](#file-preprocessing)
3. [Chunking Strategies](#chunking-strategies)
4. [Optimization Tips](#optimization-tips)
5. [Common Issues & Solutions](#common-issues--solutions)
6. [Pro Plugin Enhancements](#pro-plugin-enhancements)

---

## Supported File Formats

### ✅ Reliable Formats (Recommended)

These formats work consistently with OpenAI vector stores and provide reliable text extraction:

| Format | Extension | Use Case | Notes |
|--------|-----------|----------|-------|
| **PDF** | `.pdf` | Documents, reports, manuals | Most reliable format; ensure text-based, not scanned images |
| **Plain Text** | `.txt` | Unstructured text, logs | Lossless extraction; UTF-8 encoding required |
| **Markdown** | `.md` | Documentation, articles | Preserves structure well; good for technical content |
| **DOCX** | `.docx` | Word documents | Good support; clean up track changes first |
| **JSON** | `.json` | Structured data, API docs | Best for well-formatted structured content |
| **HTML** | `.html` | Web content | Clean up navigation/boilerplate first |

### ⚠️ Unreliable Formats (Convert First)

These formats often fail or produce poor results with vector stores. **Convert to PDF or TXT before uploading:**

| Format | Extension | Issue | Solution |
|--------|-----------|-------|----------|
| **Spreadsheets** | `.csv`, `.xlsx`, `.xls` | Poor parsing, structure loss | Export to clean PDF or structured text |
| **Presentations** | `.pptx`, `.ppt` | Unreliable extraction | Convert to PDF with speaker notes |
| **Images** | `.png`, `.jpg` (in assistants) | No text extraction from images | Use OCR first, or use vision API separately |

### 📏 File Size Limits

- **Assistants/Vector Stores**: 512 MB per file
- **Vision API**: 20 MB per image
- **Fine-tuning**: 1 GB per file
- **Whisper (audio)**: 25 MB per file

**Organization limits**: 100 GB total storage, 10,000 files per vector store (check latest OpenAI docs).

---

## File Preprocessing

### 1. Clean Up Content

Before uploading files to vector stores:

**Remove noise:**
- Headers and footers
- Navigation elements (for HTML)
- Excessive whitespace
- Advertisements and sidebars
- Track changes and comments (DOCX)
- Hidden content and metadata

**Preserve structure:**
- Keep headings and section markers
- Maintain logical hierarchy
- Preserve lists and tables in readable format
- Keep inline links and references

### 2. Encoding

- **Always use UTF-8 encoding** for text files
- Verify encoding before upload (tool: `analyze_file_suitability`)
- Non-UTF-8 files may cause extraction errors

### 3. Text Extraction Validation

**For PDFs:**
- Ensure PDF contains actual text layer, not scanned images
- Use OCR tools (like Tesseract) for scanned documents
- "Linearize" PDFs to remove complex formatting
- Remove embedded images not needed for content understanding

**For Spreadsheets (if you must use them):**
1. Export to PDF or clean text format
2. Preserve logical table structure
3. Add context headers for each section
4. Consider converting each sheet to separate file

**For DOCX:**
- Accept all track changes before exporting
- Remove comments and hidden text
- Convert complex formatting to simpler styles
- Ensure heading styles are properly applied

---

## Chunking Strategies

Vector stores automatically chunk files into smaller text units. However, **pre-chunking can improve results**.

### Optimal Chunk Size

**Recommended: 256-512 tokens per chunk**
- Smaller chunks: Better for precise retrieval, more granular
- Larger chunks (up to 1000 tokens): Better context, risk of dilution

**General rule:**
- Technical docs: 256-400 tokens
- Narrative content: 400-512 tokens
- Legal/formal docs: 512-1000 tokens

### Chunk Overlap

**Use 10-20% overlap** (e.g., 50-100 tokens for 512-token chunks)
- Prevents information loss at boundaries
- Ensures queries near chunk edges retrieve relevant context

### Chunking Methods (Pre-processing)

1. **Semantic Chunking** (Best, but compute-intensive)
   - Group by topic using sentence embeddings
   - Best for technical documentation and knowledge bases

2. **Recursive/Structural Chunking** (Recommended default)
   - Split at paragraph, section, or sentence boundaries
   - Use hierarchy: sections → paragraphs → sentences
   - Balances quality and performance

3. **Token/Character-based** (Simple, fast)
   - Fixed-size chunks
   - Use with overlap to reduce context loss
   - Good for cost-sensitive scenarios

### Implementation Tip

For manual pre-chunking, create separate files for each logical section:
```
knowledge-base/
  ├── introduction.txt
  ├── getting-started.txt
  ├── api-reference.txt
  └── troubleshooting.txt
```

---

## Optimization Tips

### 1. Metadata Management

- Use vector store metadata to track:
  - Source document
  - Page numbers or sections
  - Upload date
  - Content type or category
- Metadata improves filtering and source attribution

### 2. File Organization

**Group related content:**
- One vector store per topic/project
- Separate public vs. private knowledge bases
- Use clear naming conventions

**Avoid:**
- Mixing unrelated content in one vector store
- Uploading duplicate files
- Leaving outdated/stale content

### 3. Quality Over Quantity

- **10 well-prepared files > 100 poorly formatted files**
- Spend time on preprocessing
- Validate extraction quality after upload
- Sample retrieval results to test effectiveness

### 4. Update Strategy

- Set appropriate expiration dates for time-sensitive content
- Regularly review and update knowledge bases
- Remove outdated files promptly
- Version control for documentation

---

## Common Issues & Solutions

### Issue 1: Poor Retrieval Quality

**Symptoms:**
- Irrelevant results returned
- Missing expected information
- Low confidence scores

**Solutions:**
1. Check file format (convert CSV/XLSX to PDF)
2. Improve file preprocessing (remove noise)
3. Reduce chunk size for more granular retrieval
4. Add more context to document sections
5. Verify UTF-8 encoding

### Issue 2: Upload Failures

**Symptoms:**
- File rejected by OpenAI API
- Timeout errors
- Parse errors

**Solutions:**
1. Check file size (must be under limits)
2. Verify file format is supported
3. Convert problematic formats (CSV, XLSX) to PDF
4. Check for file corruption
5. Reduce file size by removing images

### Issue 3: Text Extraction Problems

**Symptoms:**
- Missing content in retrieval
- Garbled or incomplete text
- Wrong language/encoding

**Solutions:**
1. For PDFs: Ensure text layer exists (not scanned images)
2. Run OCR on scanned documents
3. Convert to UTF-8 encoding
4. Linearize complex PDFs
5. Remove embedded images and rich media

### Issue 4: CSV/XLSX Not Working

**Expected:** These formats are unreliable in vector stores.

**Solutions:**
1. **Best:** Export to clean PDF with preserved structure
2. **Alternative:** Convert to structured text format:
   ```
   Product Name: Example Product
   Category: Electronics
   Price: $99.99
   Description: This is a sample product...
   
   ---
   
   Product Name: Another Product
   ...
   ```
3. For large datasets: Split into multiple smaller, well-formatted files

### Issue 5: Slow Processing

**Solutions:**
1. Pre-chunk large files into smaller units
2. Remove unnecessary embedded content
3. Optimize PDF structure (linearize)
4. Consider parallel uploads for multiple files
5. Use appropriate purpose flag ('assistants' for vector stores)

---

## Tools Available in MCP AI WordPress Plugin

### File Preparation

- `analyze_file_suitability` - Check if file is ready for upload
- Validates format, size, encoding
- Provides preprocessing recommendations

### Vector Store Management

- `create_vector_store` - Create new vector store
- `manage_vector_store_files` - Add/remove/list files
- `get_vector_store` - Retrieve vector store details
- `list_vector_stores` - List all available stores

### File Upload

- `get_openai_file_details` - Verify upload success
- `list_openai_files` - List uploaded files

---

## Pro Plugin Enhancements

**🚀 Automatic File Preparation** (Pro Plugin Only)

The Pro plugin includes advanced tools that automate the preprocessing workflow:

### `prepare_file_for_vector_store` Tool

**What it does:**
- ✅ Automatically converts CSV/XLSX to structured text
- ✅ Applies OCR to scanned PDFs (multiple providers)
- ✅ Fixes UTF-8 encoding issues
- ✅ Cleans formatting (headers, footers, whitespace)
- ✅ Generates chunk previews for validation

**Example Workflow:**

Instead of manual conversion:
```
1. Upload CSV file
2. Manually export to PDF
3. Upload PDF to vector store
4. Hope for best results
```

With Pro plugin:
```
1. Upload CSV file
2. Run: prepare_file_for_vector_store(attachment_id: 123)
3. Pro tool converts to structured markdown automatically
4. Upload optimized file to vector store
5. Get excellent RAG results
```

**Benefits:**
- ⚡ **One-Click Optimization**: No manual conversion needed
- 🎯 **Better Results**: Structured format optimized for RAG
- 🔍 **Preview Before Upload**: See how content will be chunked
- 📊 **Multiple Formats**: Handles CSV, XLSX, scanned PDFs, encoding issues

**See Full Documentation:**
- [Pro Plugin Vector Storage Tools](../../addons/pro/docs/VECTOR_STORAGE_PRO_TOOLS.md)

**Availability:**
- Pro plugin with composer dependencies installed
- Automatic registration when pro plugin is active

---

## Quick Start Checklist

Before uploading files to a vector store:

- [ ] File format is PDF, TXT, DOCX, MD, JSON, or HTML
- [ ] If CSV/XLSX/PPTX, converted to PDF or TXT (or use Pro tool)
- [ ] File encoding is UTF-8 (for text files)
- [ ] Removed headers, footers, and navigation elements
- [ ] PDF contains text layer (not scanned images)
- [ ] File size is under 512 MB
- [ ] Content is logically structured with clear sections
- [ ] Ran `analyze_file_suitability` tool
- [ ] Addressed any warnings or recommendations
- [ ] (Pro) Used `prepare_file_for_vector_store` for automatic optimization

---

## Additional Resources

- [OpenAI Vector Stores API](https://platform.openai.com/docs/api-reference/vector-stores)
- [OpenAI Assistants Documentation](https://platform.openai.com/docs/assistants/overview)
- [RAG Best Practices (External)](https://www.anthropic.com/research/rag)
- [Pro Plugin Vector Storage Tools](../../addons/pro/docs/VECTOR_STORAGE_PRO_TOOLS.md) (Pro users)

---

## Summary

**Key Takeaways:**

1. ✅ Use PDF, TXT, DOCX, MD, JSON, or HTML
2. ❌ Avoid CSV, XLSX, PPTX (convert first, or use Pro tool)
3. 🔧 Preprocess: Clean up, verify UTF-8, remove noise
4. 📏 Optimal chunks: 256-512 tokens with 10-20% overlap
5. 🎯 Quality over quantity - well-prepared files perform better
6. 🚀 Pro Plugin: Automatic conversion and optimization available

For questions or issues, consult the tool descriptions or contact support.
