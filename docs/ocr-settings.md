# OCR Settings Documentation

## Overview

This document describes the new OCR (Optical Character Recognition) settings added to the Document Generation Toolkit in the NV oOS (Open Operator System) WordPress plugin.

## Location

The OCR settings are available at:
```
WordPress Admin → Document Templates → Settings
URL: /wp-admin/edit.php?post_type=mcp_ai_doc_tpl&page=document-generation-settings
```

## Settings Fields

### 1. OCR Provider

**Field Type:** Dropdown select  
**Default Value:** Auto (Detect Best Available)

**Options:**
- **Auto (Detect Best Available)** - Automatically selects the best available provider based on configured API keys
- **OpenAI GPT-4 Vision** - Uses OpenAI's GPT-4 Vision model (requires API key)
- **Google Gemini Vision** - Uses Google's Gemini Vision model (requires API key)
- **Ollama Vision Models (Local)** - Uses local Ollama installation (requires endpoint)
- **Tesseract OCR (System)** - Uses system-installed Tesseract

**Features:**
- Providers without configured API keys are disabled with "(API Key Required)" label
- Link to Provider Settings page for easy configuration: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers`

**Description:** "Select the OCR provider for extracting text from scanned images and PDFs. Auto mode automatically selects the best available provider."

### 2. OCR Fallback Provider

**Field Type:** Dropdown select  
**Default Value:** Auto (Try All Available)

**Options:**
- **Auto (Try All Available)** - Tries all configured providers in order
- **OpenAI GPT-4 Vision**
- **Google Gemini Vision**
- **Ollama Vision Models**
- **Tesseract OCR**
- **None (No Fallback)** - Fails immediately if primary provider fails

**Description:** "If the primary provider fails, this provider will be used as fallback. Auto mode tries all available providers in order."

### 3. OCR Preprocessing

**Field Type:** Checkbox  
**Default Value:** Enabled (checked)

**Label:** "Enable image preprocessing (grayscale, contrast, noise reduction)"

**Description:** "Preprocessing improves OCR accuracy for low-quality images. Disable if images are already optimized."

### 4. OCR Timeout

**Field Type:** Number input  
**Default Value:** 300 seconds  
**Range:** 30-600 seconds  
**Step:** 30 seconds

**Label:** "seconds" (displayed after input)

**Description:** "Maximum time to wait for OCR processing before timing out. Range: 30-600 seconds."

## How Settings Are Used

### Provider Selection Priority

When OCR is invoked, the service checks settings in this order:

1. **Explicit Provider Setting**: If `ocr_provider` is set to a specific provider (not "auto"), uses that provider
2. **Auto-Detection**: If set to "auto", checks for available API keys in this order:
   - OpenAI (if `openai_api_key` configured)
   - Gemini (if `gemini_api_key` configured)
   - Ollama (if `ollama_endpoint` configured)
   - Tesseract (if installed on system)

### Fallback Behavior

When the primary provider fails:

1. **Specific Fallback**: If a specific fallback provider is configured, tries only that provider
2. **Auto Fallback**: If set to "auto", tries all remaining providers in order
3. **No Fallback**: If set to "none", fails immediately without retry

### Preprocessing

When enabled (default):
- Converts images to grayscale
- Enhances contrast
- Reduces noise
- Improves OCR accuracy for poor quality scans

When disabled:
- Uses images as-is
- Faster processing
- Better for high-quality, pre-optimized images

### Timeout

- Applied to each OCR operation
- Range: 30-600 seconds (0.5-10 minutes)
- Prevents indefinite hangs on large documents
- Different from HTTP timeouts (handles long-running AI processing)

## Technical Implementation

### Settings Storage

Settings are stored in the WordPress options table under:
```php
$option_name = 'wp_mcp_ai_document_generation_settings';
```

Keys:
- `ocr_provider` (string)
- `ocr_fallback_provider` (string)
- `ocr_preprocessing` (boolean)
- `ocr_timeout` (integer)

### Code Locations

**Settings Page Registration:**
```php
File: addons/pro/includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php
Methods:
- register_settings() - Registers the 4 new fields
- render_ocr_provider_field() - Renders provider dropdown
- render_ocr_fallback_provider_field() - Renders fallback dropdown
- render_ocr_preprocessing_field() - Renders preprocessing checkbox
- render_ocr_timeout_field() - Renders timeout input
```

**Settings Usage:**
```php
File: addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php
Methods:
- determine_best_provider() - Reads ocr_provider setting
- get_fallback_providers() - Reads ocr_fallback_provider setting
- extract_text_from_image() - Reads ocr_preprocessing and ocr_timeout settings
```

### API Key Detection

The OCR provider dropdown intelligently detects available API keys:

```php
$main_settings = get_option( 'wp_mcp_ai_settings', array() );

