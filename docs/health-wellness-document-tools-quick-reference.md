# Health & Wellness Document Tools - Quick Reference

## Common Medical Document Scenarios

### 📄 Extract Text from Lab Report PDF

```javascript
// Step 1: Upload PDF to media library
// Step 2: Extract text
{
  "tool": "extract_pdf_text",
  "arguments": {
    "attachment_id": 123
  }
}

// Step 3: Parse into structured records
{
  "tool": "parse_health_information",
  "arguments": {
    "member_id": 42,
    "raw_information": "[extracted text here]",
    "auto_create_records": true
  }
}
```

### 📊 Export All Medications to Excel

```javascript
{
  "tool": "pro_excel_document",
  "arguments": {
    "operation": "create",
    "title": "Medication Schedule - John Doe",
    "sheets": [{
      "name": "Current Medications",
      "headers": ["Medication", "Dosage", "Frequency", "Start Date", "Prescriber"],
      "data": [
        // Data from list_prescriptions
      ]
    }]
  }
}
```

### 🔐 Add Confidentiality Watermark

```javascript
{
  "tool": "add_watermark_to_pdf",
  "arguments": {
    "attachment_id": 123,
    "watermark_text": "CONFIDENTIAL MEDICAL RECORD",
    "opacity": 0.3,
    "position": "center",
    "color": "#FF0000"
  }
}
```

### 📑 Merge Multiple Medical Documents

```javascript
{
  "tool": "merge_pdfs",
  "arguments": {
    "attachment_ids": [123, 456, 789, 1011],
    "output_filename": "complete-medical-history-2024.pdf",
    "add_page_numbers": true
  }
}
```

### 📝 Generate Health Summary Report

```javascript
{
  "tool": "pro_pdf_document",
  "arguments": {
    "operation": "generate",
    "title": "Health Summary - John Doe",
    "description": "Comprehensive health report including medications, allergies, and recent lab results",
    "content": {
      "patient_info": {
        "name": "John Doe",
        "dob": "1980-05-15",
        "blood_type": "O+"
      },
      "sections": [
        {
          "heading": "Current Medications",
          "content": "..."
        },
        {
          "heading": "Known Allergies",
          "content": "..."
        },
        {
          "heading": "Recent Lab Results",
          "content": "..."
        }
      ]
    },
    "page_size": "letter",
    "include_headers": true,
    "include_footers": true
  }
}
```

### 💰 Generate Medical Invoice

```javascript
{
  "tool": "generate_invoice_pdf",
  "arguments": {
    "invoice_number": "INV-2024-042",
    "date": "2024-02-13",
    "patient": {
      "name": "John Doe",
      "address": "123 Main St",
      "city": "Anytown, CA 12345"
    },
    "provider": {
      "name": "Dr. Jane Smith",
      "facility": "City Medical Center",
      "tax_id": "XX-XXXXXXX"
    },
    "items": [
      {
        "description": "Office Visit - Follow-up",
        "code": "99213",
        "quantity": 1,
        "unit_price": 150.00
      },
      {
        "description": "Complete Blood Count",
        "code": "85025",
        "quantity": 1,
        "unit_price": 75.00
      }
    ],
    "subtotal": 225.00,
    "tax": 0.00,
    "total": 225.00,
    "notes": "Insurance claim submitted. Balance due from patient."
  }
}
```

### 📥 Import Health Data from Excel

```javascript
{
  "tool": "excel_data_import",
  "arguments": {
    "attachment_id": 456,
    "sheet_name": "Blood Pressure Log",
    "has_headers": true,
    "skip_rows": 0,
    "columns": {
      "A": "date",
      "B": "systolic",
      "C": "diastolic",
      "D": "pulse",
      "E": "notes"
    }
  }
}

// Then create records
{
  "tool": "parse_health_information",
  "arguments": {
    "member_id": 42,
    "raw_information": "[imported data]",
    "auto_create_records": true
  }
}
```

### 🌐 Convert HTML Health Record to PDF

```javascript
{
  "tool": "html_to_pdf",
  "arguments": {
    "html_content": "<html>...[health record HTML]...</html>",
    "filename": "health-record-2024-02.pdf",
    "page_size": "letter",
    "margins": {
      "top": "1in",
      "bottom": "1in",
      "left": "1in",
      "right": "1in"
    }
  }
}
```

### 📧 Generate Referral Letter (Word)

