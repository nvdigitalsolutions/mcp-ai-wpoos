# Provider Dropdown Filtering Feature

## Overview

This feature filters provider dropdowns throughout the admin interface to show only providers that are both **enabled** (via settings checkboxes) and **properly configured** (have required credentials).

## Implementation Date

January 16, 2026

## Problem Solved

Previously, all provider dropdowns showed all 7 providers (OpenAI, Anthropic, Gemini, Ollama, LM Studio, Cloudflare, Huggingface) regardless of whether they were enabled or configured. This led to:
- User confusion when selecting unconfigured providers
- Failed requests when users selected providers without API keys
- Poor user experience

## Solution

### Centralized Filtering Function

All provider dropdowns now use `WP_MCP_AI_Admin_Settings::get_available_providers()` which:
1. Loads `WP_MCP_AI_Model_Config` if not already loaded
2. Calls `WP_MCP_AI_Model_Config::get_available_providers()` 
3. Returns only providers that pass both checks:
   - Enable checkbox is checked (enable_openai, enable_gemini, etc.)
   - Required credentials are present (API keys or endpoint URLs)

### Provider Enable Logic

| Provider | Enable Check | Credential Check | Default Enabled |
|----------|-------------|------------------|-----------------|
| OpenAI | enable_openai | openai_api_key | Yes (true) |
| Anthropic | enable_anthropic | anthropic_api_key | Yes (true) |
| Gemini | enable_gemini | gemini_api_key | Yes (true) |
| Ollama | enable_ollama | ollama_endpoint_url | Yes (true) |
| LM Studio | enable_lm_studio | lm_studio_endpoint_url | Yes (true) |
| Cloudflare | enable_cloudflare | cloudflare_api_token + cloudflare_account_id | No (false) |
| Huggingface | enable_huggingface | huggingface_api_key | No (false) |

## Files Modified

### Core Logic
1. **includes/class-wp-mcp-ai-model-config.php**
   - Enhanced `get_available_providers()` to check enable_* settings
   - Added consistent logic for all providers including Huggingface

2. **includes/admin/class-wp-mcp-ai-admin-settings.php**
   - Rewrote `get_available_providers()` to delegate to Model_Config
   - Added fallback for environments where Model_Config isn't loaded
   - Supports both base and pro deployment modes

### UI Locations (6 dropdowns updated)
3. **includes/admin/class-wp-mcp-ai-add-assistant-page.php**
   - Create Assistant modal provider dropdown

4. **includes/assistants/metaboxes/class-wp-mcp-ai-metabox-defaults.php**
   - Assistant CPT edit screen provider metabox

5. **includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-defaults.php**
   - Profession CPT edit screen provider dropdown

6. **includes/teams/class-wp-mcp-ai-team-cpt.php**
   - Team CPT edit screen provider dropdown

7. **includes/admin/class-wp-mcp-ai-build-assistant-page.php**
   - Build Assistant page provider dropdown

## Backward Compatibility

- ✅ Maintains `wp_mcp_ai_allowed_providers` filter hook
- ✅ Graceful fallback if Model_Config unavailable
- ✅ Works with existing assistants that may have unconfigured providers
- ✅ Compatible with both base and pro deployment modes

## Testing

Manual testing scenarios:
1. Disable a provider via checkbox → should disappear from dropdowns
2. Remove API key from enabled provider → should disappear from dropdowns  
3. Re-enable and add API key → should reappear in dropdowns
4. Verify all 6 dropdown locations show consistent results

Automated tests:
- PHP syntax validation: ✅ Passed
- Provider filtering logic: ✅ Passed (see /tmp/test-provider-filtering.php)
- WordPress coding standards: ✅ Passed

## Usage

### For Users
1. Go to **Settings → NV oOS → Providers**
2. Enable desired providers via checkboxes
3. Add required API keys/endpoints
4. Provider dropdowns throughout admin will show only enabled+configured providers

### For Developers
```php
// Get filtered list of available providers
$providers = WP_MCP_AI_Admin_Settings::get_available_providers();
// Returns: array( 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', ... )

// Use in dropdowns
foreach ( $providers as $slug => $label ) {
    echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
}
```

## Benefits

1. **User Experience**: Users only see providers they can actually use
2. **Error Prevention**: Prevents selection of unconfigured providers
3. **Consistency**: Same filtering logic across all 6 dropdown locations
4. **Maintainability**: Single source of truth for provider filtering
5. **Flexibility**: Supports dynamic enable/disable without code changes
