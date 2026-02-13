# Image Generation with Multi-Step Orchestration

**Tools:** `generate_openai_image` and `generate_gemini_image`  
**Feature:** Multi-step orchestration mode  
**Status:** Available in latest version

Both OpenAI (DALL-E) and Google Gemini (Imagen) image generation tools support the same orchestration pattern with provider-specific parameters.

## Quick Start

### OpenAI (DALL-E)

Enable orchestration for enhanced image generation:

```php
$result = $tool->execute(array(
    'prompt'             => 'A serene mountain landscape at sunset',
    'orchestration_mode' => true,
), $context);
```

### Gemini (Imagen)

```php
$result = $tool->execute(array(
    'prompt'             => 'A serene mountain landscape at sunset',
    'aspect_ratio'       => '16:9',
    'orchestration_mode' => true,
), $context);
```

## New Parameters

- `orchestration_mode` (boolean): Enable 5-step workflow
- `optimize_prompt` (boolean): AI-powered prompt enhancement
- `generate_alt_text` (boolean): Automatically generate alt text for accessibility
- `optimize_output` (boolean): Optimize metadata and descriptive titles
- `generate_variants` (boolean): Create responsive image size variants

## Full Automation Example

### OpenAI (DALL-E)

```php
$result = $tool->execute(array(
    'prompt'              => 'Mountain landscape',
    'size'                => '1024x1024',
    'quality'             => 'hd',
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'optimize_output'     => true,
    'generate_variants'   => true,
), $context);
```

### Gemini (Imagen)

```php
$result = $tool->execute(array(
    'prompt'              => 'Mountain landscape',
    'aspect_ratio'        => '16:9',
    'mime_type'           => 'image/png',
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'optimize_output'     => true,
    'generate_variants'   => true,
), $context);
```

Both will:
1. Enhance the prompt with AI (makes it more detailed)
2. Validate all parameters (provider-specific)
3. Generate the image via OpenAI or Gemini
4. Generate alt text for accessibility
5. Create responsive size variants and optimize metadata

## Benefits

- ✅ AI-enhanced prompts for better image quality
- ✅ Automatic alt text generation (accessibility)
- ✅ Parameter validation (prevents API errors)
- ✅ Responsive image variants for different screen sizes
- ✅ Descriptive metadata for better organization
- ✅ Detailed error messages with execution tracking

## Backward Compatibility

Default behavior is unchanged. Orchestration is opt-in via the `orchestration_mode` parameter.

## Usage Scenarios

### 1. Basic Generation with Validation

```php
$result = $tool->execute(array(
    'prompt'             => 'A professional headshot',
    'size'               => '1024x1024',
    'orchestration_mode' => true,
), $context);
```

### 2. Enhanced Prompt + Alt Text

```php
$result = $tool->execute(array(
    'prompt'              => 'sunset',
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,  // AI enhances prompt
    'generate_alt_text'   => true,  // Accessibility
), $context);
```

### 3. Full Optimization for Web

```php
$result = $tool->execute(array(
    'prompt'              => 'Product photo of smartwatch',
    'size'                => '1024x1024',
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,  // Better prompt
    'generate_alt_text'   => true,  // Alt text
    'optimize_output'     => true,  // Metadata
    'generate_variants'   => true,  // Responsive sizes
), $context);
```

## Prompt Optimization Example

**Before Optimization:**
```
"mountain"
```

**After AI Optimization:**
```
"A majestic mountain landscape with snow-capped peaks rising against a clear blue sky, 
alpine meadows in the foreground with wildflowers, dramatic lighting from the setting sun 
casting long shadows, photorealistic style"
```

The AI automatically enhances vague prompts to be more specific and detailed, resulting in higher quality images.

## Error Handling

Orchestration provides detailed error messages:

```json
{
  "error": {
    "code": "orchestration_failed",
    "message": "Image generation orchestration failed at step: validate. Prompt must be at least 3 characters",
    "data": {
      "step": "validate",
      "execution_id": "image_gen_abc123..."
    }
  }
}
```

## Response Format

### Legacy Mode
```json
{
  "attachment_id": 123,
  "url": "https://example.com/wp-content/uploads/image.png",
  "size": "1024x1024",
  "quality": "hd",
  "model": "dall-e-3"
}
```

### Orchestration Mode
```json
{
  "attachment_id": 123,
  "url": "https://example.com/wp-content/uploads/image.png",
  "size": "1024x1024",
  "quality": "hd",
  "model": "dall-e-3",
  "alt_text": "A majestic mountain landscape at sunset...",
  "variants": {
    "thumbnail": {"file": "image-150x150.png", "width": 150, "height": 150},
    "medium": {"file": "image-300x300.png", "width": 300, "height": 300},
    "large": {"file": "image-1024x1024.png", "width": 1024, "height": 1024}
  },
  "execution_id": "image_gen_xyz789...",
  "orchestration": {
    "enabled": true,
    "steps": [
      {"name": "started", "time": "2026-02-13 12:00:00"},
      {"name": "optimize", "time": "2026-02-13 12:00:01"},
      {"name": "validate", "time": "2026-02-13 12:00:02"},
      {"name": "generate", "time": "2026-02-13 12:00:05"},
      {"name": "post_process", "time": "2026-02-13 12:00:06"},
      {"name": "completed", "time": "2026-02-13 12:00:07"}
    ]
  }
}
```

