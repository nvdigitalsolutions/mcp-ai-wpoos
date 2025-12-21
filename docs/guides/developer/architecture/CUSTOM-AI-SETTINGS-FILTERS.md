# Custom AI Settings (Filters) - Admin UI Feature

## Overview

The **Custom AI Settings (Filters)** feature provides a user-friendly admin interface for configuring WordPress filter values that control AI behavior. This allows administrators to customize the plugin's behavior without writing any PHP code.

**Location:** Settings → WP oOS → General Settings → Custom AI Settings (Filters)

**URL:** `admin.php?page=wp-mcp-ai-dashboard&tab=general&subtab=custom_filters`

## Purpose

This feature addresses the question: **"Does this actually work or is it redundant?"**

**Answer:** It **WORKS** and is **NOT REDUNDANT**. This feature:

1. ✅ **Applies settings to real filters** used throughout the codebase
2. ✅ **Provides a code-free configuration method** for non-developers
3. ✅ **Uses the WordPress filter system** with proper priority handling
4. ✅ **Integrates with the Settings Registry** for persistent storage
5. ✅ **Validates all input** to prevent configuration errors

## How It Works

### Architecture

```
Admin UI (class-wp-mcp-ai-section-custom-filters.php)
    ↓ Save settings to database
Settings Registry (class-wp-mcp-ai-settings-registry.php)
    ↓ Retrieve settings
Filters Applicator (class-wp-mcp-ai-custom-filters-applicator.php)
    ↓ Apply at priority 5
WordPress Filters (wp_mcp_ai_*)
    ↓ Used by production code
Plugin Behavior (model selection, retries, timeouts, etc.)
```

### Execution Flow

1. **Admin saves settings** via the Custom AI Settings UI
2. **Settings stored** in `wp_mcp_ai_settings` option
3. **Applicator instantiated** on init (in `settings-dashboard-init.php`)
4. **Filters registered** at priority 5
5. **Production code** applies filters when needed
6. **Custom settings override defaults** (unless empty)

## Available Settings

### AI Model Selection
- **Default Light Model** (`filter_default_light_model`)
  - Default: `gpt-4.1-mini`
  - Filter: `wp_mcp_ai_default_light_model`
  - Used by: `class-wp-mcp-ai-model-selector.php`

- **Default Advanced Model** (`filter_default_advanced_model`)
  - Default: `gpt-4.1`
  - Filter: `wp_mcp_ai_default_advanced_model`
  - Used by: `class-wp-mcp-ai-model-selector.php`

### Resource Management
- **Max Agentic Iterations** (`filter_max_agentic_iterations`)
  - Default: `5`
  - Range: 1-50
  - Filter: `wp_mcp_ai_max_agentic_iterations`
  - Used by: `class-wp-mcp-ai-rest.php`

- **Resource Max Tokens** (`filter_resource_max_tokens`)
  - Default: Auto-detected
  - Range: 100-128000
  - Filter: `wp_mcp_ai_resource_max_tokens`
  - Used by: `class-resource-manager.php`

- **Resource Request Timeout** (`filter_resource_request_timeout`)
  - Default: Auto-detected (30-120s)
  - Range: 10-600 seconds
  - Filter: `wp_mcp_ai_resource_request_timeout`
  - Used by: `class-resource-manager.php`

### Retry & Error Handling
- **Max Retries** (`filter_max_retries`)
  - Default: `3`
  - Range: 0-10
  - Filter: `wp_mcp_ai_max_retries`
  - Used by: `class-wp-mcp-ai-enhanced-openai-client.php`

- **Max Retry Delay** (`filter_max_retry_delay`)
  - Default: `60` seconds
  - Range: 1-300 seconds
  - Filter: `wp_mcp_ai_max_retry_delay`
  - Used by: `class-wp-mcp-ai-enhanced-openai-client.php`

### File & Attachment Limits
- **Max Attachment Size** (`filter_max_attachment_bytes`)
  - Default: `10485760` (10MB)
  - Range: 1024-104857600 bytes (1KB-100MB)
  - Filter: `wp_mcp_ai_max_attachment_bytes`
  - Used by: `class-wp-mcp-ai-message-attachments.php`

### Local AI Endpoint URLs
- **Default Ollama Endpoint URL** (`filter_default_ollama_endpoint_url`)
  - Default: `http://localhost:11434`
  - Filter: `wp_mcp_ai_default_ollama_endpoint_url`
  - Used by: Admin settings sections

- **Default LM Studio Endpoint URL** (`filter_default_lm_studio_endpoint_url`)
  - Default: `http://localhost:1234`
  - Filter: `wp_mcp_ai_default_lm_studio_endpoint_url`
  - Used by: Admin settings sections

