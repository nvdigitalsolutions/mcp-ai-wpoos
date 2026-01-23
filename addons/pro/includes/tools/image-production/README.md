# Image Production Toolkit (Phase 2.8)

This directory contains 15 professional AI-powered image tools for the NV oOS Pro toolkit.

## Tools Overview

### AI Image Generation (4 tools)
1. **generate_image_ai** - Generate images from text prompts (DALL-E, Stable Diffusion)
2. **generate_image_variations** - Create variations of existing images
3. **image_inpainting** - AI-powered image inpainting/editing
4. **text_to_image_prompt_optimizer** - Optimize prompts for better results

### Image Editing & Enhancement (5 tools)
5. **remove_image_background** - AI background removal
6. **upscale_image_ai** - AI upscaling (2x, 4x, 8x)
7. **enhance_image_quality** - Enhance quality, sharpness, colors
8. **apply_artistic_style** - Apply artistic styles (style transfer)
9. **colorize_image** - Colorize black & white images

### Optimization & Batch Processing (6 tools)
10. **compress_image** - Compress with quality preservation
11. **convert_image_format** - Convert formats (JPG, PNG, WebP, AVIF)
12. **resize_image_smart** - Smart content-aware resizing
13. **batch_process_images** - Batch apply operations
14. **generate_responsive_images** - Generate responsive variants
15. **optimize_for_web** - Optimize for web performance

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
**Tools Count**: 15  
**Total Size**: ~97 KB
