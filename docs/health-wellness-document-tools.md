# Health & Wellness Document Processing Tools

## Overview

The Health & Wellness toolkit now includes 13 powerful document processing tools from the Document Generation toolkit, enabling comprehensive medical document management, extraction, and generation capabilities.

## Available Tools

### Document Extraction & Import

#### `extract_pdf_text`
Extract text content from medical PDF documents such as lab reports, doctor's notes, prescriptions, and discharge summaries.

**Use Cases:**
- Extract text from scanned lab reports for record keeping
- Parse prescription information from PDF documents
- Import doctor's notes from PDF files
- Extract discharge summary information

**Example:**
```json
{
  "tool": "extract_pdf_text",
  "arguments": {
    "attachment_id": 123,
    "max_pages": 5
  }
}
```

#### `excel_data_import`
Import structured health data from Excel spreadsheets.

**Use Cases:**
- Bulk import medical history from Excel files
- Import lab results from spreadsheet format
- Load medication schedules from Excel
- Import vaccination records

**Example:**
```json
{
  "tool": "excel_data_import",
  "arguments": {
    "attachment_id": 456,
    "sheet_name": "Lab Results",
    "has_headers": true
  }
}
```

### Professional Document Generation

#### `pro_pdf_document`
Generate professional health reports and summaries as PDF documents with custom styling and branding.

**Use Cases:**
- Create comprehensive health summary reports
- Generate patient progress reports
- Create medical consultation summaries
- Generate treatment plans as PDFs

**Example:**
```json
{
  "tool": "pro_pdf_document",
  "arguments": {
    "operation": "generate",
    "title": "Health Summary Report - John Doe",
    "description": "Comprehensive health summary including medical history, current medications, and recent lab results",
    "content": "Patient: John Doe\nDate: 2024-02-13\n\nMedical History:...",
    "author": "Dr. Smith"
  }
}
```

#### `pro_word_document`
Generate medical documents in Microsoft Word format for sharing with healthcare providers.

**Use Cases:**
- Create referral letters
- Generate medical correspondence
- Create treatment plans in editable format
- Generate health insurance documentation

**Example:**
```json
{
  "tool": "pro_word_document",
  "arguments": {
    "operation": "generate",
    "title": "Referral Letter - Specialist Consultation",
    "content": "Medical correspondence content...",
    "format": "professional"
  }
}
```

#### `pro_excel_document`
Export health data to Excel spreadsheets for analysis and sharing.

**Use Cases:**
- Export medication schedules
- Create lab results tracking spreadsheets
- Generate appointment calendars
- Export vaccination records

**Example:**
```json
{
  "tool": "pro_excel_document",
  "arguments": {
    "operation": "create",
    "title": "Medication Schedule",
    "sheets": [
      {
        "name": "Daily Medications",
        "data": [...]
      }
    ]
  }
}
```

### Quick Document Generation

#### `generate_pdf`
Quick PDF generation for prescriptions, reports, and summaries.

**Use Cases:**
- Generate prescription PDFs
- Create quick health summaries
- Generate appointment reminders
- Create medical notes

#### `generate_word`
Quick Word document generation for editable medical documents.

**Use Cases:**
- Create patient instructions
- Generate medical forms
- Create health questionnaires

#### `generate_excel`
Quick Excel generation for health data tracking.

**Use Cases:**
- Create symptom tracking spreadsheets
- Generate medication logs
- Create appointment tracking sheets

### Document Conversion

#### `html_to_pdf`
Convert HTML-formatted health records to PDF format.

**Use Cases:**
- Convert online health records to PDF
- Archive web-based medical information
- Convert formatted notes to PDF
- Create PDF versions of web reports

**Example:**
```json
{
  "tool": "html_to_pdf",
  "arguments": {
    "html_content": "<h1>Health Record</h1><p>Content...</p>",
    "filename": "health-record.pdf",
    "page_size": "letter"
  }
}
```

### Document Management

#### `merge_pdfs`
Combine multiple medical documents into consolidated PDF files.

**Use Cases:**
- Merge multiple lab reports into one document
- Consolidate prescription PDFs
- Combine medical imaging reports
- Create comprehensive medical history PDFs

**Example:**
```json
{
  "tool": "merge_pdfs",
  "arguments": {
    "attachment_ids": [123, 456, 789],
    "output_filename": "complete-medical-records.pdf"
  }
}
```

#### `add_watermark_to_pdf`
Add confidentiality watermarks to medical documents to protect Protected Health Information (PHI).

**Use Cases:**
- Mark documents as "CONFIDENTIAL"
- Add "DRAFT" watermarks to preliminary reports
- Mark documents with patient names
- Add date stamps to medical records

**Example:**
```json
{
  "tool": "add_watermark_to_pdf",
  "arguments": {
    "attachment_id": 123,
    "watermark_text": "CONFIDENTIAL - PATIENT MEDICAL RECORD",
    "opacity": 0.3,
    "position": "center"
  }
}
```

### Data Export

#### `excel_data_export`
Export consolidated health data to Excel format.

**Use Cases:**
- Export all medications to Excel
- Create comprehensive lab results spreadsheet
- Export appointment history
- Generate health metrics tracking sheets

**Example:**
```json
{
  "tool": "excel_data_export",
  "arguments": {
    "data_source": "medical_records",
    "member_id": 42,
    "include_fields": ["date", "type", "provider", "notes"],
    "filename": "john-doe-medical-history.xlsx"
  }
}
```

