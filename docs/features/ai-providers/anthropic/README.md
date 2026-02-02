# Anthropic (Claude) Provider

## Overview

The Anthropic provider integrates Claude AI models into WordPress through the NV oOS plugin. Claude models offer advanced reasoning, code generation, and vision capabilities.

## Supported Features

### ✅ Available Capabilities

#### Chat Completion
- Full support for Claude's conversational AI
- Models: Claude 3.5 Sonnet, Claude 3.5 Haiku, Claude Opus, and more
- Streaming responses supported
- Function/tool calling supported

#### Vision & Image Analysis
- **Image Analysis**: Detailed description and understanding of images via `analyze_image` tool
- **Image Alt Text Generation**: Generate accessible alt text via `generate_image_alt_text` tool  
- **OCR (Text Extraction)**: Extract text from images, documents, and screenshots via `extract_image_text` tool
- **Visual Question Answering**: Ask questions about image content
- Supported formats: JPEG, PNG, GIF, WebP
- Maximum image size: 10MB (5MB recommended for best performance)

### ❌ NOT Supported Natively

The following features are **not available** through Anthropic's API:

1. **Image Generation**: Claude cannot create or generate images. For image generation, use:
   - OpenAI (DALL-E, GPT-Image)
   - Google Gemini
   - Cloudflare AI

2. **Text-to-Speech (TTS)**: Claude cannot convert text to audio. For TTS, use:
   - OpenAI (TTS-1, TTS-1-HD)
   - Google Gemini

3. **Audio Transcription**: Claude cannot transcribe audio files. For transcription, use:
   - OpenAI (Whisper)
   - Cloudflare AI
   - Google Gemini

## Configuration

### Basic Settings

Navigate to **Settings → NV oOS → Providers → Anthropic**:

1. **Enable Anthropic Provider**: Toggle to enable/disable
2. **API Key**: Your Anthropic API key from [console.anthropic.com](https://console.anthropic.com/)
3. **Default Model**: Primary Claude model for chat completions

### Vision Settings

4. **Vision Model**: Model to use for image analysis (defaults to your primary model)
5. **Max Image Tokens**: Maximum tokens allocated for image analysis
   - Default: 1568 tokens
   - Range: 1000-4000 tokens
   - Higher values = more detailed analysis but higher cost

## Available Tools

### Multi-Provider Vision Tools

All vision tools support multiple providers (OpenAI, Anthropic, Gemini). Specify `"provider": "anthropic"` in tool arguments to use Claude.

#### `analyze_image`
General-purpose image analysis supporting all providers.

**Use Cases:**
- Describe image content in detail
- Identify objects, people, and scenes
- Analyze composition and colors
- Answer questions about images

**Example:**
```json
{
  "attachment_id": 123,
  "prompt": "Describe this product image for an e-commerce listing",
  "provider": "anthropic",
  "max_tokens": 1024
}
```

#### `extract_image_text`
OCR and text extraction supporting all providers. Anthropic recommended for best accuracy.

**Use Cases:**
- Extract text from screenshots
- OCR for scanned documents
- Read text from photos
- Extract data from infographics

**Example:**
```json
{
  "attachment_id": 456,
  "provider": "anthropic",
  "preserve_layout": true,
  "include_metadata": false
}
```

#### `generate_image_alt_text`
Generate accessibility-friendly alt text. Now supports Anthropic alongside OpenAI and Gemini.

**Example:**
```json
{
  "attachment_id": 789,
  "context": "Product photo for online store"
}
```
*Note: Provider is determined by your default provider setting unless explicitly specified.*

## Best Practices

### Image Analysis

1. **Image Quality**: Higher resolution images provide better results
2. **File Size**: Keep images under 5MB for optimal performance
3. **Format**: PNG and JPEG work best; WebP and GIF are also supported
4. **Prompts**: Be specific in your analysis prompts for better results

### Token Management

- Vision requests consume tokens based on image size and detail level
- Monitor token usage to control costs
- Claude 3.5 Sonnet offers best balance of accuracy and cost
- Claude 3.5 Haiku is faster and more economical

### Model Selection

- **Claude 3.5 Sonnet**: Best balance of performance and cost for vision
- **Claude 3.5 Haiku**: Faster, more economical for simpler tasks
- **Claude Opus**: Highest quality for complex visual reasoning

## API Rate Limits

Anthropic enforces rate limits based on your API tier:
- Free tier: Limited requests per minute
- Paid tiers: Higher limits based on usage

See [Anthropic pricing](https://www.anthropic.com/pricing) for details.

## Troubleshooting

### Common Issues

**"Anthropic client class not found"**
- Ensure the plugin is fully activated
- Check that `class-wp-mcp-ai-anthropic-client.php` exists

**"Image too large"**
- Maximum size is 10MB
- Recommended maximum is 5MB for best performance
- Resize or compress large images before upload

**"Could not get URL for attachment"**
- Verify the attachment ID is valid
- Check that the file still exists in the media library
- Ensure the file is an image type

**"No text could be extracted from the image"**
- Image may not contain any text
- Text may be too small or blurry
- Try using a higher resolution image

## Related Documentation

- [Tool Reference](../../../tool-reference.md)
- [OpenAI Provider](../openai/README.md)
- [Gemini Provider](../gemini/README.md)
- [REST API](../../../rest-api.md)

## Support

For issues specific to Anthropic:
- [Anthropic Documentation](https://docs.anthropic.com/)
- [Anthropic Support](https://support.anthropic.com/)

For plugin issues:
- [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
