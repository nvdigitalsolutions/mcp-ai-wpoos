# Design Professional Tools Integration

## Overview

The Design Professional tools preset provides a comprehensive suite of AI-powered tools for CAD, rendering, 3D modeling, branding, and vector graphics workflows. This preset is optimized for creative professionals working with visual content, video production, and design deliverables.

## Tool Preset

The `design_professional` preset includes the following categories of tools:

### Image Generation & Editing
- **generate_openai_image** - Generate images using DALL-E models
- **generate_gemini_image** - Generate images using Google Gemini
- **edit_gemini_image** - Edit existing images with Gemini AI
- **resize_image** - Resize images to specific dimensions
- **crop_image** - Crop images to specified regions
- **rotate_image** - Rotate images by degrees
- **convert_image_format** - Convert between image formats (PNG, JPEG, WebP, etc.)

### Video Production
- **generate_veo_video** - Generate videos using Google Veo
- **check_video_status** - Poll async video generation job status
- **analyze_video** - AI-powered video content analysis
- **extract_video_frames** - Extract frames from video for processing
- **get_video_metadata** - Retrieve video file metadata

### Vision & Analysis
- **vision_object_localization** - Detect and locate objects in images
- **vision_product_search** - Find similar products using visual search
- **generate_image_alt_text** - Generate accessible alt text for images
- **generate_image_caption** - Create descriptive captions for images

### Data Visualization & Audio
- **create_chart** - Generate charts and graphs from data
- **generate_music** - AI-powered music generation

## Token Usage & Budgeting

### Token Multipliers

Design professional tools have higher token consumption due to their resource-intensive nature. The following multipliers are applied:

| Tool | Multiplier | Rationale |
|------|-----------|-----------|
| `generate_veo_video` | 5.0x | Video generation is extremely token-intensive |
| `generate_music` | 3.5x | Audio generation requires significant processing |
| `generate_openai_image` | 3.0x | Image generation with DALL-E |
| `generate_gemini_image` | 3.0x | Image generation with Gemini |
| `edit_gemini_image` | 2.5x | Image editing operations |
| `analyze_video` | 2.5x | Video analysis with vision models |
| `extract_video_frames` | 2.0x | Frame extraction and processing |
| `vision_object_localization` | 2.0x | Object detection in images |
| `vision_product_search` | 2.0x | Visual search operations |
| `generate_image_alt_text` | 1.5x | Vision model inference for alt text |
| `generate_image_caption` | 1.5x | Vision model inference for captions |
| `check_video_status` | 1.0x | Simple status polling (minimal tokens) |

### Best Practices for Token Management

1. **Set Appropriate User Tiers**
   - Free tier: 50,000 tokens/day (limited design work)
   - Pro tier: 200,000 tokens/day (regular design workflows)
   - Enterprise tier: 1,000,000 tokens/day (intensive production work)

2. **Tool-Specific Limits**
   ```php
   // Example: Limit video generation to 5 requests per day
   WP_MCP_AI_Tool_Token_Limits::set_tool_limit(
       'generate_veo_video',
       5,
       'daily'
   );
   ```

3. **Monitor High-Cost Operations**
   - Track video generation requests closely
   - Set alerts for users exceeding 80% of limits
   - Review music generation patterns monthly

4. **Batch Operations**
   - Encourage users to batch image processing tasks
   - Use frame extraction wisely (select key frames vs. all frames)
   - Generate charts from pre-processed data when possible

## Orchestration Configuration

### Recommended Preset: Balanced

For most design professional workloads, use the **balanced** orchestration preset with these adjustments:

```php
$orchestration_config = array(
    'max_concurrent_tools'     => 3,  // Allow multiple image operations
    'tool_timeout'             => 120, // Extended for video/image generation
    'retry_attempts'           => 2,   // Retry failed generations
    'rate_limit_per_minute'    => 15,  // Prevent API throttling
    'async_threshold'          => 30,  // Force async for long operations
);
```

### High-Traffic Configuration

For sites with many design professionals:

```php
$orchestration_config = array(
    'max_concurrent_tools'     => 5,
    'tool_timeout'             => 180,
    'retry_attempts'           => 3,
    'rate_limit_per_minute'    => 25,
    'async_threshold'          => 20,
    'queue_enabled'            => true, // Enable job queuing
);
```

### Development/Testing Configuration

For development and testing design tools:

```php
$orchestration_config = array(
    'max_concurrent_tools'     => 2,
    'tool_timeout'             => 60,
    'retry_attempts'           => 1,
    'rate_limit_per_minute'    => 5,
    'async_threshold'          => 10,
    'cache_results'            => true, // Cache for testing
);
```

## Tool-Specific Orchestration Hints

### Video Generation (generate_veo_video)
- **Always run async**: Video generation takes 30-120 seconds
- **Poll with check_video_status**: Use exponential backoff (10s, 20s, 40s)
- **Timeout**: Set to 180 seconds minimum
- **Retry strategy**: Linear retry with 30s delay

### Image Generation
- **Batch similar requests**: Group multiple image generations
- **Cache results**: Enable caching for development/testing
- **Fallback models**: Use Gemini as fallback if OpenAI fails
- **Quality vs. Speed**: Offer quality tiers (fast/standard/high)

