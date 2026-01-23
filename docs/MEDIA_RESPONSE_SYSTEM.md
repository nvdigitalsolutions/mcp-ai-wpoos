# Media Response System - Comprehensive Documentation

## Overview

The Media Response System provides a standardized way for all media generation tools in the WordPress plugin to render their output with proper HTML elements, ensuring users can **immediately see, play, and interact** with newly created media directly in the chat interface.

## Architecture

### Core Principle: **Trait-Based Composition**

Each media type has a dedicated PHP trait that can be mixed into any tool class:

```php
class My_Tool implements WP_MCP_AI_Tool_Interface {
    use WP_MCP_AI_Tool_Image_Response;  // For images
    use WP_MCP_AI_Tool_Audio_Response;  // For audio
    use WP_MCP_AI_Tool_Video_Response;  // For video
    
    public function execute( $arguments, $context ) {
        // Generate media...
        $result = array(
            'attachment_id' => $id,
            'url' => $url,
            'text' => 'Media generated successfully'
        );
        
        // Add rendered HTML
        return $this->add_image_html_to_response( $result );
    }
}
```

---

## Available Traits

### 1. Image Response Trait
**File:** `includes/tools/trait-wp-mcp-ai-tool-image-response.php`

**Purpose:** Renders `<img>` tags for image attachments

**Methods:**
- `add_image_html_to_response( $result )` - Single image
- `add_multiple_images_html_to_response( $result )` - Multiple images (variations, edits)
- `generate_image_html( $id, $alt, $title )` - Low-level HTML generation
- `get_image_alt_text( $result )` - Smart alt text extraction

**Features:**
- ✅ `loading="lazy"` for performance
- ✅ Alt text with 125-character limit (WCAG compliant)
- ✅ Width/height attributes (prevent layout shift)
- ✅ Proper escaping (XSS prevention)
- ✅ CSS class `wp-mcp-ai-generated-image`

**Example Output:**
```html
Successfully generated image (ID: 123).

<img src="https://example.com/wp-content/uploads/2024/image.jpg" 
     alt="A beautiful sunset over mountains" 
     title="Generated Image" 
     width="1024" 
     height="768" 
     class="wp-mcp-ai-generated-image" 
     loading="lazy" />
```

**Applied To:**
- ✅ `generate-openai-image`
- ✅ `generate-gemini-image`
- ✅ `generate-cloudflareai-image`
- ✅ `create-image-variation`
- ✅ `edit-openai-image`
- ✅ `edit-gemini-image`

---

### 2. Audio Response Trait
**File:** `includes/tools/trait-wp-mcp-ai-tool-audio-response.php`

**Purpose:** Renders `<audio>` players for audio attachments

**Methods:**
- `add_audio_html_to_response( $result )` - Add audio player
- `generate_audio_html( $url, $title, $result )` - Low-level HTML generation

**Features:**
- ✅ Native `<audio controls>` player
- ✅ `preload="metadata"` for faster display
- ✅ MIME type detection
- ✅ Fallback download link
- ✅ Metadata display (voice, model, format)
- ✅ CSS class `wp-mcp-ai-generated-audio`

**Example Output:**
```html
Successfully generated speech audio using voice "alloy" in MP3 format.

<audio controls preload="metadata" class="wp-mcp-ai-generated-audio" title="Generated Speech">
<source src="https://example.com/audio.mp3" type="audio/mpeg">
<p>Your browser does not support the audio tag. <a href="...">Download audio</a></p>
</audio>
<p class="wp-mcp-ai-audio-metadata" style="font-size: 0.9em; color: #666;">Voice: alloy | Model: tts-1 | Format: MP3</p>
```

**Applied To:**
- ✅ `generate-openai-speech`
- ⏳ `generate-music` (pending)

---

### 3. Video Response Trait
**File:** `includes/tools/trait-wp-mcp-ai-tool-video-response.php`

