# Image Production Toolkit (Phase 2.8)

This directory contains 20 professional AI-powered image tools for the NV oOS Pro toolkit.

## Tools Overview

### AI Image Generation (4 tools)
1. **generate_image_ai** - Generate images from text prompts (DALL-E, Stable Diffusion)
2. **generate_image_variations** - Create variations of existing images
3. **image_inpainting** - AI-powered image inpainting/editing
4. **text_to_image_prompt_optimizer** - Optimize prompts for better results

### Image Editing & Enhancement (5 tools)
5. **remove_image_background** - AI background removal
6. **upscale_image_ai** - Real lanczos3 upscaling 2x/4x/8x via local Sharp or the Media Worker sidecar (Wave 1, issue #6877); `upscale_method` reports honestly
7. **enhance_image_quality** - Real Sharp enhancement (sharpen, saturation, contrast, denoise) via local Sharp or the Media Worker sidecar (Wave 1, issue #6877)
8. **apply_artistic_style** - Real AI style transfer via the Media Worker `/api/image/edit` route or Gemini/OpenAI (Wave 2, issue #6877); 9 preset prompts
9. **colorize_image** - Real AI colorization via the Media Worker `/api/image/edit` route or Gemini/OpenAI (Wave 2, issue #6877)

### Optimization & Batch Processing (11 tools)
10. **compress_image** - Compress with quality preservation
11. **convert_image_format** - Convert formats (JPG, PNG, WebP, AVIF)
12. **resize_image_smart** - Smart content-aware resizing
13. **batch_process_images** - Batch apply operations
14. **generate_responsive_images** - Generate responsive variants
15. **optimize_for_web** - Optimize for web performance
16. **get_images_without_alt** - Query images missing alt text
17. **get_unoptimised_images** - Query images needing optimization
18. **get_unwatermarked_images** - Query images not yet watermarked
19. **apply_watermark_batch** - Batch watermark application
20. **optimise_images_batch** - Batch image optimization

## Wave 1 — real Sharp implementations (2026-10-04, issue #6877)

`upscale_image_ai` and `enhance_image_quality` now run real processing on the
`optimize_image_sharp` dual path: the bundled local Sharp runtime
(`WP_MCP_AI_Sharp_Image_Processing` trait → `bin/sharp-process.js`) first,
the Media Worker sidecar (`/api/image/enhance`, `/api/image/upscale`) second.
Without either backend the tools return honest `WP_Error`s — never fake
success envelopes. See
`docs/project/plans/image-production-sidecar-cluster-plan.md`.

## Wave 2 — real AI edits (2026-10-04, issue #6877)

`colorize_image` and `apply_artistic_style` now run real AI image edits
through the Media Worker sidecar `/api/image/edit` route (Gemini/OpenAI/
Replicate) or the PHP Gemini/OpenAI provider clients
(`WP_MCP_AI_Provider_Image_Edit` trait). The `_wp_mcp_ai_colorized` /
`_wp_mcp_ai_artistic_style` meta stamps are written only on real success.

## Features

- All tools extend `WP_MCP_AI_Tool_Image_Base` or implement `WP_MCP_AI_Tool_Interface`
- Proper WordPress coding standards compliance
- Complete PHPDoc annotations with @phase Phase 2.8
- Support for WordPress media library integration
- Remote processing capability via Remote Sites
- GPU offloading options for heavy processing
- Comprehensive error handling and validation
- LLM-friendly response sanitization

## Integration

These tools integrate with:
- WordPress Media Library
- OpenAI DALL-E API
- Stability AI API
- Various image processing libraries (rembg, Real-ESRGAN, etc.)
- Remote GPU processing services

## Usage

Tools are automatically registered when the Pro toolkit is active. They can be called through:
- WordPress REST API
- MCP protocol
- Direct PHP execution
- AI Assistant workflows

## File Structure

Each tool follows this structure:
- Class name: `WP_MCP_AI_Tool_[Tool_Name]`
- File name: `class-wp-mcp-ai-tool-[tool-slug].php`
- Tool slug: snake_case matching filename
- Required methods: `get_slug()`, `get_name()`, `get_description()`, `get_parameters_schema()`, `execute()`, `get_capability_flags()`

## Security

All tools implement:
- Capability checks (`upload_files` or `manage_options`)
- User authentication validation
- Input sanitization
- Output escaping
- WordPress nonce verification (when applicable)

## Dependencies

- WordPress 6.0+
- PHP 7.4+
- GD or Imagick PHP extension
- Optional: Python 3 with various AI libraries
- Optional: API keys for external services (OpenAI, Stability AI, remove.bg)

## Phase Information

**Phase**: 2.8  
**Component**: Image Production Toolkit  
**Status**: Implemented  
**Tools Count**: 20  
**Total Size**: ~175 KB