### Video Analysis
- **Pre-process videos**: Extract key frames before analysis
- **Chunk large files**: Process videos in segments for files > 50MB
- **Parallel processing**: Analyze multiple frames concurrently
- **Result caching**: Cache analysis results for 24 hours

### Vision Tools
- **Optimize images**: Resize to max 2048px before processing
- **Batch detection**: Group multiple object localization requests
- **Use appropriate models**: Vision-capable models only
- **Error handling**: Graceful fallback for unsupported formats

## Model Requirements

Design professional tools require specific AI model capabilities:

| Tool Category | Required Capability | Recommended Models |
|--------------|---------------------|-------------------|
| Image Generation | `image-generation` | DALL-E 3, Gemini Imagen |
| Image Editing | `image-editing` | Gemini |
| Video Generation | `video` | Google Veo |
| Vision Analysis | `vision` | GPT-4 Vision, Gemini Pro Vision |
| Music Generation | `audio` | Model-specific audio generators |

## API Credentials Required

- **OpenAI API Key**: For DALL-E image generation
- **Google AI API Key**: For Gemini image/video generation and vision tools
- **Google Cloud Vision API**: For advanced vision features (optional)

## Usage Examples

### Creating a Design Assistant

```php
// Create assistant with design professional preset
$assistant_id = wp_insert_post( array(
    'post_type'   => 'mcp_ai_assistant',
    'post_title'  => 'Brand Design Assistant',
    'post_status' => 'publish',
) );

// Apply design professional preset
$design_tools = array(
    'generate_openai_image',
    'generate_gemini_image',
    'edit_gemini_image',
    'resize_image',
    'crop_image',
    'create_chart',
    'vision_product_search',
);

update_post_meta( $assistant_id, '_wp_mcp_ai_tools', $design_tools );
```

### Setting Tool-Specific Limits

```php
// Set custom limits for design tools
WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'generate_veo_video', 100000 );
WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'generate_openai_image', 75000 );
WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'generate_music', 50000 );
```

### Monitoring Usage

```php
// Get design tool usage statistics
$design_tools = array(
    'generate_openai_image',
    'generate_gemini_image',
    'generate_veo_video',
    'generate_music',
);

$usage_stats = array();
foreach ( $design_tools as $tool_slug ) {
    $stats = WP_MCP_AI_Tool_Token_Limits::get_tool_usage_stats( $tool_slug );
    $usage_stats[ $tool_slug ] = $stats;
}
```

## Performance Optimization

### Caching Strategies

1. **Image Results**: Cache generated images for 7 days
2. **Video Metadata**: Cache video metadata indefinitely
3. **Vision Analysis**: Cache object detection results for 24 hours
4. **Chart Generation**: Cache charts based on data hash

### Resource Management

1. **Queue Management**: Use background processing for video generation
2. **File Cleanup**: Auto-delete temporary files after 24 hours
3. **CDN Integration**: Serve generated media from CDN
4. **Compression**: Auto-compress images to WebP format

### Error Handling

1. **Rate Limiting**: Implement exponential backoff for API calls
2. **Graceful Degradation**: Fallback to lower quality on errors
3. **User Feedback**: Provide clear status updates for long operations
4. **Retry Logic**: Auto-retry failed generations with adjusted parameters

## Security Considerations

1. **File Validation**: Validate all uploaded files for type and size
2. **Output Sanitization**: Sanitize all generated content before display
3. **API Key Security**: Store API keys encrypted in database
4. **User Permissions**: Restrict design tools to appropriate user roles
5. **Rate Limiting**: Prevent abuse with per-user rate limits

## Troubleshooting

### Common Issues

**Video Generation Timeouts**
- Increase `tool_timeout` to 180+ seconds
- Enable async processing
- Use queue system for batch jobs

**Image Quality Issues**
- Verify API credentials are valid
- Check model availability in region
- Review prompt quality and specificity

**High Token Consumption**
- Review tool multipliers
- Implement result caching
- Optimize image sizes before processing
- Use batch operations

**Vision Tool Errors**
- Ensure vision-capable model is selected
- Verify image format compatibility
- Check image size limits (max 20MB)
- Validate image URLs are accessible

## Future Enhancements

Planned additions to the design professional toolkit:

- **CAD Import/Export**: Native support for CAD file formats (DWG, DXF)
- **3D Model Generation**: AI-powered 3D model creation
- **Brand Kit Integration**: Automated brand consistency checking
- **Vector Graphics**: SVG generation and manipulation
- **Color Palette Generation**: AI-suggested color schemes
- **Typography Analysis**: Font pairing recommendations
- **AR/VR Preview**: Augmented reality visualization tools

## Support & Resources

- **Documentation**: See `/docs/tool-reference.md` for detailed tool specifications
- **API Reference**: Check `/docs/rest-api.md` for REST endpoints
- **Examples**: Review `/assets/examples/` for code samples
- **Community**: GitHub Discussions for questions and feedback

## Related Documentation

- [Tool Reference](../../../reference/tools/tool-reference.md)
- [Token Manager](../../../reference/tools/TOOL-MULTIPLIERS.md)
- [Orchestration Configuration](../../../archive/features/TOOL_EXECUTION_ORCHESTRATION.md)
- [Assistant Tool Shortcuts](../../../getting-started/first-steps/assistant-tool-shortcuts.md)
