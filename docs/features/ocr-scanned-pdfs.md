# OCR for Scanned PDFs - Documentation

## Overview

The Document Generation Toolkit now includes **OCR (Optical Character Recognition)** capabilities to extract text from scanned/image-only PDF documents. This feature automatically detects when a PDF contains no machine-readable text and applies OCR using multiple providers with intelligent fallback.

## Key Features

### Multi-Provider Support

The system supports 4 OCR providers with automatic fallback:

1. **OpenAI GPT-4 Vision** (Primary)
   - Highest accuracy
   - Best for complex layouts
   - Requires OpenAI API key
   
2. **Google Gemini Vision** (Alternative)
   - High accuracy
   - Good language support
   - Requires Gemini API key

3. **Ollama Vision Models** (Local)
   - Privacy-focused (no data leaves server)
   - Requires Ollama with vision model (e.g., llava)
   - No API costs

4. **Tesseract OCR** (Fallback)
   - Open-source, reliable
   - Requires system install or PHP package
   - Best for standard documents

### Auto-Detection

The enhanced `extract_pdf_text` tool automatically:
- Tries standard PDF text extraction first
- Detects scanned PDFs (< 50 readable characters)
- Automatically applies OCR when needed
- Reports which extraction method was used

### Image Preprocessing

For better OCR accuracy, images are automatically preprocessed:
- **Resize**: Optimize to 2048px max dimension
- **Grayscale**: Convert to grayscale for better contrast
- **Normalization**: Auto-adjust contrast levels
- **Sharpening**: Enhance text edges
- **Noise Reduction**: Remove artifacts
- **Gamma Correction**: Improve overall clarity

Two preprocessing engines supported:
- **Sharp** (Node.js) - Preferred, high performance
- **Imagick** (PHP extension) - Fallback

## Usage

### Using the Enhanced extract_pdf_text Tool

```php
// Basic usage - OCR enabled by default
$result = $tool->execute(
    array(
        'attachment_id' => 123,  // or 'url' => 'https://...'
    )
);

// Explicitly configure OCR
$result = $tool->execute(
    array(
        'attachment_id' => 123,
        'enable_ocr'    => true,       // Enable OCR fallback (default: true)
        'ocr_provider'  => 'auto',     // auto, openai, gemini, ollama, tesseract
        'max_pages'     => 10,         // Limit pages for OCR (default: all for standard, 10 for OCR)
    )
);
```

### Using the Dedicated ocr_pdf_text Tool

```php
// For explicitly OCR-only extraction
$result = $tool->execute(
    array(
        'attachment_id' => 123,
        'max_pages'     => 5,          // Default: 10 (OCR is resource-intensive)
        'provider'      => 'auto',     // Select OCR provider
        'preprocess'    => true,       // Enable preprocessing (default: true)
        'language'      => 'eng',      // OCR language (default: eng)
    )
);
```

### Using the OCR Service Directly

```php
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';

$ocr_service = new WP_MCP_AI_OCR_Service();

// Check if PDF is scanned
if ( $ocr_service->is_scanned_pdf( $pdf_path ) ) {
    // Extract text using OCR
    $text = $ocr_service->extract_text_from_pdf(
        $pdf_path,
        array(
            'max_pages' => 10,
            'provider'  => 'auto',
            'dpi'       => 300,  // Image quality
        )
    );
}

// Extract text from a single image
$text = $ocr_service->extract_text_from_image(
    $image_path,
    array(
        'provider'   => 'openai',
        'preprocess' => true,
        'language'   => 'eng',
    )
);
```

## Installation

### Required Dependencies

**Already Installed:**
- ✅ PHP 8.1+
- ✅ WordPress 6.0+
- ✅ Sharp (Node.js) - via npm

**Automatic in Pro Plugin:**
- ✅ `thiagoalessio/tesseract_ocr` - PHP Tesseract wrapper

**Optional System Dependencies:**

For best results, install these system packages:

