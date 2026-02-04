# Pro Toolkit Slash Commands & Workflows - Implementation Guide

**Status**: ✅ Complete  
**Date**: February 4, 2026  
**Version**: 1.3.0+

## Overview

This implementation adds concrete slash command handlers and automated workflows for three major pro toolkits:
- E-commerce Pro Toolkit
- Social Media Management Toolkit
- Video Production Toolkit

## New Slash Commands

### E-commerce Pro Toolkit

#### `/upsell-suggest`
Generate AI-powered upsell recommendations for products.

**Usage:**
```bash
/upsell-suggest --product-id=123 [--recommendation-type=product_based] [--limit=5]
```

**Parameters:**
- `--product-id` (required): Product ID to get recommendations for
- `--recommendation-type` (optional): Type of recommendations
  - `product_based`: Based on product relationships (default)
  - `customer_based`: Based on customer behavior
  - `cart_based`: Based on cart contents
  - `frequently_bought`: Frequently bought together
- `--limit` (optional): Maximum number of recommendations (default: 5)

**Required Capability:** `manage_woocommerce`

**Example:**
```bash
/upsell-suggest --product-id=456 --recommendation-type=frequently_bought --limit=10
```

#### `/abandoned-recover`
Identify and recover abandoned carts with automated campaigns.

**Usage:**
```bash
/abandoned-recover [--action=identify] [--cart-id=123] [--send-email]
```

**Parameters:**
- `--action` (optional): Action to perform (default: identify)
  - `identify`: Find abandoned carts
  - `recover`: Initiate recovery process
  - `status`: Get recovery status
- `--cart-id` (optional): Specific cart ID to recover
- `--send-email` (flag): Send recovery email

**Required Capability:** `manage_woocommerce`

**Examples:**
```bash
# Find abandoned carts
/abandoned-recover --action=identify

# Recover specific cart
/abandoned-recover --action=recover --cart-id=789 --send-email

# Check status
/abandoned-recover --action=status
```

#### `/ecom-analytics`
Get comprehensive e-commerce analytics and insights.

**Usage:**
```bash
/ecom-analytics [--period=month] [--metrics=all] [--format=table]
```

**Parameters:**
- `--period` (optional): Time period (default: month)
  - `today`, `week`, `month`, `year`
- `--metrics` (optional): Comma-separated metrics (default: all)
  - `sales`, `orders`, `customers`, `conversion`
- `--format` (optional): Output format (default: table)
  - `table`, `json`, `chart`

**Required Capability:** `manage_woocommerce`

**Example:**
```bash
/ecom-analytics --period=week --metrics=sales,orders,conversion --format=chart
```

### Social Media Management Toolkit

#### `/hashtag-suggest`
Generate relevant hashtag suggestions for content.

**Usage:**
```bash
/hashtag-suggest --content="Your post content" [--platform=all] [--count=10]
```

**Parameters:**
- `--content` (required): Content text to analyze
- `--platform` (optional): Target platform (default: all)
  - `twitter`, `instagram`, `linkedin`, or `all`
- `--count` (optional): Number of suggestions (default: 10)

**Required Capability:** `edit_posts`

**Example:**
```bash
/hashtag-suggest --content="New WordPress plugin release!" --platform=twitter --count=15
```

#### `/social-analytics`
Get social media analytics and performance metrics.

**Usage:**
```bash
/social-analytics [--platform=all] [--period=week] [--metrics=engagement,reach]
```

**Parameters:**
- `--platform` (optional): Platform to analyze (default: all)
- `--period` (optional): Time period (default: week)
  - `today`, `week`, `month`
- `--metrics` (optional): Comma-separated metrics
  - `engagement`, `reach`, `clicks`, `conversions`

**Required Capability:** `edit_posts`

**Example:**
```bash
/social-analytics --platform=facebook --period=month --metrics=engagement,reach
```

### Video Production Toolkit

#### `/video-subtitle`
Generate or add subtitles to videos.

**Usage:**
```bash
/video-subtitle --video-id=123 [--language=en] [--auto-generate] [--style=default]
```

**Parameters:**
- `--video-id` (required): Video attachment ID
- `--language` (optional): Subtitle language code (default: en)
- `--auto-generate` (flag): Auto-generate from audio
- `--style` (optional): Subtitle style preset

**Required Capability:** `upload_files`

**Example:**
```bash
/video-subtitle --video-id=456 --language=es --auto-generate --style=modern
```

#### `/video-template`
Apply video templates and presets.

**Usage:**
```bash
/video-template --template=intro --input=123,456 [--output-name=my-video]
```

**Parameters:**
- `--template` (required): Template name or ID
- `--input` (required): Input video or images (comma-separated IDs)
- `--output-name` (optional): Output filename

**Required Capability:** `upload_files`

**Example:**
```bash
/video-template --template=product-intro --input=789,790,791 --output-name=promo-video
```

#### `/video-analytics`
Get video performance analytics.

**Usage:**
```bash
/video-analytics [--video-id=123] [--period=week] [--metrics=views,engagement]
```

**Parameters:**
- `--video-id` (optional): Specific video ID (default: all)
- `--period` (optional): Time period (default: week)
  - `today`, `week`, `month`
- `--metrics` (optional): Comma-separated metrics
  - `views`, `engagement`, `completion`

**Required Capability:** `upload_files`

**Example:**
```bash
/video-analytics --video-id=999 --period=month --metrics=views,completion
```

## New Workflows

### Abandoned Cart Recovery Campaign
**Slug:** `abandoned_cart_campaign`  
**Steps:** 3