## Parameter Validation

### OpenAI Parameters

Orchestration mode validates:
- **Prompt length**: 3-4000 characters
- **Size**: Must be valid OpenAI size (1024x1024, 1792x1024, 1024x1792, etc.)
- **Quality**: Must be standard or hd
- **Model**: Must be valid OpenAI model name (dall-e-2, dall-e-3)

### Gemini Parameters

Orchestration mode validates:
- **Prompt length**: 3-4000 characters
- **Aspect ratio**: Must be 1:1, 3:4, 4:3, 16:9, or 9:16
- **MIME type**: Must be image/png, image/jpeg, or image/webp
- **Model**: Must be valid Gemini model name (imagen-3.0-generate-001, etc.)

Validation prevents API errors before making expensive API calls.

## Provider Comparison

| Feature | OpenAI (DALL-E) | Gemini (Imagen) |
|---------|-----------------|-----------------|
| **Orchestration** | ✅ 5-step workflow | ✅ 5-step workflow |
| **Size Control** | Fixed sizes (1024x1024, etc.) | Aspect ratios (16:9, etc.) |
| **Format Control** | PNG only | PNG, JPEG, WebP |
| **Quality Modes** | Standard, HD | Standard |
| **Prompt Optimization** | ✅ AI-enhanced | ✅ AI-enhanced |
| **Alt Text** | ✅ Auto-generated | ✅ Auto-generated |
| **Variants** | ✅ Responsive sizes | ✅ Responsive sizes |
| **Metadata** | ✅ Full metadata | ✅ Full metadata + provider tag |

## Performance Considerations

### Execution Time by Features

| Configuration | Typical Time |
|---------------|--------------|
| Legacy mode | 5-15 seconds |
| Orchestration (validation only) | 5-15 seconds |
| + Prompt optimization | 8-20 seconds |
| + Alt text generation | 10-25 seconds |
| + Full optimization | 12-30 seconds |

### Optimization Tips

1. **Selective Features**: Only enable features you need
2. **Prompt Quality**: Better prompts = better images (use `optimize_prompt`)
3. **Variants**: Generate once, use everywhere (responsive images)
4. **Alt Text**: Essential for accessibility compliance

## Troubleshooting

### Issue: "Orchestration mode not working"

**Solution:** Ensure `orchestration_mode` is explicitly set to `true` (boolean, not string):

```php
// ❌ Wrong
'orchestration_mode' => 'true',  // String

// ✅ Correct
'orchestration_mode' => true,     // Boolean
```

### Issue: "Prompt optimization not improving prompt"

**Solution:** Provide more context in the original prompt:

```php
// ❌ Too vague
'prompt' => 'car',

// ✅ Better starting point
'prompt' => 'sports car for advertising',
```

### Issue: "Alt text generation failing"

**Solution:** Ensure `generate_image_alt_text` tool is available and image was successfully created.

## Accessibility Benefits

When `generate_alt_text=true`:
- Automatically generates descriptive alt text
- Improves screen reader experience
- Helps with SEO
- Meets WCAG compliance requirements

Example alt text generated:
```
"A serene mountain landscape at sunset with snow-capped peaks, 
alpine meadows with wildflowers, and dramatic lighting"
```

## Integration Examples

### With Featured Images (OpenAI)

```php
// Generate and set as featured image
$result = $tool->execute(array(
    'prompt'              => 'Blog post hero image about technology',
    'size'                => '1792x1024',  // Wide format
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'generate_variants'   => true,
), $context);

if (!is_wp_error($result)) {
    set_post_thumbnail($post_id, $result['attachment_id']);
}
```

### With Featured Images (Gemini)

```php
// Generate and set as featured image
$result = $tool->execute(array(
    'prompt'              => 'Blog post hero image about technology',
    'aspect_ratio'        => '16:9',  // Wide format
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'generate_variants'   => true,
), $context);

if (!is_wp_error($result)) {
    set_post_thumbnail($post_id, $result['attachment_id']);
}
```

### With Product Images

```php
// Generate product photo (OpenAI)
$result = $tool->execute(array(
    'prompt'              => 'Product photo of ' . $product_name,
    'size'                => '1024x1024',  // Square format
    'quality'             => 'hd',
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'optimize_output'     => true,
), $context);

// Or use Gemini
$result = $tool->execute(array(
    'prompt'              => 'Product photo of ' . $product_name,
    'aspect_ratio'        => '1:1',  // Square format
    'mime_type'           => 'image/webp',  // Smaller file size
    'orchestration_mode'  => true,
    'optimize_prompt'     => true,
    'generate_alt_text'   => true,
    'optimize_output'     => true,
), $context);
```

---

For complete documentation, see the developer guides in `docs/guides/developer/`.
