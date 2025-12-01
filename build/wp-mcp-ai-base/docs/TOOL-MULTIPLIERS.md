# Tool Multipliers Enhancement

## Overview

The Token Limits by Tool page now supports **tool multipliers** that allow you to adjust base tier limits for resource-intensive tools.

## What Are Tool Multipliers?

Tool multipliers are scaling factors applied to base tier limits. For example:
- A tool with a `2.0×` multiplier will have double the token limit of the base tier
- A Free tier user (50k tokens/day) would get 100k tokens/day for that tool
- A Pro tier user (200k tokens/day) would get 400k tokens/day for that tool

## Default Multipliers

The following tools have default multipliers:

| Tool | Multiplier | Reason |
|------|------------|--------|
| `run_crawl4ai_job` | 2.0× | Web scraping generates large outputs |
| `search_content` | 1.5× | Search results can be verbose |
| `web_search` | 1.5× | External API calls return substantial data |
| `submit_document_prompt` | 2.0× | Document processing requires higher limits |

## Configuring Multipliers

### Via Admin UI

1. Navigate to **Settings → WP oOS → Token Usage Manager**
2. Click the **Per Tool** tab
3. Adjust multipliers in the **Multiplier** column (0.1 to 10.0 range)
4. Click **Save All Tool Settings**

### Programmatically

```php
// Set a custom multiplier for a tool
WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( 'my_custom_tool', 3.5 );

// Get all multipliers
$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

// Get multiplier for a specific tool
$multiplier = isset( $multipliers['my_custom_tool'] ) 
    ? $multipliers['my_custom_tool'] 
    : 1.0; // Default
```

### Via Filters

```php
add_filter( 'wp_mcp_ai_tool_limit_multiplier', function( $multiplier, $tool_slug ) {
    if ( 'my_special_tool' === $tool_slug ) {
        return 4.0;
    }
    return $multiplier;
}, 10, 2 );
```

## Tier Reference

Base limits for each tier:

- **Free**: 50,000 tokens/day
- **Pro**: 200,000 tokens/day
- **Enterprise**: 1,000,000 tokens/day

## Effective Limits Display

The Per Tool page shows effective limits for each tier based on the multiplier:

```
Multiplier: 2.0×
Effective Limits:
  F: 100,000 (50k × 2.0)
  P: 400,000 (200k × 2.0)
  E: 2,000,000 (1M × 2.0)
```

## Usage Tracking

The **Usage %** column shows total tokens used across all users as a percentage of the Enterprise tier effective limit. Color coding:

- 🟢 **Green** (0-49%): Healthy usage
- 🟠 **Orange** (50-79%): Moderate usage
- 🔴 **Red** (80-100%): High usage

## Storage

Custom multipliers are stored in the `wp_options` table under the `wp_mcp_ai_tool_multipliers` key as a serialized array.

## Validation

Multipliers are validated to ensure:
- Minimum value: 0.1
- Maximum value: 10.0
- Tool slug must not be empty
- Value must be numeric

## Examples

### High-Output Tool

```php
// A tool that generates very large responses
WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( 'generate_book_chapter', 5.0 );
```

### Low-Output Tool

```php
// A simple utility tool
WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( 'get_site_url', 0.5 );
```

## See Also

- [Token Management](token-management.md)
- [Tool Reference](tool-reference.md)
- [Token Usage Manager Enhancement Plan](TOKEN-MANAGER-ENHANCEMENT-PLAN.md)
