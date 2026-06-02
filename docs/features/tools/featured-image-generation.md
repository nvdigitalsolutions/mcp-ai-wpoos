# Featured Image Generation for Research Pages

## Overview

All Research & Add pages in NV oOS now support automatic featured image generation using AI. When creating content from research, users can optionally generate a professional featured image to accompany their post, page, product, quiz, place, ECA, or policy.

## Features

### Supported Research Pages

1. **Post Research Page** - Creates blog posts with featured images
2. **Page Research Page** - Creates pages with featured images
3. **Product Research Page** - Creates WooCommerce products with featured images
4. **Quiz Research Page** - Creates quizzes with featured images
5. **Place Research Page** - Creates places/locations with featured images
6. **ECA Research Page** - Creates extra-curricular activities with featured images
7. **Policy Research Page** - Creates insurance policies with featured images

### AI Providers

The system attempts to generate images using the following providers in order:

1. **OpenAI DALL-E** (Primary) - Uses `gpt-image-1.5` model at 1792x1024 resolution
2. **Google Gemini** (Fallback) - Uses Imagen with 16:9 aspect ratio
3. **Cloudflare AI** (Tertiary) - Uses Cloudflare's image generation

If one provider fails or is not configured, the system automatically tries the next one.

## User Interface

### Dialog Options

When clicking "Create [Type] from Research", a modal dialog appears with:

1. **Generate Featured Image** checkbox
   - When checked, shows additional options
   - When unchecked, creates content without featured image

2. **Image Description** input field (optional)
   - Allows users to provide a custom image prompt
   - If left empty, automatically generates a contextual prompt
   - Example: "A professional featured image for a blog post about: [Post Title]"

### Automatic Prompts

If the user doesn't provide a custom image prompt, the system generates one based on:

- **Posts**: "A professional featured image for a blog post about: [Title]"
- **Pages**: "A professional featured image for a page about: [Title]"
- **Products**: "A professional featured image for a product about: [Title]"
- **Quiz**: "A professional featured image for a quiz about: [Title]"
- **Place**: "A professional featured image for a place about: [Name]"
- **ECA**: "A professional featured image for an extra-curricular activity about: [Title]"
- **Policy**: "A professional featured image for a policy about: [Policy Name]"

## Technical Implementation

### Backend Architecture

#### Trait-Based Approach

All research pages use the `WP_MCP_AI_Research_Page_Featured_Image` trait which provides:

```php
// Generate featured image using AI
protected static function generate_featured_image( $prompt, $title, $context )

// Process featured image request from POST data
protected static function process_featured_image_request( $research_data, $title, $context )
```

#### AJAX Flow

1. User completes research in chat interface
2. User clicks "Create [Type] from Research"
3. Modal dialog appears with image generation options
4. User confirms creation
5. JavaScript sends AJAX request with:
   - `research_data` (JSON)
   - `generate_featured_image` (boolean)
   - `image_prompt` (optional string)
6. Backend generates image if requested
7. Backend creates post/page/product with featured image ID
8. User is redirected to edit screen

### JavaScript Integration

All `add*ToDatabase()` methods accept an `options` parameter:

```javascript
addPostToDatabase(researchData, {
    generateFeaturedImage: true,
    imagePrompt: 'Custom image description'
})
```

### Integration with WordPress

The generated images are:

1. Uploaded to WordPress Media Library as attachments
2. Associated with the post/page using `set_post_thumbnail()`
3. Visible in the WordPress editor's Featured Image metabox
4. Available for use in themes and templates via `the_post_thumbnail()`

## Configuration Requirements

### Required

At least one of the following AI providers must be configured:

- **OpenAI API Key** - Configured in NV oOS settings
- **Google Gemini API Key** - Configured in NV oOS settings
- **Cloudflare AI Token** - Configured in NV oOS settings

### Optional

- No additional configuration needed
- Feature automatically detects available providers
- Gracefully falls back if a provider fails

## Error Handling

If image generation fails:

1. The post/page/product is still created successfully
2. No featured image is set
3. No error is shown to the user
4. User can manually add a featured image later

This ensures content creation is never blocked by image generation failures.

## WordPress Compatibility

### Post Types

Works with any WordPress post type that supports:

- The Featured Image functionality (`post-thumbnails`)
- Standard WordPress `set_post_thumbnail()` API

### Themes

Generated featured images work with any WordPress theme that supports:

- `the_post_thumbnail()` template tag
- Featured image display in listings
- Featured image display in single post/page views

## Code Examples

### Using in Research Pages

```php
// In handle_create_from_research() method
$research_data = self::process_featured_image_request( 
    $research_data, 
    $research_data['title'], 
    'a blog post' 
);
```

### JavaScript Integration

```javascript
// Show confirmation dialog with image options
this.showCreateConfirmation({
    title: 'Create post with the researched information?',
    postType: 'post',
    onConfirm: (options) => {
        this.addPostToDatabase(researchData, options);
    }
});
```

## Future Enhancements

Potential improvements for future versions:

1. Support for multiple featured images/gallery
2. Image style presets (photorealistic, illustration, etc.)
3. Image editing capabilities before setting as featured
4. Batch image generation for multiple posts
5. Integration with stock photo services as fallback
6. Image dimension/aspect ratio customization
7. Automatic alt text generation for accessibility
8. Image optimization and compression options

## Troubleshooting

### Image Not Generated

**Symptom**: Post/page created but no featured image

**Possible Causes**:
1. No AI provider configured - Check API keys in NV oOS settings
2. API quota exceeded - Check provider dashboard
3. Invalid API credentials - Verify keys are correct
4. Network connectivity issues - Check server can reach API endpoints

**Solution**: Manually add featured image in WordPress editor

### Wrong Image Generated

**Symptom**: Image doesn't match expected content

**Solution**: 
1. Try providing a more detailed custom image prompt
2. Regenerate by editing post and using "Set featured image"
3. Adjust research content to be more specific

### Permission Errors

**Symptom**: "You do not have permission" errors

**Solution**: Ensure user has appropriate WordPress capabilities:
- `edit_posts` for Posts
- `edit_pages` for Pages
- `edit_products` for WooCommerce Products
- `edit_posts` for custom post types

## Support

For issues or questions:

1. Check NV oOS documentation
2. Review AI provider status pages
3. Verify WordPress permissions
4. Check PHP error logs
5. Contact NV Digital Solutions support

## Changelog

### Version 1.0.0
- Initial release
- Support for 7 research page types
- Multi-provider image generation
- Custom image prompt support
- Automatic fallback between providers