```javascript
{
  "tool": "pro_word_document",
  "arguments": {
    "operation": "generate",
    "title": "Referral to Cardiology",
    "content": {
      "letterhead": {
        "from": "Dr. Jane Smith, Primary Care",
        "facility": "City Medical Center"
      },
      "recipient": {
        "name": "Dr. Robert Johnson",
        "specialty": "Cardiology",
        "facility": "Heart Specialists Clinic"
      },
      "body": "Dear Dr. Johnson,\n\nI am referring my patient, John Doe (DOB: 05/15/1980), for cardiology evaluation...",
      "signature": "Dr. Jane Smith, MD"
    },
    "template": "professional_letter"
  }
}
```

## Integration Examples

### Full Workflow: Upload → Extract → Parse → Generate Report

```javascript
// 1. User uploads lab report PDF (attachment_id: 123)

// 2. Extract text
const extracted = await tools.extract_pdf_text({
  attachment_id: 123
});

// 3. Parse into structured records
const parsed = await tools.parse_health_information({
  member_id: 42,
  raw_information: extracted.text,
  auto_create_records: true,
  attachment_ids: [123]
});

// 4. Generate summary report
const report = await tools.pro_pdf_document({
  operation: "generate",
  title: "Lab Results Summary",
  content: `
    Lab Results Processed: ${parsed.records_created}
    
    ${parsed.summary}
  `,
  author: "Automated System"
});

// 5. Add watermark
const final = await tools.add_watermark_to_pdf({
  attachment_id: report.attachment_id,
  watermark_text: "CONFIDENTIAL",
  opacity: 0.3
});
```

### Bulk Document Processing

```javascript
// Process multiple medical documents
const documents = [123, 456, 789]; // Attachment IDs

for (const docId of documents) {
  // Extract text
  const text = await tools.extract_pdf_text({
    attachment_id: docId
  });
  
  // Parse and create records
  await tools.parse_health_information({
    member_id: 42,
    raw_information: text.text,
    auto_create_records: true,
    attachment_ids: [docId]
  });
}

// Merge all documents
const merged = await tools.merge_pdfs({
  attachment_ids: documents,
  output_filename: "consolidated-medical-records.pdf"
});

// Add watermark to merged document
await tools.add_watermark_to_pdf({
  attachment_id: merged.attachment_id,
  watermark_text: "COMPLETE MEDICAL HISTORY - CONFIDENTIAL"
});
```

## Tips & Best Practices

### ✅ DO
- Always watermark sensitive medical documents
- Use structured parsing after text extraction
- Merge related documents before archiving
- Export to Excel for data analysis
- Generate professional PDFs for healthcare providers
- Verify extracted data accuracy

### ❌ DON'T
- Don't share unwatermarked medical documents
- Don't skip validation of extracted data
- Don't store documents indefinitely (configure auto-delete)
- Don't use low-quality scans (affects text extraction)
- Don't forget to backup important documents

## Tool Availability Check

All document tools are available when:
- ✅ Health & Wellness Management is enabled
- ✅ Document Generation Toolkit is enabled (Pro addon)
- ✅ Assistant has access to health toolkit tools

Check in: **Members → Settings → Tools**

## Keyboard Shortcuts (in Chat Interface)

- `Ctrl+U` - Upload document
- `Ctrl+E` - Extract text from last upload
- `Ctrl+P` - Parse health information
- `Ctrl+G` - Generate PDF report
- `Ctrl+M` - Merge documents

## Quick Command Examples (for Chat)

```
"Extract text from the uploaded lab report"
→ Uses: extract_pdf_text

"Create a health summary PDF for John Doe"
→ Uses: pro_pdf_document + list_medical_records

"Merge all my medical documents from last year"
→ Uses: merge_pdfs + add_watermark_to_pdf

"Export my medication list to Excel"
→ Uses: pro_excel_document + list_prescriptions

"Import blood pressure readings from uploaded spreadsheet"
→ Uses: excel_data_import + parse_health_information
```

## Troubleshooting Quick Fixes

| Issue | Quick Fix |
|-------|-----------|
| Can't extract PDF text | Install poppler-utils: `apt-get install poppler-utils` |
| Generated PDF looks bad | Enable Node.js NPM packages for better quality |
| Watermark too visible | Reduce opacity: 0.2-0.3 recommended |
| Merge failed | Check all PDFs are valid and not corrupted |
| Excel import error | Verify column headers and data format |

## Support Resources

- 📖 Full documentation: `/docs/health-wellness-document-tools.md`
- 🛠️ Tool reference: `/docs/tool-reference.md`
- 🔧 Document Generation toolkit: `/addons/pro/includes/tools/document-generation/README.md`
- 💬 Get help: Contact support with error messages

---

**Pro Tip:** Use the `guide_health_record_creation` tool for AI-assisted document processing workflows!