- **LM Studio Fallback Model** (`filter_lm_studio_fallback_model`)
  - Default: Uses "Default Model" setting
  - Filter: `wp_mcp_ai_lm_studio_fallback_model`
  - Used by: LM Studio provider when no model is explicitly specified
  - Purpose: Provides OpenAI compatibility for LM Studio local AI


## Usage Examples

### For Non-Developers

1. Go to **WordPress Admin → Settings → WP oOS**
2. Click the **General Settings** tab
3. Click the **Custom AI Settings (Filters)** subtab
4. Configure desired settings:
   - Set default AI models
   - Adjust iteration limits
   - Configure timeouts
   - Set file size limits
   - Configure local AI endpoints
   - Set LM Studio fallback model
5. Click **Save Changes**

Empty fields use system defaults. No code required!

### For Developers

#### Programmatic Override (Priority 1-4)

```php
// Override admin UI setting
add_filter( 'wp_mcp_ai_default_light_model', function( $model ) {
    return 'gpt-3.5-turbo';
}, 1 ); // Priority 1 runs before admin UI's priority 5
```

#### Conditional Logic (Priority 20+)

```php
// Enhance admin UI setting with logic
add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $iterations, $config ) {
    // Admin UI provides base value, we add conditional logic
    if ( isset( $config['complexity'] ) && 'high' === $config['complexity'] ) {
        return max( $iterations, 15 );
    }
    return $iterations;
}, 20, 2 ); // Priority 20 runs after admin UI priority 5
```

## Filter Priority System

- **Priority 1-4:** Programmatic overrides that **always win**
- **Priority 5:** Admin UI settings (**custom-filters-applicator**)
- **Priority 10+:** Conditional enhancements to admin settings

This allows developers to:
- Force specific values (priority 1-4)
- Let admin control settings (priority 5)
- Add conditional logic (priority 20+)

## Validation

All settings are validated on save:

### Number Fields
- Range validation (min/max)
- Integer conversion via `absint()`
- Empty strings allowed (use defaults)

### URL Fields
- `filter_var()` validation
- `esc_url_raw()` sanitization
- Empty strings allowed (use defaults)

### Text Fields
- `sanitize_text_field()` sanitization

## Database Storage

Settings are stored in the `wp_mcp_ai_settings` option as an array:

```php
array(
    'filter_default_light_model' => 'gpt-3.5-turbo',
    'filter_max_agentic_iterations' => 10,
    'filter_max_attachment_bytes' => 20971520,
    // ... other settings
)
```

Empty values are stored as empty strings (`''`) which are treated as "use default".

## Testing

Comprehensive test suite: `tests/test-custom-filters-applicator.php`

Tests verify:
- ✅ Filter hooks are registered
- ✅ Custom settings override defaults
- ✅ Empty strings use defaults
- ✅ Filter priority system works
- ✅ All 11 filter types function correctly

Run tests:
```bash
vendor/bin/phpunit tests/test-custom-filters-applicator.php
```

## Related Documentation

- [Dynamic Configuration Filters](guides/developer/architecture/DYNAMIC-CONFIGURATION-FILTERS.md) - Complete filter reference
- [Best Practices](guides/developer/best-practices/BEST_PRACTICES.md) - Usage recommendations
- [Quick Reference](QUICK_REFERENCE.md) - Fast lookup guide

## Security Considerations

1. **Capability Check:** Only administrators can modify settings
2. **Input Validation:** All inputs validated before saving
3. **Sanitization:** All outputs sanitized before use
4. **Nonce Verification:** Form submissions require valid nonce
5. **Range Limits:** Numeric values clamped to safe ranges

## Common Questions

### Q: Why are my programmatic filters not working?

**A:** Check the priority. Admin UI uses priority 5. Use priority 1-4 to override, or 20+ to enhance.

### Q: Can I disable specific filters in the admin UI?

**A:** Leave the field empty. Empty fields use system defaults.

### Q: Do I need to configure these settings?

**A:** No. All fields are optional. Empty fields use sensible defaults.

### Q: Are these settings per-site or network-wide in multisite?

**A:** Per-site. Each site in a multisite network has its own settings.

### Q: Can I export/import these settings?

**A:** Yes, they're stored in the `wp_mcp_ai_settings` option. Use standard WordPress export/import tools or `wp option` CLI commands.

## Conclusion

The Custom AI Settings (Filters) feature **is fully functional and not redundant**. It provides:

1. ✅ **User-friendly interface** for non-developers
2. ✅ **Real filter integration** used by production code
3. ✅ **Proper validation and sanitization**
4. ✅ **Developer-friendly priority system**
5. ✅ **Comprehensive test coverage**

It successfully bridges the gap between code-free configuration and advanced programmatic customization.
