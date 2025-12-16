# OpenAI Sora Video Generation

This document describes the OpenAI Sora video generation tools added to WP oOS.

## Overview

The plugin now supports video generation using OpenAI's Sora 2 models:
- **Sora 2**: Standard quality video generation (5-20 seconds)
- **Sora 2 Pro**: Higher quality, more coherent video generation (5-60 seconds)

## Tools

### generate_sora_video

Generates videos from text prompts using OpenAI's Sora API.

**Slug**: `generate_sora_video`

**Parameters**:
- `prompt` (required): Text description of the desired video
- `model` (optional): Model to use - `sora-2` (default) or `sora-2-pro`
- `size` (optional): Video resolution - `480p`, `720p`, or `1080p` (default)
- `duration` (optional): Video length in seconds (5-20 for sora-2, 5-60 for sora-2-pro)
- `fps` (optional): Frames per second - 24, 30, or 60 (default: 24)
- `aspect_ratio` (optional): Video aspect ratio - `16:9` (default), `9:16`, or `1:1`
- `file_name` (optional): Base filename for the saved video
- `save_to_media` (optional): Whether to save to Media Library (default: true)
- `timeout` (optional): API request timeout in seconds (default: 300)

**Example Usage**:
```json
{
  "tool": "generate_sora_video",
  "arguments": {
    "prompt": "A serene mountain lake at sunset with golden reflections",
    "model": "sora-2",
    "duration": 10,
    "size": "1080p",
    "aspect_ratio": "16:9"
  }
}
```

**Capability Flags**:
- `requires-credentials`: Requires OpenAI API key
- `requires-capability`: Requires `upload_files` capability
- `write`: Creates video files
- `external-api`: Makes external API requests
- `async`: Supports asynchronous execution
- `long-running`: Video generation takes several minutes
- `background-only`: Must run in background to avoid timeouts

### generate_sora_video_validated

Validated wrapper for the Sora video generation tool. Uses the same parameters and behavior as `generate_sora_video` but includes additional validation.

**Slug**: `generate_sora_video_validated`

## Pricing

Video generation costs are calculated per second of generated video:

| Model | Cost per Second |
|-------|----------------|
| sora-2 | $0.10 |
| sora-2-pro | $0.20 |

**Example Costs**:
- 10-second video with sora-2: $1.00
- 10-second video with sora-2-pro: $2.00
- 30-second video with sora-2-pro: $6.00

## Configuration

### Admin Settings

Configure default Sora settings in WordPress Admin:
1. Navigate to **Settings → WP oOS**
2. Find the **OpenAI Video** section
3. Set defaults for:
   - Model (sora-2 or sora-2-pro)
   - Resolution (480p, 720p, or 1080p)
   - Duration (5-60 seconds)
   - FPS (24, 30, or 60)

### API Key

An OpenAI API key is required. Configure it in:
**Settings → WP oOS → OpenAI API Key**

## Async Execution

Video generation typically takes 5-10 minutes. The tool automatically uses async mode to prevent HTTP timeouts:

1. Tool execution queues a background job
2. Returns immediately with job ID and expected URL
3. Job processes in background via WordPress cron
4. Video appears in Media Library when complete

**Checking Status**:
```json
{
  "tool": "check_video_status",
  "arguments": {
    "job_id": "sora_abc123xyz"
  }
}
```

## Media Library Integration

Generated videos are automatically:
- Saved to WordPress Media Library
- Tagged with generation metadata (prompt, model, etc.)
- Assigned to the requesting user
- Available in post/page editor media picker

**Metadata Stored**:
- `_sora_prompt`: Original generation prompt
- `_sora_model`: Model used (sora-2 or sora-2-pro)
- `_provider`: Always set to "openai"
- `_sora_job_id`: Background job identifier (if async)

## Model Capabilities

### Sora 2
- Duration: 5-20 seconds
- Resolution: Up to 1080p
- Aspect ratios: 16:9, 9:16, 1:1
- FPS: 24, 30, 60
- Best for: Standard video content, prototypes, previews

### Sora 2 Pro
- Duration: 5-60 seconds
- Resolution: Up to 1080p
- Aspect ratios: 16:9, 9:16, 1:1
- FPS: 24, 30, 60
- Best for: High-quality productions, marketing content, longer clips

## Best Practices

### Prompts
- Be specific and detailed
- Include visual elements, actions, and style
- Mention lighting, camera angles, and mood
- Use cinematic language for better results

**Good Prompt Example**:
```
"Wide shot of a modern coffee shop interior at golden hour, 
sunlight streaming through large windows, barista preparing 
latte art, warm ambient lighting, shallow depth of field, 
cinematic composition"
```

### Performance
- Use `sora-2` for faster, cost-effective generation
- Reserve `sora-2-pro` for final productions
- Keep durations short (5-10 seconds) during development
- Use async mode (automatic) to prevent timeouts

### Costs
- Start with shorter durations to test prompts
- Monitor usage via OpenAI dashboard
- Set up budget alerts in OpenAI account
- Consider caching frequently generated concepts

## Troubleshooting

### "No OpenAI API key configured"
- Add your OpenAI API key in Settings → WP oOS
- Ensure the key has video generation permissions
- Verify the key is valid and active

### "You do not have permission to generate videos"
- Requires `upload_files` capability
- Check user role and permissions
- Administrators and Editors have access by default

### Video generation takes too long
- This is normal - Sora generation takes 5-10 minutes
- Use async mode (automatic) to prevent timeouts
- Check job status with `check_video_status` tool
- Monitor WordPress cron execution

### Generated video not in Media Library
- Check job status - may still be processing
- Verify background job completed (check logs)
- Ensure user has upload permissions
- Check WordPress upload directory permissions

## Related Tools

- `generate_veo_video`: Google's Veo video generation
- `generate_openai_image`: OpenAI image generation
- `check_video_status`: Check async video job status
- `analyze_video`: Analyze existing videos with AI

## API Reference

### OpenAI Sora API Endpoint
```
POST https://api.openai.com/v1/videos/generations
```

### Response Format
The API returns a URL to download the generated video. The plugin automatically downloads and saves it to the Media Library.

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/` directory
- OpenAI Documentation: https://platform.openai.com/docs/models/sora-2

## Version History

- **v1.0.0** (December 2024): Initial Sora 2 support added
  - Sora 2 and Sora 2 Pro models
  - Async execution support
  - Media Library integration
  - Cost calculation
