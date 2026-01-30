# Media Asset Manager Professional Playbook

## Overview

**Profession:** Media Asset Manager  
**Primary Toolkit:** Media Processing  
**Recommended Pattern:** Sequential Pipeline  
**Risk Tolerance:** Standard  
**Team Size:** 4 agents  

## Primary Tools (17 Tools)

### Image Operations
- `resize_image` - Resize images
- `crop_image` - Crop images
- `rotate_image` - Rotate images
- `convert_image_format` - Format conversion
- `edit_gemini_image` - AI editing
- `edit_openai_image` - AI editing

### Image Generation
- `generate_gemini_image` - AI generation
- `generate_openai_image` - AI generation
- `generate_cloudflareai_image` - AI generation
- `create_image_variation` - Create variations

### Image Enhancement
- `generate_image_alt_text` - Accessibility
- `generate_image_caption` - Captions
- `image_alt_text_optimizer` - Optimize alt text

### Video & Audio
- `generate_sora_video` - AI videos
- `generate_veo_video` - AI videos
- `generate_openai_speech` - Text-to-speech
- `generate_music` - AI music

### Asset Management
- `search_attachments` - Find media files
- `vectorize_image` - Image vectors

## Recommended Pattern: Sequential Pipeline

Linear processing chain for media transformation workflows.

**Pipeline Example:**
```
Upload → Resize → Optimize → Generate Alt Text → Publish
```

## Common Use Cases

1. **Batch Image Processing** - Process multiple images
2. **Media Library Organization** - Organize and tag assets
3. **Automated Publishing** - Prepare media for publication

## Best Practices

1. Maintain original files
2. Consistent naming conventions
3. Automated alt text generation
4. Regular asset audits

---

**Version:** 1.0 | **Date:** January 30, 2026
