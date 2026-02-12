# AI Quick Actions Widget - User Guide

## Overview

The **AI Quick Actions Widget** provides instant access to all 519 AI tools available in the NV oOS plugin through a simple, intuitive interface. Users can perform complex AI operations with just a few clicks, following industry-standard UX patterns for AI interfaces.

## Installation & Setup

### Requirements
- WordPress 6.0 or higher
- Elementor plugin installed and activated
- NV oOS plugin version 1.1.1 or higher
- Appropriate user capabilities for desired tools

### Adding the Widget

1. **In Elementor Editor:**
   - Open a page in Elementor
   - Search for "AI Quick Actions" in the widget panel
   - Drag the widget onto your page
   - Configure settings in the left panel

2. **Widget Settings:**
   - **Widget Title**: Customize the heading
   - **Description**: Add instructions for users
   - **Default Category**: Choose which category shows first
   - **Layout**: Grid or List view
   - **Columns**: Number of columns (Grid only, 1-6)
   - **Show Icons**: Display category emoji icons
   - **Show Tool Descriptions**: Display brief tool descriptions
   - **Enable File Upload**: Allow file uploads
   - **Enable Media Library**: Allow selecting from media library

## Usage Guide

### Basic Workflow

```
1. Select Category → 2. Choose Tool → 3. Upload File (if needed) → 4. Execute → 5. Review Result → 6. Apply/Regenerate
```

### Step-by-Step Example: Generate Image Alt Text

**Step 1: Select Category**
- Open the category dropdown
- Select "🖼️ Image Tools"

**Step 2: Upload or Select Image**
- Click "Upload File" to upload from computer
- OR click "Select from Media Library" to choose existing image
- Image preview will appear

**Step 3: Choose Tool**
- Click "Generate Alt Text" button
- Tool begins executing automatically

**Step 4: Review Progress**
- Progress indicator shows "Analyzing image..."
- Wait for processing to complete

**Step 5: Preview Result**
- Result preview box appears
- Review the generated alt text
- Check quality and accuracy

**Step 6: Take Action**
- **Apply**: Save the alt text to the image
- **Regenerate**: Try again if not satisfied
- **Cancel**: Discard the result

### Tool Categories

#### 🖼️ Image Tools (30+ actions)
**Generation:**
- Generate Image from Prompt (OpenAI DALL-E)
- Generate Image from Prompt (Google Gemini)
- Generate Image from Prompt (Cloudflare AI)
- Create Image Variation

**Enhancement & Editing:**
- Remove Background
- Edit Image (OpenAI)
- Edit Image with Prompt (Gemini)
- Crop/Rotate/Resize Image
- Convert Image Format
- Optimize for Web

**Analysis:**
- Analyze Image Content
- Extract Text from Image (OCR)
- Detect Objects in Image
- Visual Product Search
- Generate Alt Text
- Generate Caption

**Batch Operations:**
- Batch Convert Image Formats
- Batch Generate Alt Text
- Batch Optimize Images

#### 🎬 Video Tools (5+ actions)
- Generate Video from Prompt (OpenAI Sora)
- Generate Video from Prompt (Google Veo)
- Analyze Video Content
- Generate Video Captions
- Check Video Processing Status

#### 🎵 Audio & Music (4+ actions)
- Generate Speech from Text (OpenAI TTS)
- Generate Music from Description
- Transcribe Audio to Text
- Analyze Audio Content

#### 📝 Content Creation (25+ actions)
- Generate Blog Post
- Generate Post Excerpt
- Generate Product Description
- Auto-Categorize Content
- Optimize SEO Meta Tags
- Generate Internal Link Suggestions
- Moderate Content for Safety
- Summarize Text
- Translate Text

#### 🔍 SEO Tools (8+ actions)
- Optimize Meta Description
- Optimize Meta Title
- Generate Schema Markup
- Analyze Page SEO (Rank Math)
- Suggest Internal Links
- Check Page Speed
- Get Search Console Data

#### 🛍️ WooCommerce (12+ actions)
- Create Product from Description
- Scrape Product Data from URL
- Generate Product Description
- Optimize Product Images
- Get Recent Orders Summary
- Analyze Order Trends

#### 📧 Email & Newsletter (8+ actions)
- Send Group Email
- Create Newsletter
- Add Newsletter Subscriber
- Get Subscriber Stats
- Design Email Template

#### 🔐 Security (6+ actions)
- Check Site Security Status
- Setup 2FA for User
- Analyze Password Strength
- Monitor Login Activity
- Audit User Activity

#### 📊 Analytics & Charts (8+ actions)
- Generate Chart from Data
- Create Mermaid Diagram
- Visualize Workflow Metrics
- Get OpenAI Usage Analytics
- Generate Performance Report

#### 🤖 AI & Models (15+ actions)
- Suggest Best Model for Task
- Discover New Models
- Research Model Capabilities
- Count Tokens in Text
- Create Text Embeddings
- Create Vector Store

#### 🔗 Workflow & Automation (10+ actions)
- Execute Workflow
- Validate Workflow
- Create Cron Job
- Schedule Batch Job
- Monitor Batch Status

#### 🌐 Web & External Data (12+ actions)
- Web Search with AI
- Scrape Website Data (Crawl4AI)
- Geocode Address
- Search Google Places
- Get Weather Forecast
- Search Gmail/Google Drive

#### 🗂️ Page Builders (6+ actions)
- Import Elementor Template
- Generate Elementor Pattern
- Create Gutenberg Block Pattern
- Get JetEngine Items