**Purpose:** Renders `<video>` players for video attachments

**Methods:**
- `add_video_html_to_response( $result )` - Add video player
- `generate_video_html( $url, $title, $result )` - Low-level HTML generation

**Features:**
- ✅ Native `<video controls>` player
- ✅ `preload="metadata"` for faster display
- ✅ Poster image support
- ✅ Width/height attributes
- ✅ MIME type detection
- ✅ Fallback download link
- ✅ CSS class `wp-mcp-ai-generated-video`

**Example Output:**
```html
Successfully generated video (1920x1080, 30fps).

<video width="1920" height="1080" controls preload="metadata" 
       poster="https://example.com/thumbnail.jpg" 
       class="wp-mcp-ai-generated-video" 
       title="Generated Video">
<source src="https://example.com/video.mp4" type="video/mp4">
<p>Your browser does not support the video tag. <a href="...">Download video</a></p>
</video>
```

**Applied To:**
- ⏳ `generate-sora-video` (pending)
- ⏳ `generate-veo-video` (pending)

---

## Industry Standards Compliance

### Performance ✅
| Feature | Implementation | Benefit |
|---------|---------------|----------|
| Lazy Loading | `loading="lazy"` | Defers offscreen images |
| Dimensions | `width="X" height="Y"` | Prevents layout shift |
| Metadata Preload | `preload="metadata"` | Fast audio/video display |
| Minimal HTML | No srcset bloat | Smaller response size |

### Accessibility (WCAG 2.1 AA) ✅
| Requirement | Implementation | WCAG Criterion |
|-------------|---------------|----------------|
| Alt Text | Max 125 chars, contextual | 1.1.1 |
| Controls | Native `controls` attribute | 1.2.1, 2.1.1 |
| Keyboard Nav | Browser default controls | 2.1.1 |
| Fallback Text | Download links | 1.1.1 |
| No Autoplay | Controls-only playback | 1.4.2 |
| Title Attributes | Descriptive titles | 1.3.1 |

### Security ✅
| Protection | Implementation |
|------------|---------------|
| XSS Prevention | `esc_url()`, `esc_attr()`, `esc_html()` |
| MIME Type Validation | Sanitize and validate MIME types |
| URL Validation | WordPress `esc_url_raw()` |
| HTML Sanitization | All output escaped |

---

## Usage Patterns

### Pattern 1: Single Image Generation
```php
public function execute( $arguments, $context ) {
    // Generate image...
    $attachment_id = $this->create_image();
    
    $result = array(
        'attachment_id' => $attachment_id,
        'url'          => wp_get_attachment_url( $attachment_id ),
        'prompt'       => $arguments['prompt'], // Used for alt text
        'text'         => 'Image generated successfully',
    );
    
    // Automatically adds <img> tag to $result['message']
    return $this->add_image_html_to_response( $result );
}
```

### Pattern 2: Multiple Images (Variations/Edits)
```php
public function execute( $arguments, $context ) {
    $saved_images = array(); // Array of image data
    
    $result = array(
        'success' => true,
        'data' => array(
            'images' => $saved_images, // Each has attachment_id, url
            'text'   => 'Created 3 variations',
        ),
    );
    
    // Adds <img> tags for all images
    return $this->add_multiple_images_html_to_response( $result );
}
```

### Pattern 3: Audio/Video with Metadata
```php
public function execute( $arguments, $context ) {
    $result = array(
        'attachment_id' => $id,
        'url'          => $url,
        'voice'        => 'alloy',      // Displayed in metadata
        'model'        => 'tts-1',      // Displayed in metadata
        'format'       => 'mp3',        // Displayed in metadata
        'mime_type'    => 'audio/mpeg',
        'text'         => 'Audio generated',
    );
    
    // Adds <audio> player with metadata display
    return $this->add_audio_html_to_response( $result );
}
```

---

## NPM Package Integration

