# Image Tools Capability Flags Enhancement

## Overview

This document describes the capability flags enhancement for the WP oOS image manipulation tools (Graphic Editor Suite). These flags provide metadata to the orchestration layer and AI assistants for better workflow planning and resource management.

## Enhanced Tools

The following image manipulation tools now have enhanced capability flags:

1. **resize_image** - Image resizing tool
2. **crop_image** - Image cropping tool
3. **rotate_image** - Image rotation and flipping tool
4. **convert_image_format** - Image format conversion tool
5. **remove_background** - Background removal tool (with dynamic flags)

## Capability Flags Explained

### Common Flags (All Image Tools)

- **`requires-capability`** - Tool requires the `upload_files` WordPress capability to execute
- **`write`** - Tool creates new media files in the WordPress Media Library
- **`idempotent`** - Tool can be safely called multiple times with the same parameters and will produce the same result
- **`performance-impact`** - Large images may temporarily affect server performance during processing

### Local Processing Tools (resize, crop, rotate, convert_image_format)

- **`local-only`** - Tool works entirely locally without making external API calls

### Remove Background Tool (Dynamic Flags)

The `remove_background` tool dynamically adjusts its capability flags based on configuration:

#### Without API Key (Local Mode - using rembg)
```php
array(
    'requires-capability',
    'write',
    'local-only',
    'idempotent',
    'performance-impact',
)
```

#### With API Key (External API Mode - using remove.bg)
```php
array(
    'requires-capability',
    'write',
    'external-api',
    'requires-credentials',
    'network-dependent',
    'consumes-tokens',
    'rate-limited',
    'idempotent',
    'performance-impact',
)
```

**Additional flags when API key is configured:**
- **`external-api`** - Tool makes external HTTP requests to remove.bg API
- **`requires-credentials`** - Tool requires API key configuration
- **`network-dependent`** - Tool requires internet connectivity
- **`consumes-tokens`** - Tool uses external API credits/tokens
- **`rate-limited`** - Tool is subject to API rate limiting

## Benefits for Orchestration

### 1. Resource Planning
AI assistants can determine:
- Whether a tool runs locally or requires external services
- If network connectivity is needed
- Whether API credits will be consumed
- If the operation is idempotent (safe to retry)

### 2. Performance Optimization
The orchestration layer can:
- Schedule resource-intensive operations appropriately
- Avoid overwhelming the server with concurrent image processing
- Plan timeouts based on expected execution characteristics

### 3. Error Handling
Better error messages when:
- User lacks required capabilities (`upload_files`)
- API credentials are missing
- Network is unavailable
- Rate limits are exceeded

### 4. Workflow Intelligence
AI assistants can:
- Choose the optimal tool based on available resources
- Fall back from paid API to free local processing
- Warn users about potential costs before execution
- Batch similar operations to minimize API calls

## Usage Example

### Checking Capability Flags

```php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-resize-image.php';

$tool = new WP_MCP_AI_Tool_Resize_Image();
$flags = $tool->get_capability_flags();

if ( in_array( 'local-only', $flags, true ) ) {
    echo 'This tool runs entirely locally.';
}

if ( in_array( 'performance-impact', $flags, true ) ) {
    echo 'Warning: Large images may affect server performance.';
}
```

### Dynamic Flag Example (remove_background)

```php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';

// Without API key
delete_option( 'wp_mcp_ai_removebg_api_key' );
$tool = new WP_MCP_AI_Tool_Remove_Background();
$flags = $tool->get_capability_flags();
// Result: local-only mode flags

// With API key
update_option( 'wp_mcp_ai_removebg_api_key', 'YOUR_API_KEY' );
$tool = new WP_MCP_AI_Tool_Remove_Background();
$flags = $tool->get_capability_flags();
// Result: external-api mode flags
```

## Testing

A comprehensive test suite is available in `tests/test-image-tools-capability-flags.php`:

```bash
# Run all image tools capability flag tests
vendor/bin/phpunit tests/test-image-tools-capability-flags.php

# Run specific test
vendor/bin/phpunit --filter test_remove_background_capability_flags_with_api_key tests/test-image-tools-capability-flags.php
```

### Test Coverage

- ✅ Verifies all tools implement `WP_MCP_AI_Tool_Capability_Flags_Interface`
- ✅ Validates correct flags for each tool
- ✅ Tests dynamic behavior of `remove_background` based on configuration
- ✅ Ensures consistency across all image manipulation tools

## Implementation Details

### Code Changes

Each tool now overrides the base class `get_capability_flags()` method:

```php
/**
 * {@inheritdoc}
 */
public function get_capability_flags() {
    return array(
        'requires-capability',  // Requires upload_files capability.
        'write',                // Creates new media files.
        'local-only',           // Works locally without external APIs.
        'idempotent',           // Can be called multiple times safely with same result.
        'performance-impact',   // Large images may temporarily affect performance.
    );
}
```

### Backwards Compatibility

These changes are **fully backwards compatible**:
- Tools already inherited base capability flags
- New flags only add metadata, don't change functionality
- Existing code continues to work without modification
- Tests ensure no regression

## Future Enhancements

Potential future improvements:
1. Add `streaming-capable` flag for real-time progress updates
2. Add `supports-batch` flag for bulk operations
3. Add estimated execution time metadata
4. Add memory usage estimates for large images

## Related Documentation

- [Tool Reference Guide](tool-reference.md) - Complete tool documentation
- [Capability Flags Usage](capability-flags-usage.md) - Using capability flags in workflows
- [Tool Grouping](tool-grouping.md) - Organizing tools by capabilities
- [Image Manipulation Tools](IMAGE-MANIPULATION-TOOLS.md) - Graphic Editor Suite overview

## Changelog

### Version 1.0 (December 2025)
- Added enhanced capability flags to all 5 image manipulation tools
- Implemented dynamic flag system for `remove_background` tool
- Created comprehensive test suite
- Added inline documentation for all flags