#### 🔧 Utilities (10+ actions)
- Purge All Caches
- Optimize Media Library
- Export Data to Excel
- Generate QR Code
- Convert File Format

## Advanced Features

### File Upload Options

**Supported File Types:**
- Images: JPG, PNG, GIF, WebP, SVG
- Audio: MP3, WAV
- Video: MP4, WebM
- Documents: PDF

**File Size Limits:**
- Default: 10MB per file
- Can be adjusted via filter: `wp_mcp_ai_quick_action_max_file_size`

### Keyboard Shortcuts

- **Tab**: Navigate between controls
- **Enter**: Execute selected tool (when focused)
- **Escape**: Cancel result preview

### Customization

#### Via Elementor Settings

**Style Tab:**
- Button Colors (normal/hover)
- Button Typography
- Border Radius
- Padding and Spacing

**Advanced Tab:**
- Custom CSS classes
- Tool filters
- Capability requirements

#### Via WordPress Filters

```php
// Modify max file size (in bytes)
add_filter( 'wp_mcp_ai_quick_action_max_file_size', function() {
    return 20971520; // 20MB
});

// Customize tool categories
add_filter( 'wp_mcp_ai_quick_action_categories', function( $categories ) {
    // Add or modify categories
    return $categories;
});
```

## Best Practices

### 1. Category Organization
- Use default category to highlight most-used tools
- Enable only relevant categories for your use case
- Consider your audience's technical level

### 2. File Management
- Compress images before upload for faster processing
- Use media library for frequently used assets
- Clear preview after applying results

### 3. Result Handling
- Always review results before applying
- Use regenerate if first attempt isn't perfect
- Test with sample content first

### 4. Performance
- Avoid executing multiple tools simultaneously
- Use batch operations for bulk processing
- Cache results when possible

### 5. Security
- Verify user capabilities for sensitive tools
- Sanitize user inputs before processing
- Review AI outputs for accuracy

## Troubleshooting

### Tool Not Executing
**Possible Causes:**
- Missing required capabilities
- File upload failed or wrong format
- Tool requires additional configuration
- API keys not configured

**Solutions:**
- Check user role and permissions
- Verify file type and size
- Review tool requirements
- Configure API keys in plugin settings

### File Upload Failed
**Possible Causes:**
- File too large
- Unsupported file type
- Upload directory permissions
- PHP upload limits

**Solutions:**
- Reduce file size
- Convert to supported format
- Check directory permissions (wp-content/uploads)
- Increase PHP upload_max_filesize

### Result Not Displaying
**Possible Causes:**
- Tool returned error
- Network timeout
- Invalid response format
- JavaScript error

**Solutions:**
- Check browser console for errors
- Review tool execution logs
- Test with simpler input
- Clear browser cache

### Slow Performance
**Possible Causes:**
- Large file processing
- Complex AI operation
- Server resource limits
- API rate limits

**Solutions:**
- Optimize file size
- Use simpler tools first
- Increase server resources
- Check API quota usage

## Frequently Asked Questions

### Q: Can I use this widget without Elementor?
A: Currently, the widget requires Elementor. We plan to add a shortcode version in a future update.

### Q: Are all 519 tools available?
A: Yes, if you have the Full Version enabled. Base Version includes 165 core tools.

### Q: Can I customize which tools appear?
A: Yes, you can filter tools by category in the widget settings or use WordPress filters in your theme.

### Q: Do I need API keys for all tools?
A: Only tools that use external services (OpenAI, Google, etc.) require API keys. Many tools work without any API configuration.

### Q: Can guests use this widget?
A: By default, only logged-in users can use the widget. You can customize this via WordPress capabilities.

### Q: How do I add custom tools?
A: Use the `wp_mcp_ai_register_tools` action hook to register custom tools. See developer documentation for details.

### Q: Is there a limit on tool executions?
A: Limits depend on your API quotas and server resources. You can add rate limiting via plugin filters.

### Q: Can I use this on multiple pages?
A: Yes, add the widget to any Elementor page. Each instance can have different settings.

## Security Considerations

### User Capabilities
- Tools respect WordPress capability checks
- Sensitive operations require appropriate permissions
- Capability checks cannot be bypassed

### File Upload Security
- MIME type validation
- File extension whitelist
- Size limits enforced
- Malware scanning (if available)

### Data Privacy
- User inputs are not logged by default
- API requests follow provider privacy policies
- Results are temporary unless explicitly saved

### Rate Limiting
- Prevents abuse of AI tools
- Configurable limits per user/role
- Automatic throttling for high usage

## Support & Resources

### Documentation
- Full plugin documentation: `/docs/`
- Tool reference: `/docs/tool-reference.md`
- API documentation: `/docs/rest-api.md`

### Getting Help
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Support Forum: WordPress.org
- Email: support@nvdigitalsolutions.com

### Contributing
- Report bugs via GitHub Issues
- Submit feature requests
- Contribute code via Pull Requests
- Improve documentation

## Changelog

### Version 1.1.1
- Initial release of AI Quick Actions Widget
- 14 tool categories
- Support for all 519 tools (base + pro)
- File upload and media library integration
- Result preview and regenerate functionality
- Responsive design with dark mode support

## Credits

### Design Inspiration
- Microsoft Copilot UX patterns
- Google Gemini interface patterns
- OpenAI ChatGPT UI best practices
- AIUX Design Guide
- Shape of AI Pattern Library

### Development
- Built by NV Digital Solutions
- Based on Open Operator System (oOS) framework
- Licensed under GPLv3

---

**Last Updated:** February 12, 2026
**Version:** 1.1.1
**Author:** NV Digital Solutions