Automated workflow to identify and recover abandoned carts.

**Workflow Steps:**
1. **Identify** - Find abandoned carts in the system
2. **Recover** - Send recovery emails to customers
3. **Analytics** - Track recovery rate and revenue

**Usage:**
```bash
/workflow abandoned_cart_campaign
```

### Multi-Platform Social Media Campaign
**Slug:** `social_media_campaign`  
**Steps:** 3

Create and publish content across all social platforms.

**Required Parameters:**
- `post_content`: The content to post

**Workflow Steps:**
1. **Generate Hashtags** - AI-powered hashtag suggestions
2. **Post Content** - Publish to Facebook, Twitter, Instagram, LinkedIn
3. **Track Analytics** - Monitor engagement metrics

**Usage:**
```bash
/workflow social_media_campaign post_content="Check out our new feature!"
```

### Video Marketing Production
**Slug:** `video_marketing_workflow`  
**Steps:** 3

Complete video creation and distribution workflow.

**Required Parameters:**
- `template_name`: Video template to use
- `video_assets`: Comma-separated asset IDs
- `video_description`: Description for social posts

**Workflow Steps:**
1. **Apply Template** - Create video from template
2. **Add Subtitles** - Auto-generate subtitles
3. **Distribute** - Post to YouTube, Facebook, Instagram

**Usage:**
```bash
/workflow video_marketing_workflow template_name="promo" video_assets="1,2,3" video_description="New product launch!"
```

### E-Commerce Upsell Optimization
**Slug:** `ecommerce_upsell_optimization`  
**Steps:** 2

Analyze and optimize product upsells and cross-sells.

**Workflow Steps:**
1. **Analyze Products** - Get top-performing products
2. **Generate Recommendations** - AI-powered upsell suggestions

**Usage:**
```bash
/workflow ecommerce_upsell_optimization
```

## Integration with Existing Tools

All commands integrate with existing pro toolkit tools:

### E-commerce Commands
- `upsell-suggest` → `WP_MCP_AI_Tool_Upsell_Recommendations`
- `abandoned-recover` → `WP_MCP_AI_Tool_Abandoned_Cart_Recovery`
- `ecom-analytics` → `WP_MCP_AI_Tool_Get_Order_Analytics`

### Social Media Commands
- `hashtag-suggest` → Custom AI implementation
- `social-analytics` → Custom analytics system

### Video Production Commands
- `video-subtitle` → Video processing system
- `video-template` → Template application engine
- `video-analytics` → Video metrics tracker

## Testing

### Run Command Tests
```bash
phpunit tests/test-slash-commands-pro-toolkit.php
```

**Test Coverage:**
- Command registration (9 commands)
- Command execution
- Parameter validation
- Capability requirements
- Documentation completeness

### Run Workflow Tests
```bash
phpunit tests/test-slash-commands-pro-workflows.php
```

**Test Coverage:**
- Workflow registration (4 workflows)
- Workflow structure
- Parameter placeholders
- Workflow step chaining
- Required field validation

## Requirements

### WordPress
- WordPress 6.0+
- PHP 7.4+

### Pro Toolkits
- **E-commerce Toolkit**: Requires WooCommerce plugin
- **Social Media Toolkit**: No additional requirements
- **Video Production Toolkit**: No additional requirements

### Capabilities
Users need appropriate capabilities to execute commands:
- E-commerce: `manage_woocommerce`
- Social Media: `edit_posts`
- Video Production: `upload_files`

## Troubleshooting

### Commands Not Available
**Problem:** Commands don't appear in autocomplete  
**Solution:**
1. Verify toolkit is enabled in settings
2. Check user has required capability
3. Clear browser cache
4. Check if WooCommerce is active (for ecommerce commands)

### Workflow Execution Fails
**Problem:** Workflow stops mid-execution  
**Solution:**
1. Check all required parameters are provided
2. Verify user has capability for ALL workflow steps
3. Check error logs for specific failure
4. Test individual commands first

### Tool Integration Issues
**Problem:** Command says "tool not available"  
**Solution:**
1. Verify pro addon is installed and active
2. Check toolkit is enabled in settings
3. Ensure required plugins are active (e.g., WooCommerce)
4. Check for PHP errors in error log

## Future Enhancements

### Planned Features
- [ ] Additional e-commerce commands (12 placeholders)
- [ ] Additional social media commands (10 placeholders)
- [ ] Additional video production commands (11 placeholders)
- [ ] More advanced workflows with conditionals
- [ ] Workflow templates builder UI
- [ ] Command chaining improvements

### Contributing
To add new commands:
1. Define command in appropriate `get_*_commands()` method
2. Implement handler method in toolkit manager
3. Add tests in test files
4. Update documentation

## Support

### Documentation
- **Slash Commands Guide**: `docs/slash-commands-guide.md`
- **Workflow Guide**: `docs/workflows.md`
- **Tool Reference**: `docs/tool-reference.md`

### Code Examples
- **Command Implementation**: See toolkit manager class
- **Workflow Definition**: See workflow orchestrator class
- **Test Examples**: See test files

## Changelog

### Version 1.3.0 (2026-02-04)
- ✅ Added 9 new command handlers
- ✅ Added 4 new automated workflows
- ✅ Created comprehensive test suite (25 tests)
- ✅ Enhanced command documentation
- ✅ Improved parameter validation
- ✅ Integrated with existing pro toolkit tools

---

**Last Updated:** February 4, 2026  
**Maintainer:** NV Digital Solutions  
**License:** GPLv3 or later