```bash
# Imagick PHP extension (for PDF to image conversion)
sudo apt-get install php-imagick

# Tesseract OCR (for local OCR without AI APIs)
sudo apt-get install tesseract-ocr

# Additional language packs for Tesseract
sudo apt-get install tesseract-ocr-spa  # Spanish
sudo apt-get install tesseract-ocr-fra  # French
# See: https://github.com/tesseract-ocr/tesseract/wiki/Data-Files

# Poppler utils (alternative PDF converter)
sudo apt-get install poppler-utils
```

### Configuration

OCR providers are configured in WordPress admin:
- **Settings → NV oOS → API Keys**
  - OpenAI API Key
  - Gemini API Key
  - Ollama Endpoint (for local)

No additional configuration needed - OCR works automatically with existing API keys.

## Response Format

### extract_pdf_text with OCR

```json
{
  "text": "Extracted text content...",
  "word_count": 1234,
  "char_count": 7890,
  "extraction_method": "ocr",
  "ocr_provider": "openai"
}
```

### ocr_pdf_text Tool

```json
{
  "text": "Extracted text content...",
  "word_count": 1234,
  "char_count": 7890,
  "provider": "openai",
  "is_scanned": true,
  "duration": 12.5,
  "pages": 5
}
```

## Performance Considerations

### Speed

OCR is significantly slower than standard PDF extraction:
- **Standard extraction**: < 1 second
- **OCR with API**: 2-5 seconds per page
- **OCR with Tesseract**: 3-10 seconds per page

### Resource Usage

- **CPU**: High during image preprocessing
- **Memory**: ~100-500MB per page depending on DPI
- **API Costs**: OpenAI/Gemini charge per API call

### Best Practices

1. **Limit pages**: Use `max_pages` parameter for large PDFs
2. **Use standard first**: Let auto-detection try standard extraction
3. **Cache results**: Store extracted text to avoid re-processing
4. **Choose provider wisely**:
   - OpenAI/Gemini: Best accuracy, costs apply
   - Ollama: Good for privacy, requires local setup
   - Tesseract: Good for simple documents, free

## Language Support

### OpenAI & Gemini
- Automatic language detection
- Supports 100+ languages out of the box

### Ollama
- Depends on model (llava supports major languages)
- Check model documentation

