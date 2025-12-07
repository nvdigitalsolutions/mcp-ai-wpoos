# AI-Powered Media Library and Comments Moderation Tools

**Quick Reference Guide** | Last Updated: 2024-11-13 | Version: 1.1

This document provides comprehensive guidance on using the three new AI-powered tools for media enhancement and comment moderation that were added in PR #1080.

## 📋 Table of Contents

1. [Quick Start](#quick-start)
2. [Tool Overview](#tool-overview)
3. [Detailed Tool Reference](#detailed-tool-reference)
4. [WordPress Integration](#wordpress-integration)
5. [AI Assistant Usage Examples](#ai-assistant-usage-examples)
6. [Cost & Performance](#cost--performance)
7. [Troubleshooting](#troubleshooting)
8. [Best Practices](#best-practices)

---

## Quick Start

### Enable in WordPress Admin
1. Go to **Settings → WP oOS → Tools & Features**
2. Enable **AI Media Library** for automatic image processing
3. Enable **AI Comments Moderation** for automatic comment analysis
4. Configure your OpenAI or Gemini API key in **Settings → WP oOS → Providers**

### Use via AI Assistant
Ask the assistant to use these tools directly:
- "Generate alt text for attachment ID 123"
- "Create a caption for this image URL"
- "Analyze this comment for spam"

---

## Tool Overview

| Tool | Purpose | Primary Use Case | Avg Cost |
|------|---------|------------------|----------|
| `generate_image_alt_text` | Accessibility alt text | SEO & accessibility compliance | ~$0.00015 |
| `generate_image_caption` | Engaging descriptions | Content & social media | ~$0.00023 |
| `analyze_comment_content` | Spam & toxicity detection | Comment moderation | ~$0.00038 |

**All tools support**: OpenAI GPT-4o-mini, Gemini 1.5-flash

---

## Detailed Tool Reference

### 1. `generate_image_alt_text`

**Purpose**: Generate accessibility-focused alt text for images using AI vision capabilities.

**Usage Example**:
```json
{
  "tool": "generate_image_alt_text",
  "arguments": {
    "attachment_id": 123,
    "context": "Product photo for WordPress development course"
  }
}
```

**Parameters**:
- `image_url` (optional): URL of the image to analyze
- `image_content` (optional): Base64-encoded image content
- `attachment_id` (optional): WordPress attachment ID
- `context` (optional): Additional context to help generate relevant alt text

**Returns**:
```json
{
  "alt_text": "Laptop displaying WordPress code editor with PHP syntax highlighting",
  "success": true
}
```

**Requirements**:
- User must have `upload_files` capability
- Requires OpenAI GPT-4o-mini or Gemini 1.5-flash configured
- Consumes AI tokens (approximately 100 tokens per request)

---

### 2. `generate_image_caption`

**Purpose**: Generate detailed, engaging captions for images suitable for content and social media.

**Usage Example**:
```json
{
  "tool": "generate_image_caption",
  "arguments": {
    "attachment_id": 456,
    "context": "Blog post about remote work"
  }
}
```

**Parameters**:
- `image_url` (optional): URL of the image to analyze
- `image_content` (optional): Base64-encoded image content
- `attachment_id` (optional): WordPress attachment ID
- `context` (optional): Additional context for more relevant captions

**Returns**:
```json
{
  "caption": "A professional workspace featuring a modern laptop, wireless keyboard, and coffee cup on a clean white desk, perfect for productive remote work sessions.",
  "success": true
}
```

**Requirements**:
- User must have `upload_files` capability
- Requires OpenAI GPT-4o-mini or Gemini 1.5-flash configured
- Consumes AI tokens (approximately 150 tokens per request)

---

### 3. `analyze_comment_content`

**Purpose**: Analyze comment content for spam, toxicity, and moderation concerns using AI.

**Usage Example**:
```json
{
  "tool": "analyze_comment_content",
  "arguments": {
    "comment_content": "Great article! Very helpful information.",
    "comment_author": "John Doe",
    "comment_email": "john@example.com",
    "sensitivity": "medium"
  }
}
```

**Parameters**:
- `comment_content` (required): The comment text to analyze
- `comment_author` (optional): Name of the comment author
- `comment_email` (optional): Email address of the author
- `comment_url` (optional): URL provided by the author
- `user_ip` (optional): IP address of the commenter
- `sensitivity` (optional): Moderation sensitivity level - `low`, `medium` (default), or `high`

**Returns**:
```json
{
  "is_spam": false,
  "is_toxic": false,
  "toxicity_level": "none",
  "spam_indicators": [],
  "recommended_action": "approved",
  "confidence": 0.95,
  "reason": "Legitimate comment providing positive feedback with no spam or toxic indicators",
  "sensitivity_applied": "medium"
}
```

**Requirements**:
- User must have `moderate_comments` capability for manual use
- Requires OpenAI GPT-4o-mini or Gemini 1.5-flash configured
- Consumes AI tokens (approximately 250 tokens per request)

**Sensitivity Levels**:
- `low`: Permissive - only flags obvious violations
- `medium`: Balanced - flags clear issues (recommended)
- `high`: Strict - flags anything questionable

---

## Automatic Features

These tools are also used automatically by WordPress integrations when enabled in settings:

### Media Library Integration
When enabled at **Settings → WP oOS → Tools & Features → AI Media Library**:
- Automatically generates alt text for newly uploaded images
- Automatically generates captions for newly uploaded images
- Respects overwrite settings for existing metadata

### Comments Moderation Integration
When enabled at **Settings → WP oOS → Tools & Features → AI Comments Moderation**:
- Automatically analyzes incoming comments before publication
- Applies recommendations based on confidence threshold
- Stores analysis in comment meta for moderator review
- Moderators are exempt from automatic analysis

---

## Cost & Performance

### Token Usage & Pricing

| Tool | Tokens | OpenAI Cost | Gemini Cost | Response Time |
|------|--------|-------------|-------------|---------------|
| generate_image_alt_text | ~100 | ~$0.00015 | ~$0.000075 | 2-4 seconds |
| generate_image_caption | ~150 | ~$0.00023 | ~$0.000113 | 2-5 seconds |
| analyze_comment_content | ~250 | ~$0.00038 | ~$0.000188 | 1-3 seconds |

**Cost Optimization Tips**:
- Use Gemini for ~50% cost savings
- Batch process images during off-peak hours
- Set appropriate confidence thresholds for comments
- Enable only needed features in WordPress settings

### Performance Considerations

**Image Processing**:
- ✅ Best for: Product photos, blog images, media galleries
- ⚠️ Consider: Large volumes may need rate limiting
- 💡 Tip: Process in background for better UX

**Comment Analysis**:
- ✅ Best for: Public-facing comment forms
- ⚠️ Consider: High-traffic sites should monitor costs
- 💡 Tip: Use sensitivity settings to reduce false positives

---

## Tool Groups & Categories

All three tools are in the **`wordpress-core`** group:

**What this means**:
- ✅ Work with base WordPress (no plugin dependencies)
- ✅ Available in both base and full plugin versions
- ✅ Only require AI provider configuration
- ✅ Can be used immediately after API key setup

**Tool Categories**:
- **Media Tools**: `generate_image_alt_text`, `generate_image_caption`
- **Content Moderation**: `analyze_comment_content`

---

## AI Assistant Usage Examples

### Example 1: Batch Image Processing
**User**: "Process all images uploaded this week and add alt text"

**Assistant**: "I'll retrieve the recent uploads and generate alt text for each image."

```json
// Step 1: Search for recent images
{
  "tool": "search_attachments",
  "arguments": {
    "post_type": "attachment",
    "post_mime_type": "image",
    "date_query": {"after": "1 week ago"}
  }
}

// Step 2: For each image
{
  "tool": "generate_image_alt_text",
  "arguments": {
    "attachment_id": 789
  }
}
```

---

### Example 2: Social Media Caption Generation
**User**: "Create an engaging caption for my product image"

**Assistant**: "I'll generate a caption suitable for social media."

```json
{
  "tool": "generate_image_caption",
  "arguments": {
    "image_url": "https://example.com/product.jpg",
    "context": "New eco-friendly water bottle product launch"
  }
}
```

**Result**: "Introducing our latest sustainable innovation: a sleek, eco-friendly water bottle designed for the modern adventurer. Stay hydrated while protecting the planet! 🌍💧"

---

### Example 3: Comment Moderation Workflow
**User**: "Check if this comment is spam: 'Amazing post! Visit my site for SEO tips at example.com'"

**Assistant**: "I'll analyze this comment for spam indicators."

```json
{
  "tool": "analyze_comment_content",
  "arguments": {
    "comment_content": "Amazing post! Visit my site for SEO tips at example.com",
    "sensitivity": "high"
  }
}
```

**Analysis Result**:
```json
{
  "is_spam": true,
  "is_toxic": false,
  "toxicity_level": "none",
  "spam_indicators": ["promotional_link", "generic_praise", "call_to_action"],
  "recommended_action": "spam",
  "confidence": 0.92,
  "reason": "Generic positive comment combined with promotional URL indicates spam"
}
```

---

### Example 4: Accessibility Audit
**User**: "Audit all images without alt text in my last 5 posts"

**Assistant**: "I'll find posts and check for missing alt text."

```json
// Step 1: Get recent posts
{
  "tool": "get_recent_posts",
  "arguments": {"posts_per_page": 5}
}

// Step 2: Search for images in content
{
  "tool": "search_content",
  "arguments": {"search_term": "<img"}
}

// Step 3: Generate alt text for images without it
{
  "tool": "generate_image_alt_text",
  "arguments": {"attachment_id": 123}
}
```

---

## Best Practices

### For Image Alt Text

✅ **Do**:
- Use context parameter for better relevance
- Review AI-generated text before publishing
- Generate for all new uploads automatically
- Keep alt text under 125 characters

❌ **Don't**:
- Rely solely on AI without human review
- Generate alt text for purely decorative images
- Overwrite manually-crafted alt text
- Ignore accessibility guidelines

### For Image Captions

✅ **Do**:
- Provide context about the image's purpose
- Use for blog posts and social media
- Customize tone based on audience
- Edit for brand voice consistency

❌ **Don't**:
- Use generic descriptions
- Forget to fact-check AI output
- Ignore copyright/attribution needs
- Skip human review for public content

### For Comment Analysis

✅ **Do**:
- Start with medium sensitivity
- Adjust confidence threshold based on results
- Review held comments regularly
- Exempt trusted users/moderators
- Monitor false positive rates

❌ **Don't**:
- Use highest sensitivity immediately
- Auto-delete without review
- Ignore low-confidence flags
- Process moderator comments
- Forget to communicate policy to users

---
  "arguments": {
    "comment_content": "Check out my website for great deals on products!",
    "sensitivity": "medium"
  }
}
```

**Result**: 
- Spam: Yes
- Confidence: 0.87
- Recommended Action: spam
- Reason: Promotional content with call to action

---

## Troubleshooting

### Common Issues & Solutions

#### ❌ "Tool not found" Error

**Symptoms**: `Tool "generate_image_alt_text" not found`

**Solutions**:
1. ✅ Verify plugin is updated to include PR #1080 changes
2. ✅ Check tool registry initialization: `WP_MCP_AI_Tool_Registry::get_instance()->init()`
3. ✅ Verify tool name spelling (use underscores: `generate_image_alt_text`, not `generate-image-alt-text`)
4. ✅ Clear object cache if using persistent caching
5. ✅ Deactivate/reactivate plugin to force re-registration

**Debug Check**:
```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$registry->init();
var_dump($registry->is_tool_registered('generate_image_alt_text'));
// Should return: bool(true)
```

---

#### ❌ "API key not configured" Error

**Symptoms**: `OpenAI/Gemini API key is not configured`

**Solutions**:
1. ✅ Navigate to **Settings → WP oOS → Providers**
2. ✅ Enter your API key for OpenAI or Gemini
3. ✅ Click "Test Connection" to verify
4. ✅ Ensure no extra spaces in API key
5. ✅ Check API key has vision model access (for image tools)

**Get API Keys**:
- **OpenAI**: https://platform.openai.com/api-keys
- **Gemini**: https://makersuite.google.com/app/apikey

---

#### ❌ Permission Denied Errors

**Symptoms**: `You do not have permission to generate image alt text`

**Solutions**:
- **Image Tools** (`upload_files` required):
  - ✅ User must be Editor or Administrator
  - ✅ Or have custom role with `upload_files` capability
  
- **Comment Tool** (`moderate_comments` required):
  - ✅ User must be Editor, Administrator, or Moderator
  - ✅ Or have custom role with `moderate_comments` capability

**Check Capabilities**:
```php
current_user_can('upload_files');        // For image tools
current_user_can('moderate_comments');   // For comment tool
```

---

#### ❌ Image Processing Fails

**Symptoms**: Alt text or caption generation returns error

**Common Causes**:
1. **Invalid Image Format**
   - ✅ Ensure image is JPEG, PNG, WebP, or GIF
   - ✅ Check file isn't corrupted
   
2. **Image Too Large**
   - ✅ Resize images over 20MB before processing
   - ✅ Use WordPress image sizes when possible
   
3. **Network Timeout**
   - ✅ Check server firewall allows API connections
   - ✅ Increase PHP max_execution_time if needed
   - ✅ Verify internet connectivity

4. **API Rate Limits**
   - ✅ Implement delays between batch requests
   - ✅ Check API quota/billing status
   - ✅ Consider upgrading API tier for high volume

---

#### ❌ Comment Analysis Not Working

**Symptoms**: Comments bypass AI analysis

**Check These Settings**:
1. ✅ **Settings → WP oOS → Tools & Features → AI Comments Moderation**
   - Feature must be enabled
   
2. ✅ **User is not a moderator**
   - Moderators are intentionally exempt
   
3. ✅ **Comment isn't already spam**
   - Already-flagged spam is skipped
   
4. ✅ **Sensitivity and confidence settings**
   - Too high = nothing flagged
   - Too low = everything flagged

**Recommended Starting Settings**:
- Sensitivity: `medium`
- Confidence: `70%`
- Auto-hold low confidence: `enabled`

---

#### ❌ High API Costs

**Symptoms**: Unexpected API bills

**Cost Reduction Strategies**:
1. ✅ **Switch to Gemini** (50% cheaper)
2. ✅ **Disable for existing content** (only new uploads)
3. ✅ **Adjust comment sensitivity** (reduce false positives)
4. ✅ **Batch process during off-peak**
5. ✅ **Set daily limits** in API dashboard
6. ✅ **Monitor usage** via API provider console

**Cost Monitoring**:
```php
// Check OpenAI usage
$tool = $registry->get_tool('open_openai_usage');
$usage = $tool->execute(['period' => '30d']);
```

---

#### ❌ Poor Quality Results

**Symptoms**: Alt text too generic, captions not relevant

**Improvement Tips**:
1. ✅ **Add Context**: Use `context` parameter
   ```json
   {
     "context": "E-commerce product photo - vintage leather wallet"
   }
   ```

2. ✅ **Review & Edit**: Always review AI output
3. ✅ **Provide Examples**: Include in context what style you want
4. ✅ **Use Better Images**: Higher quality = better analysis
5. ✅ **Try Different Providers**: Test both OpenAI and Gemini

---

### Performance Optimization

#### For High-Volume Sites

**Image Processing**:
```php
// Process in background using WP Cron
add_action('wp_async_process_image_alt_text', function($attachment_id) {
    $tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool('generate_image_alt_text');
    $result = $tool->execute(['attachment_id' => $attachment_id]);
    // Update post meta
});
```

**Comment Moderation**:
- Use higher confidence thresholds during traffic spikes
- Implement rate limiting per IP
- Cache analysis results for similar content

#### Caching Strategies

```php
// Cache alt text for 30 days
$cache_key = 'alt_text_' . $attachment_id;
$alt_text = get_transient($cache_key);

if (false === $alt_text) {
    $result = $tool->execute(['attachment_id' => $attachment_id]);
    $alt_text = $result['alt_text'];
    set_transient($cache_key, $alt_text, 30 * DAY_IN_SECONDS);
}
```

---

## Advanced Usage

### Custom Integration Example

```php
// Hook into your custom upload process
add_action('my_custom_upload', function($attachment_id) {
    // Check if AI media is enabled
    $settings = get_option('wp_mcp_ai_settings', []);
    if (empty($settings['enable_ai_media_library'])) {
        return;
    }
    
    // Get the tool
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $tool = $registry->get_tool('generate_image_alt_text');
    
    // Execute with context
    $result = $tool->execute([
        'attachment_id' => $attachment_id,
        'context' => 'User profile picture'
    ], [
        'user_id' => get_current_user_id()
    ]);
    
    // Handle result
    if (!is_wp_error($result) && isset($result['alt_text'])) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $result['alt_text']);
        
        // Log for audit
        error_log(sprintf(
            'AI generated alt text for attachment %d: %s',
            $attachment_id,
            $result['alt_text']
        ));
    }
});
```

### REST API Usage

```bash
# Generate alt text via REST API
curl -X POST 'https://example.com/wp-json/mcp-ai/v1/tools' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "tool": "generate_image_alt_text",
    "arguments": {
      "attachment_id": 123
    }
  }'
```

### WP-CLI Integration

```bash
# Generate alt text for all images without alt text
wp eval '
$attachments = get_posts([
    "post_type" => "attachment",
    "post_mime_type" => "image",
    "posts_per_page" => -1,
    "meta_query" => [[
        "key" => "_wp_attachment_image_alt",
        "compare" => "NOT EXISTS"
    ]]
]);

$registry = WP_MCP_AI_Tool_Registry::get_instance();
$tool = $registry->get_tool("generate_image_alt_text");

foreach ($attachments as $attachment) {
    $result = $tool->execute([
        "attachment_id" => $attachment->ID
    ]);
    
    if (!is_wp_error($result)) {
        echo "Generated alt text for: {$attachment->post_title}\n";
    }
}
'
```

---

## Monitoring & Analytics

### Track Usage

```php
// Log all AI tool usage
add_filter('wp_mcp_ai_tool_executed', function($result, $tool_slug, $arguments) {
    if (in_array($tool_slug, ['generate_image_alt_text', 'generate_image_caption', 'analyze_comment_content'])) {
        // Log to your analytics
        do_action('my_analytics_track', [
            'event' => 'ai_tool_used',
            'tool' => $tool_slug,
            'success' => !is_wp_error($result),
            'cost' => $result['estimated_cost'] ?? 0
        ]);
    }
    return $result;
}, 10, 3);
```

### Performance Metrics

```php
// Track processing time
$start = microtime(true);
$result = $tool->execute($arguments);
$duration = microtime(true) - $start;

update_option('ai_tool_metrics', [
    'avg_processing_time' => $duration,
    'total_processed' => get_option('ai_tool_metrics')['total_processed'] + 1
]);
```

---

## Related Documentation

### Plugin Documentation
- 📄 `PHASE_1_IMPLEMENTATION_SUMMARY.md` - Detailed implementation notes
- 📄 `ISSUE_1080_RESOLUTION.md` - Tool registration resolution
- 📄 `docs/tool-reference.md` - Complete tool documentation
- 📄 `docs/rest-api.md` - REST API reference
- 📄 `docs/tool-grouping.md` - Tool organization

### External Resources
- 🔗 [OpenAI Vision API Docs](https://platform.openai.com/docs/guides/vision)
- 🔗 [Gemini Vision API Docs](https://ai.google.dev/docs/vision)
- 🔗 [WordPress Accessibility Guidelines](https://make.wordpress.org/accessibility/handbook/)
- 🔗 [WCAG Alt Text Guidelines](https://www.w3.org/WAI/WCAG21/Understanding/non-text-content.html)

### Support
- 🐛 [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- 💬 [Community Support](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/discussions)
- 📧 Contact: support@nvdigitalsolutions.com

---

**Last Updated**: 2024-11-13 | **Version**: 1.1 | **Status**: ✅ Production Ready
