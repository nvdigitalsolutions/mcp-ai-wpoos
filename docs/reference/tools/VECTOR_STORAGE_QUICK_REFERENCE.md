# Vector Storage Tools - Quick Reference

## TL;DR

**✅ Use these formats:**
- PDF, TXT, DOCX, MD, JSON, HTML

**❌ Avoid these formats (convert to PDF/TXT first):**
- CSV, XLSX, XLS, PPTX, PPT

**🔧 Before upload:**
1. Check file format (use `analyze_file_suitability` tool)
2. Ensure UTF-8 encoding for text files
3. Remove headers/footers and navigation
4. Verify file size < 512 MB

**📏 Best practices:**
- Chunk size: 256-512 tokens
- Chunk overlap: 10-20% (50-100 tokens)
- Clean formatting before upload
- Use structured sections with clear headings

---

## Available Tools

### File Validation
```
analyze_file_suitability
├─ file_id: WordPress attachment ID
├─ purpose: 'assistants' (for vector stores)
└─ check_content: true
```

**Returns:** Validation warnings and preprocessing recommendations

### Vector Store Management
```
create_vector_store
├─ name: Store name
├─ file_ids: [Optional] Initial files
└─ metadata: [Optional] Key-value pairs
```

```
manage_vector_store_files
├─ vector_store_id: Store ID
├─ action: 'add' | 'remove' | 'list'
├─ file_ids: Array of OpenAI file IDs
└─ limit/order: For 'list' action
```

```
get_vector_store
└─ vector_store_id: Store ID
```

```
list_vector_stores
└─ Returns all available stores
```

---

## File Format Details

### PDF ✅
- Most reliable format
- Must have text layer (not scanned images)
- Optimal size: < 10 MB for faster processing
- **Preprocessing:** Remove embedded images, linearize structure

### Plain Text (TXT, MD) ✅
- Lossless extraction
- UTF-8 encoding required
- Best for unstructured content
- **Preprocessing:** Clean whitespace, add section markers

### DOCX ✅
- Good support
- **Preprocessing:** Accept track changes, remove comments, use heading styles

### JSON ✅
- Structured data
- Must be valid JSON
- **Preprocessing:** Add context fields, format cleanly

### HTML ✅
- Web content
- **Preprocessing:** Remove nav/header/footer, keep semantic structure

### CSV/XLSX/PPTX ❌
- **Unreliable:** OpenAI may fail to parse correctly
- **Solution:** Convert to PDF or structured text
- For spreadsheets: Export each sheet separately with context headers

---

## Common Error Messages

### "Invalid file type" or "Unsupported format"
**Cause:** File format not supported or unreliable
**Solution:**
1. Check if file is CSV/XLSX/PPTX → Convert to PDF/TXT
2. Verify file is not corrupted
3. Use supported format (PDF, TXT, DOCX, MD, JSON, HTML)

### "File too large" or "Size exceeds limit"
**Cause:** File > 512 MB
**Solution:**
1. Remove embedded images
2. Compress content
3. Split into smaller logical sections
4. Export/save in optimized format

### "Parse error" or "Encoding error"
**Cause:** File encoding issue or malformed content
**Solution:**
1. Convert to UTF-8 encoding
2. Clean up formatting
3. Verify file isn't corrupted
4. For PDFs: Ensure text layer exists (run OCR if needed)

### "File not found" (when adding to vector store)
**Cause:** File ID invalid or file expired
**Solution:**
1. Re-upload file to OpenAI
2. Verify file ID is correct
3. Check file wasn't auto-deleted

---

## Preprocessing Checklist

Before uploading any file to vector store:

- [ ] File format is supported (PDF, TXT, DOCX, MD, JSON, HTML)
- [ ] If CSV/XLSX/PPTX → Converted to PDF/TXT
- [ ] Text files are UTF-8 encoded
- [ ] Removed headers, footers, navigation
- [ ] Removed track changes and comments (DOCX)
- [ ] PDF has text layer (not just scanned images)
- [ ] File size < 512 MB
- [ ] Content is well-structured with clear sections
- [ ] Ran `analyze_file_suitability` and addressed warnings

---

## Chunking Guidelines

### Optimal Chunk Size
- **Technical docs:** 256-400 tokens
- **Narrative content:** 400-512 tokens  
- **Legal/formal docs:** 512-1000 tokens

### Chunk Overlap
- Use 10-20% overlap between chunks
- Example: For 512-token chunks, use 50-100 token overlap
- Prevents information loss at boundaries

### When to Pre-chunk
1. Files > 10 MB → Split into sections
2. Books/manuals → Split by chapter
3. API docs → Split by endpoint/method
4. Mixed content → Group by topic

### How to Pre-chunk
- Upload each section as separate file
- Name files clearly: `section-1-introduction.txt`
- Maintain context with brief section headers
- Ensure logical boundaries (don't split mid-concept)

---

## Performance Tips

1. **Quality over quantity:** 10 well-prepared files > 100 poorly formatted ones
2. **Test retrieval:** Sample queries to verify extraction quality
3. **Update regularly:** Remove outdated content, refresh knowledge base
4. **Use metadata:** Tag files with source, date, category for better filtering
5. **Monitor size:** Stay well under 512 MB per file for faster processing

---

## Getting Help

- Full guide: `docs/tools/VECTOR_STORAGE_BEST_PRACTICES.md`
- Preprocessing helper: `WP_MCP_AI_File_Preprocessing_Helper` class
- Tool validation: Use `analyze_file_suitability` before upload
- Error guidance: Enhanced error messages now include troubleshooting tips

---

## Example Workflow

```
1. Prepare file
   ├─ Convert CSV to PDF (if needed)
   ├─ Clean up formatting
   └─ Ensure UTF-8 encoding

2. Validate file
   └─ Run: analyze_file_suitability(file_id, 'assistants')

3. Address warnings
   ├─ Fix encoding issues
   ├─ Convert format if needed
   └─ Reduce size if needed

4. Upload to OpenAI
   └─ Files API uploads file, returns file_id

5. Create/update vector store
   ├─ Option A: create_vector_store(name, [file_ids])
   └─ Option B: manage_vector_store_files('add', [file_ids])

6. Test retrieval
   └─ Sample queries to verify quality
```

---

**Last Updated:** February 2026  
**Based on:** OpenAI vector store API best practices and RAG implementation standards