// OpenAI detection
if ( empty( $main_settings['openai_api_key'] ) ) {
    // Option disabled with "(API Key Required)" label
}

// Gemini detection
if ( empty( $main_settings['gemini_api_key'] ) ) {
    // Option disabled with "(API Key Required)" label
}

// Ollama detection
if ( empty( $main_settings['ollama_endpoint'] ) ) {
    // Option disabled with "(Endpoint Required)" label
}
```

## Example Configurations

### Configuration 1: OpenAI Primary with Gemini Fallback
- **OCR Provider:** OpenAI GPT-4 Vision
- **OCR Fallback Provider:** Google Gemini Vision
- **OCR Preprocessing:** Enabled
- **OCR Timeout:** 300 seconds

**Use Case:** High-accuracy OCR with cloud backup

### Configuration 2: Local-Only Processing
- **OCR Provider:** Ollama Vision Models (Local)
- **OCR Fallback Provider:** Tesseract OCR
- **OCR Preprocessing:** Enabled
- **OCR Timeout:** 600 seconds

**Use Case:** Privacy-focused, no external API calls

### Configuration 3: Fast Processing for Pre-Optimized Images
- **OCR Provider:** Auto
- **OCR Fallback Provider:** None
- **OCR Preprocessing:** Disabled
- **OCR Timeout:** 60 seconds

**Use Case:** High-quality scans that don't need enhancement

### Configuration 4: Maximum Reliability
- **OCR Provider:** Auto
- **OCR Fallback Provider:** Auto (Try All Available)
- **OCR Preprocessing:** Enabled
- **OCR Timeout:** 300 seconds

**Use Case:** Mission-critical document processing

## Testing

Comprehensive tests have been added in:
```
addons/pro/tests/test-ocr-settings.php
```

Tests cover:
- Provider selection from settings
- Auto-detection mode
- Fallback configuration
- Preprocessing toggle
- Timeout configuration

## Benefits

1. **Flexibility**: Users can choose their preferred OCR provider
2. **Reliability**: Automatic fallback ensures processing continues even if one provider fails
3. **Cost Control**: Users can prioritize local/free providers (Ollama, Tesseract)
4. **Performance**: Preprocessing and timeout can be tuned for specific use cases
5. **Privacy**: Option to use only local providers (no cloud APIs)
6. **Visibility**: Clear indication of which providers are configured and available

## Migration

### Existing Users

For users who were already using OCR tools:
- Default behavior remains unchanged (auto-detection)
- No action required
- Can configure preferences if desired

### New Users

New users should:
1. Configure at least one provider's API key in Provider Settings
2. Or set up Ollama for local processing
3. Or install Tesseract on the system
4. Adjust OCR settings based on their use case

## Related Documentation

- **OCR Service:** `addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php`
- **OCR Tools:** `addons/pro/includes/tools/document-generation/`
- **Provider Settings:** WordPress Admin → NV oOS → Providers
- **Tool Reference:** `docs/tools/pro/document-generation.md`

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: `docs/` directory in repository
