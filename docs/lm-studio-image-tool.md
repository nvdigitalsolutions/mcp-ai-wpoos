# LM Studio Image Generation Tool

## Overview

The `generate_lm_studio_image` tool enables AI-powered image creation using a local LM Studio instance (running models like google/gemma-3-12b) to enhance prompts before generating images via OpenAI or Gemini.

## Key Features

- **Local Prompt Enhancement**: Uses LM Studio with models like Gemma to transform basic image ideas into detailed, effective prompts
- **Flexible Image Generation**: Optionally creates images using enhanced prompts via OpenAI or Gemini
- **Provider Auto-Selection**: Automatically chooses the best available image generation provider
- **Style Guidance**: Optional style hints to guide prompt enhancement
- **Dual-Mode Operation**: Can work as prompt enhancer only or full image generator

## Use Cases

### 1. Enhanced Image Quality
Transform simple prompts into detailed descriptions that produce better AI-generated images:

**Input**: "a cat"
**Enhanced**: "A photorealistic image of a majestic cat sitting on a windowsill, bathed in warm golden hour sunlight. The cat has striking amber eyes, detailed fur texture, and a serene expression. Shallow depth of field with bokeh background showing a garden. Professional photography, high detail, natural lighting."

### 2. Style-Specific Image Creation
Add style guidance to generate images in specific artistic styles:

**Input**: "mountain landscape"  
**Style**: "watercolor painting"  
**Enhanced**: "A watercolor painting of a serene mountain landscape with soft color transitions, visible brush strokes, gentle gradients from blue mountain peaks to green valleys, with delicate white clouds, painted in traditional watercolor technique with natural pigment flow and paper texture visible."

### 3. Technical Documentation Images
Create technical diagrams with precise specifications:

**Input**: "system architecture"  
**Style**: "technical diagram"  
**Enhanced**: "A clean, minimalist technical diagram showing system architecture with labeled components, directional arrows, color-coded layers, professional typography, white background, precise geometric shapes, and clear hierarchical structure following standard UML notation."

## Tool Specification

### Tool Slug
`generate_lm_studio_image`

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `prompt` | string | **Yes** | - | The basic idea or concept for the image |
| `enhance_prompt` | boolean | No | `true` | Whether to use LM Studio to enhance the prompt |
| `generate_image` | boolean | No | `true` | Whether to actually generate the image |
| `image_provider` | enum | No | `auto` | Provider for image generation: `openai`, `gemini`, or `auto` |
| `model` | string | No | (from settings) | LM Studio model to use (e.g., `google/gemma-3-12b`) |
| `style_guidance` | string | No | - | Optional style hints like "photorealistic", "artistic", "technical diagram" |
| `file_name` | string | No | - | Base filename for the saved image attachment |
| `timeout` | integer | No | (from settings) | Override LM Studio request timeout (5-300 seconds) |

### Return Values

| Field | Type | Description |
|-------|------|-------------|
| `original_prompt` | string | The user's input prompt |
| `enhanced_prompt` | string | LM Studio-improved prompt |
| `enhancement_model` | string | Model used for enhancement |
| `lm_studio_used` | boolean | Whether LM Studio was used |
| `image_generated` | boolean | Whether an image was created |
| `image_provider` | string | Provider used for generation (openai/gemini) |
| `attachment_id` | integer | WordPress attachment ID (if image generated) |
| `url` | string | Public URL of the image (if generated) |
| `download_url` | string | Download URL for the image (if generated) |
| `file_name` | string | Filename of saved image (if generated) |
| `mime_type` | string | MIME type of the image (if generated) |
| `bytes` | integer | File size in bytes (if generated) |
| `title` | string | Attachment title (if generated) |
| `model` | string | Image generation model used (if generated) |
| `provider` | string | Image generation provider (if generated) |
| `usage` | object | Token/credit usage information (if available) |
| `image_error` | string | Error message if image generation failed |

## Configuration

### LM Studio Setup