#### `generate_invoice_pdf`
Generate medical billing invoices in PDF format.

**Use Cases:**
- Create patient billing statements
- Generate insurance claim documents
- Create itemized medical service invoices
- Generate payment receipts

**Example:**
```json
{
  "tool": "generate_invoice_pdf",
  "arguments": {
    "invoice_number": "INV-2024-001",
    "patient_name": "John Doe",
    "items": [
      {
        "description": "Office Visit",
        "amount": 150.00
      },
      {
        "description": "Lab Tests",
        "amount": 350.00
      }
    ],
    "total": 500.00
  }
}
```

## Integration with Parse Health Information

The `parse_health_information` tool works seamlessly with document extraction tools:

1. Upload a medical document (PDF, Excel, etc.)
2. Use `extract_pdf_text` or `excel_data_import` to extract content
3. Pass extracted text to `parse_health_information`
4. AI automatically creates structured medical records, prescriptions, allergies, etc.

**Example Workflow:**
```
User uploads lab report PDF
↓
extract_pdf_text extracts: "Patient: John Doe, Test: Cholesterol, Result: 180 mg/dL, Date: 2024-02-10"
↓
parse_health_information receives extracted text
↓
AI creates medical record with structured data:
- Type: lab-result
- Title: "Cholesterol Test Results"
- Date: 2024-02-10
- Provider: [from document]
- Details: Complete test results
```

## Workflow Examples

### Example 1: Consolidating Medical Records

**Scenario:** Patient has multiple PDF documents from different healthcare providers and wants to consolidate them.

**Steps:**
1. Upload all PDFs to WordPress media library
2. Use `merge_pdfs` to combine into single document
3. Use `add_watermark_to_pdf` to mark as "CONFIDENTIAL"
4. Use `extract_pdf_text` to extract all text content
5. Use `parse_health_information` to create structured records

### Example 2: Creating Health Summary Report

**Scenario:** Generate a comprehensive health summary for a new doctor.

**Steps:**
1. Use `list_medical_records`, `list_prescriptions`, `list_allergies` to gather data
2. Use `pro_pdf_document` to generate professional summary with:
   - Patient demographics
   - Medical history
   - Current medications
   - Known allergies
   - Recent lab results
3. Add watermark for confidentiality

### Example 3: Importing Health Data from Excel

**Scenario:** Patient has tracked their blood pressure readings in Excel.

**Steps:**
1. Upload Excel file to media library
2. Use `excel_data_import` to extract readings
3. Use `parse_health_information` to create medical records for each reading
4. Generate summary chart with `generate_health_chart`

### Example 4: Medical Billing Workflow

**Scenario:** Generate invoices for medical services.

**Steps:**
1. Retrieve appointment and service data
2. Use `generate_invoice_pdf` to create itemized invoice
3. Use `merge_pdfs` to attach supporting documentation
4. Send to patient or insurance company

## HIPAA Compliance Considerations

When using document processing tools with Protected Health Information (PHI):

1. **Access Control**: All tools respect WordPress user capabilities
2. **Audit Trails**: Document generation and access is logged
3. **Encryption**: Generated documents can be stored in secure locations
4. **Watermarking**: Use `add_watermark_to_pdf` to mark sensitive documents
5. **Retention**: Configure auto-delete policies in Document Generation settings
6. **Data Minimization**: Only extract and store necessary information

## Configuration

Document generation tools inherit settings from the Document Generation Toolkit:

- **Storage Location**: `wp-content/uploads/documents/` (configurable)
- **Auto-Delete**: Configurable retention period (default: 30 days)
- **Default Formats**: PDF, DOCX, XLSX
- **Page Size**: Letter, A4, Legal
- **Branding**: Company logo and name for headers/footers

Access settings at: **Members → Settings → Tools**

## Best Practices

1. **Always watermark sensitive documents** with patient confidentiality notices
2. **Use structured data extraction** with `parse_health_information` after importing documents
3. **Consolidate related documents** using `merge_pdfs` before archiving
4. **Export to Excel** for data analysis and sharing with other healthcare systems
5. **Generate professional PDFs** for sharing with healthcare providers
6. **Configure retention policies** to automatically delete old generated documents
7. **Verify extracted data** before creating structured records from scanned documents

## Requirements

- **Node.js** (optional): For advanced PDF/Word/Excel generation features
- **NPM Packages**: pdfkit, docx, exceljs (optional, PHP fallbacks available)
- **PHP Extensions**: GD library for watermarking
- **System Tools**: pdftotext (poppler-utils) for optimal PDF text extraction

## Troubleshooting

### "PDF text extraction requires pdftotext utility"
Install poppler-utils:
- Ubuntu/Debian: `apt-get install poppler-utils`
- macOS: `brew install poppler`

### "NPM packages not installed"
Run `npm install` in `addons/pro/` directory for optimal performance.

### Generated documents not saving
Check that `wp-content/uploads/documents/` directory exists and is writable.

## Support

For issues or questions about document processing tools:
- Review the Document Generation toolkit documentation
- Check the tool reference guide
- Contact support with specific error messages

## Related Documentation

- [Document Generation Toolkit](../addons/pro/includes/tools/document-generation/README.md)
- [Health & Wellness Management](./health-wellness-management.md)
- [Parse Health Information Tool](./tools/parse-health-information.md)
- [Tool Reference Guide](./tool-reference.md)
