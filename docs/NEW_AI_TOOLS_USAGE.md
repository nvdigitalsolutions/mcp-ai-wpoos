# Using the New AI-Powered Media and Comments Tools

This document explains how to use the three new AI-powered tools that were added in PR #1080 and registered for use by the AI assistant.

## Available Tools

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

## Cost Estimates

| Tool | OpenAI Cost | Gemini Cost |
|------|-------------|-------------|
| generate_image_alt_text | ~$0.00015 | ~$0.000075 |
| generate_image_caption | ~$0.00023 | ~$0.000113 |
| analyze_comment_content | ~$0.00038 | ~$0.000188 |

*Costs are approximate and based on current API pricing.*

---

## Tool Groups

All three tools are registered in the `wordpress-core` tool group, meaning they:
- Work with base WordPress functionality
- Don't require third-party plugins
- Are available in both base and full versions
- Require only AI provider configuration (OpenAI or Gemini)

---

## Example AI Assistant Conversations

### Example 1: Generate Alt Text for Images
**User**: "Can you generate alt text for the image with ID 789?"

**Assistant**: "I'll generate alt text for that image."
```json
{
  "tool": "generate_image_alt_text",
  "arguments": {
    "attachment_id": 789
  }
}
```

**Result**: Alt text generated successfully - "Modern office space with collaborative workspace and natural lighting"

---

### Example 2: Analyze a Comment
**User**: "Please analyze this comment for spam: 'Check out my website for great deals on products!'"

**Assistant**: "I'll analyze that comment for spam and toxicity."
```json
{
  "tool": "analyze_comment_content",
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

### Tool Not Found
If you get a "Tool not found" error, ensure:
1. The plugin is up to date (version with PR #1080 changes)
2. The tool registry has been initialized
3. The tool name is spelled correctly (use underscores, not hyphens)

### Missing API Key
If you get "API key not configured" errors:
1. Go to **Settings → WP oOS → Providers**
2. Configure your OpenAI or Gemini API key
3. Test the connection

### Permission Denied
If you get permission errors:
- Vision tools require `upload_files` capability
- Comment analysis requires `moderate_comments` capability
- Make sure the user has the appropriate WordPress role

---

## Related Documentation

- PR #1080: Original implementation of tools and integrations
- `docs/tool-reference.md`: Complete tool documentation
- `docs/rest-api.md`: REST API for tool execution
- `PHASE_1_IMPLEMENTATION_SUMMARY.md`: Detailed implementation notes