1. **Install LM Studio** from [lmstudio.ai](https://lmstudio.ai)

2. **Load a Model** (recommended: google/gemma-3-12b or similar)
   - Download via LM Studio's model browser
   - Load the model in memory

3. **Start the Server**
   - Click "Start Server" in LM Studio
   - Default port: 1234
   - Note the server URL (usually `http://localhost:1234`)

4. **Configure WP oOS**
   ```
   WordPress Admin → Settings → WP oOS → Providers → LM Studio
   
   ✅ Enable LM Studio Provider: checked
   📍 Endpoint URL: http://localhost:1234
   🤖 Model: google/gemma-3-12b
   ```

### Image Provider Setup

Configure at least one image generation provider:

**OpenAI** (Settings → WP oOS → Providers → OpenAI)
- API Key: Your OpenAI API key
- Image Model: gpt-image-1 (recommended) or dall-e-3

**Gemini** (Settings → WP oOS → Providers → Gemini)
- API Key: Your Gemini API key
- Image Model: gemini-2.5-flash-image

## Usage Examples

### Example 1: Basic Usage
```php
$tool = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();

$result = $tool->execute(
    array(
        'prompt' => 'a sunset over mountains',
    ),
    array('user_id' => get_current_user_id())
);

// Returns enhanced prompt and generated image
echo $result['enhanced_prompt'];
echo $result['url'];
```

### Example 2: Prompt Enhancement Only
```php
$result = $tool->execute(
    array(
        'prompt'         => 'a futuristic city',
        'enhance_prompt' => true,
        'generate_image' => false,
    ),
    array('user_id' => get_current_user_id())
);

// Use enhanced prompt with other tools
$enhanced = $result['enhanced_prompt'];
```

### Example 3: With Style Guidance
```php
$result = $tool->execute(
    array(
        'prompt'          => 'a flower garden',
        'style_guidance'  => 'impressionist painting',
        'image_provider'  => 'openai',
    ),
    array('user_id' => get_current_user_id())
);
```

### Example 4: Specific Model and Provider
```php
$result = $tool->execute(
    array(
        'prompt'          => 'abstract art',
        'model'           => 'google/gemma-3-12b',
        'image_provider'  => 'gemini',
        'file_name'       => 'abstract-art',
    ),
    array('user_id' => get_current_user_id())
);
```

### Example 5: Via REST API
```bash
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/tools/execute \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "tool": "generate_lm_studio_image",
    "arguments": {
      "prompt": "a peaceful zen garden",
      "style_guidance": "watercolor",
      "image_provider": "openai"
    }
  }'
```

### Example 6: In Assistant Conversation
When chatting with an assistant that has this tool enabled:

**User**: "Create a product photo of a coffee mug with professional lighting"

**Assistant** (internally calls tool):
```json
{
  "tool": "generate_lm_studio_image",
  "arguments": {
    "prompt": "coffee mug product photo",
    "style_guidance": "professional product photography"
  }
}
```

**Result**: Enhanced prompt creates detailed specifications, then generates high-quality image

## Architecture

### How It Works

1. **Prompt Enhancement Phase**
   - User provides basic prompt
   - LM Studio receives enhancement instruction
   - Model (e.g., Gemma) generates detailed, specific prompt
   - Returns enhanced prompt to tool

2. **Image Generation Phase** (if enabled)
   - Enhanced prompt passed to selected provider
   - OpenAI or Gemini generates image
   - Image saved to WordPress Media Library
   - Returns complete metadata

### Provider Selection Logic

When `image_provider` is set to `auto`:

1. Check if OpenAI API key is configured → Use OpenAI
2. Else, check if Gemini API key is configured → Use Gemini
3. Else, return error

### Error Handling

The tool handles errors gracefully:

- **LM Studio unavailable**: Returns error, image generation skipped
- **Image generation fails**: Returns enhanced prompt with error message
- **No credentials**: Returns error with configuration instructions

## Capability Flags

The tool declares these capability flags:

- `requires-credentials`: Needs LM Studio + image provider credentials
- `requires-capability`: Requires user permissions
- `write`: Creates media files when generating images
- `async`: May take significant time
- `rate-limited`: Subject to rate limits
- `requires-model`: Requires LM Studio model specification
- `consumes-tokens`: Uses AI credits/tokens
- `model-dependent`: Output quality varies by model
- `local-ai-compatible`: Works with local LM Studio

## Permissions

### Required Capabilities

- **User must have**: `read` capability (subscriber or higher)
- **For image generation**: Inherits upload permissions from image tool

### Multisite Support

- Works on multisite installations
- User must be member of current blog
- Per-site configuration supported

## Best Practices

### 1. Optimize Prompts for Enhancement
**Good input**: "a product photo"  
**Better input**: "a professional product photo of a coffee mug"

The more context you provide, the better the enhancement.

### 2. Use Style Guidance Effectively
- Be specific: "vintage photography" instead of just "old"
- Mention technical details: "shallow depth of field", "soft lighting"
- Reference artistic styles: "impressionist", "minimalist", "baroque"

### 3. Choose the Right Provider
- **OpenAI**: Better for photorealistic images, product photos
- **Gemini**: Better for artistic styles, illustrations

### 4. Handle Errors Gracefully
```php
$result = $tool->execute($args, $context);

if (is_wp_error($result)) {
    error_log('Image generation failed: ' . $result->get_error_message());
    return;
}

if (!$result['image_generated'] && isset($result['image_error'])) {
    // Handle partial success (prompt enhanced but image failed)
    use_enhanced_prompt($result['enhanced_prompt']);
}
```

### 5. Cache Enhanced Prompts
Since prompt enhancement uses API calls, consider caching enhanced prompts:

```php
$cache_key = 'enhanced_' . md5($original_prompt . $style_guidance);
$enhanced = get_transient($cache_key);

if (!$enhanced) {
    $result = $tool->execute(
        array(
            'prompt' => $original_prompt,
            'enhance_prompt' => true,
            'generate_image' => false,
        ),
        $context
    );
    $enhanced = $result['enhanced_prompt'];
    set_transient($cache_key, $enhanced, HOUR_IN_SECONDS);
}
```

## Performance Considerations

### Timeouts
- **LM Studio requests**: Default 120 seconds (adjustable via `timeout` parameter)
- **Image generation**: Varies by provider (typically 30-60 seconds)
- **Total operation**: Can take 2-3 minutes for full workflow

### Resource Usage
- **Local**: LM Studio runs on your hardware (GPU recommended for Gemma models)
- **API Costs**: Only for image generation provider (OpenAI/Gemini)
- **Storage**: Generated images consume Media Library space

### Optimization Tips
1. Use smaller models for faster prompt enhancement
2. Set appropriate timeouts based on your hardware
3. Consider prompt-only mode for batch operations
4. Cache enhanced prompts when possible

## Troubleshooting

### LM Studio Connection Issues

**Problem**: "No LM Studio endpoint URL configured"
**Solution**: Configure endpoint in Settings → WP oOS → LM Studio

**Problem**: Connection timeout
**Solution**:
- Ensure LM Studio is running
- Check server port (default: 1234)
- Increase timeout setting
- Verify firewall settings

### Image Generation Failures

**Problem**: "No suitable image generation provider"
**Solution**: Configure OpenAI or Gemini API key

**Problem**: Images generated but low quality
**Solution**:
- Improve base prompt
- Add specific style guidance
- Try different image provider
- Check enhanced prompt output

### Model-Specific Issues

**Problem**: Gemma returns generic enhancements
**Solution**:
- Provide more detailed base prompts
- Use specific style guidance
- Try different Gemma model variant

## Integration with Other Tools

### Chain with Image Editing
```php
// 1. Generate image with LM Studio
$generated = generate_lm_studio_image_tool->execute(
    array('prompt' => 'sunset'),
    $context
);

// 2. Edit with Gemini
$edited = edit_gemini_image_tool->execute(
    array(
        'attachment_id' => $generated['attachment_id'],
        'prompt' => 'make it more vibrant',
    ),
    $context
);
```

### Use with Content Creation
```php
// Generate article illustrations
$article_images = array();
foreach ($sections as $section) {
    $result = $tool->execute(
        array(
            'prompt' => extract_image_concept($section),
            'style_guidance' => 'editorial photography',
        ),
        $context
    );
    $article_images[] = $result['attachment_id'];
}
```

## Security

### Input Sanitization
- All prompts sanitized with `sanitize_textarea_field()`
- File names sanitized with `sanitize_file_name()`
- Enum values validated against allowed options

### Output Escaping
- URLs escaped in responses
- Attachment data validated before storage

### Authentication
- Requires authenticated user or valid token
- Permission checks before execution
- Multisite membership validation

## Updates and Versioning

This tool was introduced in WP oOS 1.0.0 and follows semantic versioning.

**Current Version**: 1.0.0  
**Last Updated**: 2024-11-18

## Support

For issues or questions:
- **GitHub**: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- **Documentation**: See `docs/` directory
- **LM Studio Help**: https://lmstudio.ai/

## See Also

- [LM Studio Integration Guide](lm-studio-integration.md)
- [OpenAI Image Tool](tool-reference.md#generate_openai_image)
- [Gemini Image Tool](tool-reference.md#generate_gemini_image)
- [Tool Reference](tool-reference.md)
