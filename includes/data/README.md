# Compliance Data

This directory contains embedded compliance framework data that is generated from the markdown Statement of Applicability files.

## Purpose

The compliance data is embedded in PHP to ensure it's always available in the deployed plugin, even if the `docs/` directory is excluded from distribution. This provides:

- ✅ Reliable data access in production
- ✅ No file I/O overhead
- ✅ Guaranteed availability for Pro Dashboard

## Files

- `class-wp-mcp-ai-compliance-data.php` - Auto-generated compliance data class

## Regenerating Data

When compliance documentation is updated, regenerate the embedded data:

```bash
php bin/generate-compliance-data.php
```

This will:
1. Parse `docs/compliance/iso27001/Statement-of-Applicability.md`
2. Parse `docs/compliance/soc2/Statement-of-Applicability.md`
3. Parse `docs/compliance/hipaa/Statement-of-Applicability.md`
4. Generate a new `class-wp-mcp-ai-compliance-data.php` file

## Usage

The data is automatically loaded and used by:
- `WP_MCP_AI_Pro_Dashboard` - Main Pro Dashboard UI
- `WP_MCP_AI_Pro_Dashboard_REST` - REST API endpoints

Both classes use embedded data as the primary source with automatic fallback to markdown file parsing for development environments.

## Do Not Edit

**Never manually edit `class-wp-mcp-ai-compliance-data.php`!**

The file is auto-generated and will be overwritten. Instead:
1. Edit the source markdown files in `docs/compliance/`
2. Run the generation script to update the embedded data
3. Commit both the markdown and generated PHP file

## Data Structure

The embedded data includes:

### ISO 27001
- All 93 controls from Annex A
- Implementation status (implemented, partial, planned, not_applicable)
- Applicability flags
- Justifications
- Pre-calculated statistics

### SOC 2
- Compliance percentage calculation
- Based on ISO 27001 implementation

### HIPAA
- Compliance percentage calculation
- Based on ISO 27001 implementation

## Integration

The compliance data class is loaded early in the plugin initialization:

```php
// mcp-ai-wpoos.php
require_once WP_MCP_AI_PATH . 'includes/data/class-wp-mcp-ai-compliance-data.php';
```

It's then used by the Pro Dashboard:

```php
// Primary: Use embedded data
if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
    $controls = WP_MCP_AI_Compliance_Data::get_iso27001_controls();
}

// Fallback: Parse markdown files (development)
if ( empty( $controls ) && file_exists( $file ) ) {
    // Parse markdown...
}
```

## Maintenance

Remember to regenerate the embedded data when:
- Adding new controls to Statement of Applicability
- Updating control implementation status
- Changing applicability of controls
- Before major releases
- After compliance audits

This ensures the Pro Dashboard always displays current, accurate compliance information.
