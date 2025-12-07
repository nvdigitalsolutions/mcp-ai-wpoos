# WP oOS Settings Dashboard

This directory contains the modern, modular settings dashboard system for WP Open Operator System.

## Overview

The settings dashboard replaces the monolithic 6318-line `class-wp-mcp-ai-admin-settings.php` with a modular, tab-based interface that is:

- **Maintainable**: Easy to update without breaking existing functionality
- **Safe**: Isolated components reduce regression risks
- **Extensible**: Simple to add new features without touching core files
- **Developer-friendly**: Clear structure, easier debugging and testing

## Architecture

### Core Components

1. **Settings Registry** (`class-wp-mcp-ai-settings-registry.php`)
   - Central registry for all plugin settings
   - Manages sections and tabs
   - Provides unified get/set methods for settings

2. **Settings Validator** (`class-wp-mcp-ai-settings-validator.php`)
   - Validation utilities for common field types
   - URL, email, number, API key validation
   - Reusable validation methods

3. **Settings Dashboard** (`class-wp-mcp-ai-settings-dashboard.php`)
   - Main controller for the settings interface
   - Handles rendering, saving, and validation
   - Manages tab navigation

4. **Abstract Section Base** (`sections/abstract-wp-mcp-ai-settings-section.php`)
   - Base class that all sections extend
   - Common field rendering logic
   - Default sanitization and validation

### Section Implementations

Each tab has its own dedicated section class:

- **General** (`class-wp-mcp-ai-section-general.php`)
  - Basic plugin settings
  - Logging, cleanup, history, timeout
  
- **Providers** (`class-wp-mcp-ai-section-providers.php`)
  - AI provider configurations
  - OpenAI, Google Gemini, Ollama, LM Studio

- **Authentication** (`class-wp-mcp-ai-section-authentication.php`)
  - Auth0 configuration
  - JWT, Guest tokens, Gravatar bridge

- **Tools** (`class-wp-mcp-ai-section-tools.php`)
  - Tools & features configuration
  - Mesh computing, federation

- **Integrations** (`class-wp-mcp-ai-section-integrations.php`)
  - Third-party integrations
  - JetEngine, WooCommerce, Gmail, Crawl4AI

- **Security** (`class-wp-mcp-ai-section-security.php`)
  - Security settings
  - Root key, rate limiting

- **Advanced** (`class-wp-mcp-ai-section-advanced.php`)
  - Advanced configuration
  - Memory limits, extended logging, OPcache

## File Structure

```
includes/admin/
├── class-wp-mcp-ai-settings-dashboard.php       # Main dashboard controller
├── class-wp-mcp-ai-settings-registry.php        # Settings registry
├── class-wp-mcp-ai-settings-validator.php       # Validation utilities
├── settings-dashboard-init.php                  # Initialization loader
├── sections/
│   ├── abstract-wp-mcp-ai-settings-section.php  # Base class
│   ├── class-wp-mcp-ai-section-general.php
│   ├── class-wp-mcp-ai-section-providers.php
│   ├── class-wp-mcp-ai-section-authentication.php
│   ├── class-wp-mcp-ai-section-tools.php
│   ├── class-wp-mcp-ai-section-integrations.php
│   ├── class-wp-mcp-ai-section-security.php
│   └── class-wp-mcp-ai-section-advanced.php
└── class-wp-mcp-ai-admin-settings.php           # Legacy (still active)
```

## Usage

### Accessing Settings

```php
// Get a setting value
$value = WP_MCP_AI_Settings_Registry::get_setting( 'openai_api_key', '' );

// Update a setting value
WP_MCP_AI_Settings_Registry::update_setting( 'openai_api_key', 'sk-...' );

// Update multiple settings
WP_MCP_AI_Settings_Registry::update_settings( array(
    'openai_api_key' => 'sk-...',
    'default_model'  => 'gpt-4o',
) );
```

### Creating a New Section

1. Create a new class extending `WP_MCP_AI_Settings_Section`
2. Implement required methods: `get_id()`, `get_title()`, `get_tab()`, `get_fields()`, `render()`
3. Add validation logic in `validate()` method
4. Register the section in `settings-dashboard-init.php`

Example:

```php
class WP_MCP_AI_Section_Custom extends WP_MCP_AI_Settings_Section {
    public function get_id() {
        return 'custom';
    }
    
    public function get_title() {
        return __( 'Custom Settings', 'wp-mcp-ai' );
    }
    
    public function get_tab() {
        return 'advanced'; // Or create a new tab
    }
    
    public function get_fields() {
        return array(
            'custom_field' => array(
                'type'        => 'text',
                'label'       => __( 'Custom Field', 'wp-mcp-ai' ),
                'description' => __( 'Custom field description', 'wp-mcp-ai' ),
            ),
        );
    }
    
    public function render() {
        $fields = $this->get_fields();
        foreach ( $fields as $key => $field ) {
            $this->render_field( $key, $field );
        }
    }
}
```

## Feature Flag

The new dashboard is enabled by default. To use the legacy settings instead:

```php
// In wp-config.php
define( 'WP_MCP_AI_USE_OLD_SETTINGS', true );
```

## Testing

Tests are located in `tests/test-settings-dashboard.php` and cover:

- Section registration
- Field validation
- Input sanitization
- URL/email/number validation

Run tests:

```bash
composer run test -- tests/test-settings-dashboard.php
```

## Frontend Assets

- **CSS**: `assets/css/settings-dashboard.css` - Modern tab-based styling
- **JS**: `assets/js/settings-dashboard.js` - Tab switching and UI interactions

## Migration from Legacy Settings

The new dashboard uses the same option name (`wp_mcp_ai_settings`) as the legacy system, ensuring seamless data compatibility. Both systems can coexist during the transition period.

### Legacy Compatibility

- Same option storage: `wp_mcp_ai_settings`
- Same capability checks: `manage_options`
- Same sanitization principles
- Backward compatible with existing code

## Future Enhancements

Planned features (as per SETTINGS-RESTRUCTURE-PLAN.md):

- [ ] Settings search/filter
- [ ] Export/import settings
- [ ] Reset to defaults per section
- [ ] Contextual inline help
- [ ] Connection testing for providers
- [ ] Real-time validation feedback

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: `docs/` directory
