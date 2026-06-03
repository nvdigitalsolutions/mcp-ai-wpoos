# Toolkit Slash Commands - Quick Start Guide

## Overview

Toolkit-specific slash commands provide quick access to 400+ automation workflows across 31 toolkits. Commands are only available when their toolkit is enabled.

**Status:** Phase 2 Implementation (4 commands working, 396 coming soon)

---

## Currently Implemented Commands

### Content & Publishing Toolkit

#### `/content-draft` - Create Draft Content

Creates a new draft post with AI-assisted setup.

**Usage:**
```bash
/content-draft --topic="Your Topic" [--type=post] [--tone=professional]
```

**Parameters:**
- `--topic` (required): Topic or title for the content
- `--type` (optional): Post type (post, page, product). Default: post
- `--tone` (optional): Writing tone (professional, casual, technical). Default: professional

**Example:**
```bash
/content-draft --topic="AI Trends in 2024" --type=post --tone=casual
```

**Returns:**
- `post_id`: Created post ID
- `edit_url`: Link to edit the post
- `topic`, `type`, `tone`: Confirmed parameters

**Required Capability:** `edit_posts`

---

#### `/content-enhance` - Enhance Existing Content

Analyzes content and provides enhancement suggestions.

**Usage:**
```bash
/content-enhance --post_id=123
```

**Parameters:**
- `--post_id` (required): ID of post to enhance

**Example:**
```bash
/content-enhance --post_id=456
```

**Returns:**
- `post_id`, `post_title`: Post identification
- `suggestions`: Array of improvement suggestions
  - `readability`: Readability improvements
  - `engagement`: Engagement tips
  - `seo`: SEO recommendations

**Required Capability:** `edit_posts`

---

#### `/seo-optimize` - SEO Optimization

Applies SEO best practices to content.

**Usage:**
```bash
/seo-optimize --post_id=123
```

**Parameters:**
- `--post_id` (required): ID of post to optimize

**Example:**
```bash
/seo-optimize --post_id=789
```

**Returns:**
- `meta_description`: Generated or existing meta description
- `optimizations`: Applied optimizations
  - `meta_description`: Boolean
  - `title_length`: Character count
  - `content_length`: Word count
- `recommendations`: Additional SEO tips

**Required Capability:** `edit_posts`

---

### Data & Analytics Toolkit

#### `/data-summarize` - Data Summary

Generates statistical summary of data source.

**Usage:**
```bash
/data-summarize --source="source_name"
```

**Parameters:**
- `--source` (required): Data source identifier

**Example:**
```bash
/data-summarize --source="sales_2024"
```

**Returns:**
- `record_count`: Total records
- `date_range`: Start and end dates
- `statistics`: Statistical measures (total, unique, avg, max, min)
- `trends`: Trend analysis (direction, change %)

**Required Capability:** `edit_posts`

---

## Command Response Format

All commands return a consistent response structure:

### Success Response
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    // Command-specific data
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": "error_code",
  "message": "Human-readable error message"
}
```

---

## Common Error Codes

- `missing_required_param`: Required parameter not provided
- `insufficient_permissions`: User lacks required capability
- `post_not_found`: Specified post does not exist
- `command_error`: Generic command execution error

---

## Upcoming Commands (Phase 2-7)

### Content & Publishing (11 more)
- `/content-translate` - Multi-language content
- `/content-schedule` - Smart scheduling
- `/publish-review` - Review workflow
- `/publish-approve` - Fast approval
- `/meta-generate` - Auto meta tags
- And 6 more...

### Media Processing (14 commands)
- `/video-transcode` - Video format conversion
- `/image-optimize` - Image compression
- `/audio-normalize` - Audio level balancing
- And 11 more...

### Data & Analytics (12 more)
- `/chart-create` - Generate charts
- `/dashboard-build` - Build dashboards
- `/data-trend` - Trend analysis
- And 9 more...

### E-Commerce & Business (16 commands)
- `/order-fulfill` - Order fulfillment
- `/inventory-check` - Stock checking
- `/cart-recover` - Cart recovery
- And 13 more...

### Developer & Technical (15 commands)
- `/code-analyze` - Code analysis
- `/deploy-staging` - Deploy to staging
- `/test-run` - Run tests
- And 12 more...

### Security & Compliance (14 commands)
- `/security-scan` - Security audit
- `/gdpr-check` - GDPR compliance
- `/compliance-report` - Generate reports
- And 11 more...

**Plus 25 more toolkits with 300+ additional commands!**

---

## Integration

### Using in Chat
Simply type the command in the chat interface:
```
/content-draft --topic="My New Blog Post"
```

### Using via API
```php
// Execute command programmatically
$result = wp_mcp_ai_execute_slash_command(
    '/content-draft --topic="API Test"',
    array(
        'user_id' => get_current_user_id(),
    )
);

if ( $result['success'] ) {
    $post_id = $result['data']['post_id'];
    // Use the created post ID
}
```

### Using in Code
```php
// Get handler instance
$handler = wp_mcp_ai_get_slash_command_handler();

// Execute command
$result = $handler->execute(
    '/data-summarize --source="revenue"',
    array( 'user_id' => 1 )
);
```

---

## Toolkit Availability

Commands are automatically available when their toolkit is enabled. To check toolkit status:

```php
// Check if toolkit is enabled
$enabled = apply_filters(
    'wp_mcp_ai_toolkit_enabled',
    true,
    'content_publishing'
);

// Get toolkit commands
$manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
$commands = $manager->get_toolkit_commands( 'content_publishing' );
```

---

## Testing

Use the `--dry-run` flag (when available) to preview command effects:

```bash
/content-draft --topic="Test" --dry-run
```

---

## Support & Feedback

For issues or feature requests:
1. Check `/help [command]` for detailed command help
2. Review full documentation in `docs/TOOLKIT_SLASH_COMMANDS_PROPOSAL.md`
3. Report issues via GitHub issues

---

## Changelog

### v1.3.0 - Phase 2 Week 1
- ✅ Infrastructure implemented
- ✅ Validation framework added
- ✅ 4 commands working:
  - `/content-draft`
  - `/content-enhance`
  - `/seo-optimize`
  - `/data-summarize`
- ✅ Error handling and logging
- ✅ Comprehensive test suite

### Upcoming
- Week 2: 10+ more Priority 1 commands
- Week 3-4: Complete Priority 1 (60 commands)
- Week 5+: Remaining 340 commands

---

**Last Updated:** February 2026  
**Version:** 1.3.0-alpha  
**Status:** Active Development