### Tesseract
- Requires language packs installed
- Use `language` parameter:
  - `'eng'` - English
  - `'spa'` - Spanish
  - `'fra'` - French
  - `'deu'` - German
  - `'chi_sim'` - Chinese Simplified
  - See: [Tesseract Languages](https://github.com/tesseract-ocr/tessdoc/blob/main/Data-Files.md)

## Error Handling

```php
$result = $tool->execute( $args );

if ( isset( $result['error'] ) ) {
    // Handle error
    echo 'OCR failed: ' . $result['error'];
}
```

Common errors:
- `file_not_found` - Invalid attachment ID or URL
- `no_api_key` - API key not configured
- `tesseract_not_found` - Tesseract not installed
- `no_converter` - No PDF to image converter available
- `ocr_failed` - All OCR providers failed

## Logging

OCR operations are logged when logging is enabled:

```php
// Enable in WordPress admin
Settings → NV oOS → Enable Logging

// Or via constant
define( 'WP_MCP_AI_DEBUG', true );
```

Log events:
- `ocr_extraction_success` - Successful OCR
- `ocr_page_failed` - Individual page failure
- `pdf_ocr_fallback` - Auto-fallback to OCR
- `ocr_preprocessing_failed` - Preprocessing issues
- `tesseract_wrapper_failed` - PHP wrapper issues

## Troubleshooting

### "No machine-readable text found"

This is expected for scanned PDFs. The system will automatically use OCR if `enable_ocr` is true.

### "Tesseract OCR not installed"

Install Tesseract:
```bash
sudo apt-get install tesseract-ocr
```

Or install PHP wrapper:
```bash
cd addons/pro
composer install
```

### "No PDF to image converter available"

Install Imagick:
```bash
sudo apt-get install php-imagick
sudo service php8.1-fpm restart  # or apache2 restart
```

Or install poppler-utils:
```bash
sudo apt-get install poppler-utils
```

### "Image preprocessing failed"

Check if Sharp is installed:
```bash
cd addons/pro
npm install
```

Or install Imagick (fallback):
```bash
sudo apt-get install php-imagick
```

### Low OCR Accuracy

1. **Increase DPI**: Higher quality images = better OCR
   ```php
   'dpi' => 300  // or 600 for very small text
   ```

2. **Enable preprocessing**: Improves image quality
   ```php
   'preprocess' => true
   ```

3. **Try different provider**: Some providers work better for specific content
   ```php
   'provider' => 'openai'  // Often most accurate
   ```

4. **Check source quality**: Ensure original scan is clear

## API Reference

### WP_MCP_AI_OCR_Service

#### Methods

##### `extract_text_from_image( $image_path, $options )`
Extract text from a single image file.

**Parameters:**
- `$image_path` (string) - Path to image file
- `$options` (array) - Optional parameters:
  - `provider` (string) - OCR provider (default: 'auto')
  - `preprocess` (bool) - Enable preprocessing (default: true)
  - `language` (string) - OCR language (default: 'eng')
  - `enhance` (bool) - Enhance image quality (default: true)

**Returns:** `string|WP_Error` - Extracted text or error

##### `extract_text_from_pdf( $pdf_path, $options )`
Extract text from PDF using OCR.

**Parameters:**
- `$pdf_path` (string) - Path to PDF file
- `$options` (array) - Optional parameters:
  - `max_pages` (int) - Max pages to process (default: 0 = all)
  - `provider` (string) - OCR provider (default: 'auto')
  - `dpi` (int) - DPI for PDF to image (default: 300)

**Returns:** `string|WP_Error` - Extracted text or error

##### `is_scanned_pdf( $pdf_path )`
Check if PDF appears to be scanned (image-only).

**Parameters:**
- `$pdf_path` (string) - Path to PDF file

**Returns:** `bool` - True if PDF is scanned

## Examples

### Example 1: Extract Text with Auto-OCR

```php
// Will use standard extraction first, OCR if needed
$tool = new WP_MCP_AI_Tool_Extract_PDF_Text();
$result = $tool->execute(
    array( 'attachment_id' => 123 )
);

if ( isset( $result['error'] ) ) {
    wp_die( 'Error: ' . $result['error'] );
}

echo "Extracted {$result['word_count']} words\n";
echo "Method: {$result['extraction_method']}\n";
echo "\nText:\n{$result['text']}";
```

### Example 2: Force OCR with Specific Provider

```php
$tool = new WP_MCP_AI_Tool_OCR_PDF_Text();
$result = $tool->execute(
    array(
        'url'        => 'https://example.com/scanned.pdf',
        'provider'   => 'gemini',
        'max_pages'  => 3,
        'language'   => 'spa',  // Spanish
    )
);
```

### Example 3: Batch Process Multiple PDFs

```php
$pdfs = array( 123, 456, 789 );  // Attachment IDs

foreach ( $pdfs as $pdf_id ) {
    $result = $tool->execute(
        array(
            'attachment_id' => $pdf_id,
            'max_pages'     => 5,
        )
    );
    
    if ( ! isset( $result['error'] ) ) {
        // Store extracted text
        update_post_meta( $pdf_id, '_extracted_text', $result['text'] );
    }
}
```

### Example 4: Check Before Processing

```php
$ocr_service = new WP_MCP_AI_OCR_Service();
$pdf_path = get_attached_file( 123 );

if ( $ocr_service->is_scanned_pdf( $pdf_path ) ) {
    echo "This PDF is scanned and will need OCR\n";
    
    // Process with appropriate limits
    $text = $ocr_service->extract_text_from_pdf(
        $pdf_path,
        array(
            'max_pages' => 10,
            'provider'  => 'auto',
        )
    );
} else {
    echo "This PDF has readable text\n";
    // Use standard extraction (faster)
}
```

## Additional Resources

- [Tesseract OCR Documentation](https://github.com/tesseract-ocr/tesseract)
- [Sharp Documentation](https://sharp.pixelplumbing.com/)
- [OpenAI Vision API](https://platform.openai.com/docs/guides/vision)
- [Google Gemini Vision](https://ai.google.dev/gemini-api/docs/vision)

## Support

For issues or questions:
- Check the [Troubleshooting](#troubleshooting) section
- Review WordPress error logs
- Enable debug logging to see detailed OCR flow
- Contact support with log details