The system leverages existing NPM packages for enhanced functionality:

### Base Package
```json
{
  "chart.js": "^4.4.7",           // Chart rendering (already used)
  "@neplex/vectorizer": "^0.0.5", // SVG vectorization
  "marked": "^9.1.6",             // Markdown rendering
  "dompurify": "^3.3.0"           // HTML sanitization
}
```

### Pro Package
```json
{
  "pdfkit": "^0.17.2",     // PDF generation
  "docx": "^9.5.1",        // Word documents
  "exceljs": "^4.4.0",     // Excel spreadsheets
  "d3": "^7.8.5",          // Advanced visualizations
  "sharp": "^0.33.5",      // Image processing/thumbnails
  "fluent-ffmpeg": "^2.1.3", // Video processing
  "katex": "^0.16.11"      // Math rendering
}
```

---

## Testing

### Test File
`tests/test-image-response-trait.php`

**Test Coverage:**
- ✅ Single image HTML generation
- ✅ Multiple images HTML generation
- ✅ Missing attachment ID handling
- ✅ XSS prevention (proper escaping)
- ✅ Alt text truncation (125 chars)

**Run Tests:**
```bash
vendor/bin/phpunit tests/test-image-response-trait.php
```

---

## Future Enhancements

### Short Term
1. **Document Response Trait** (PDF, Word, Excel)
   - Thumbnail previews using sharp
   - Download buttons with file info
   - Sandboxed PDF iframe viewer

2. **Chart Accessibility Enhancements**
   - ARIA labels for Chart.js
   - Screen reader text alternatives
   - High contrast mode support

### Long Term
3. **Math Rendering Trait** (KaTeX)
   - LaTeX to HTML/SVG conversion
   - MathML fallback for accessibility

4. **Advanced Video Processing**
   - Thumbnail extraction (ffmpeg)
   - Subtitle embedding (subtitle package)
   - Web optimization

---

## Developer Guidelines

### Adding a New Media Tool

1. **Choose the appropriate trait** based on media type
2. **Require the trait file** in your tool class
3. **Use the trait** in your class declaration
4. **Call the helper method** before returning results

```php
<?php
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

class My_New_Image_Tool implements WP_MCP_AI_Tool_Interface {
    use WP_MCP_AI_Tool_Image_Response;
    
    public function execute( $arguments, $context ) {
        // Your tool logic...
        $result = array(/* ... */);
        
        // Add rendered media
        return $this->add_image_html_to_response( $result );
    }
}
```

### Best Practices

✅ **DO:**
- Always call the response helper before returning
- Include descriptive text in `$result['text']`
- Provide alt text sources (prompt, revised_prompt, title)
- Escape all user-provided content
- Test with various media sizes and formats

❌ **DON'T:**
- Return raw URLs without rendered HTML
- Skip alt text for images
- Use inline styles (use CSS classes)
- Auto-play audio/video
- Ignore accessibility requirements

---

## Maintenance

### Updating a Trait

When updating a trait, consider:
1. **Backward compatibility** - Don't break existing tools
2. **Accessibility** - Maintain WCAG compliance
3. **Performance** - Keep HTML minimal
4. **Security** - Always escape output
5. **Testing** - Update test cases

### Monitoring

Watch for:
- Browser compatibility issues
- Accessibility violations
- Performance regressions
- Security vulnerabilities
- User feedback on media display

---

## Support

**Questions?** See:
- `docs/QUICK_REFERENCE.md` - Fast reference guide
- `docs/tool-reference.md` - Complete tool documentation
- `CONTRIBUTING.md` - Contribution guidelines

**Issues?** Check:
- `docs/deployment-troubleshooting.md` - Common issues
- GitHub Issues - Report bugs

---

## License

GPL-3.0-or-later - See LICENSE file

---

**Last Updated:** 2026-01-22  
**Version:** 1.2.0  
**Maintainer:** NV Digital Solutions
