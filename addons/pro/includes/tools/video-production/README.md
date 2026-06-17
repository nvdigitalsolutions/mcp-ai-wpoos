# Video Production Toolkit Tools

This directory contains all tools for the Video Production Toolkit.

## AI Video Generation

### Gemini Omni Flash (May 2026 — replaces Veo)
- [ ] generate_omni_video - Any-to-any multimodal video generation (Planned)
- [ ] edit_omni_video - Conversational multi-turn video editing (Planned)
- [x] generate_veo_video - Veo 3.1/2.0 (Legacy, maintained as fallback)

## Tool Categories

### Video Creation (4 tools)
- [x] create_video_from_images - Slideshow creator
- [x] add_watermark_to_video - Brand with watermarks
- [x] generate_video_captions - Auto-generate subtitles
- [x] merge_videos - Combine multiple videos

### Video Editing (3 tools)
- [x] trim_video - Cut video sections
- [x] resize_video_resolution - Change dimensions
- [x] adjust_video_speed - Speed up/slow down

### Video Optimization (3 tools)
- [x] compress_video - Reduce file size
- [x] convert_video_format - Convert formats
- [x] optimize_for_platform - Platform-specific optimization

### Video Analysis (2 tools)
- [x] extract_video_metadata - Get video information
- [x] generate_video_thumbnails - Create thumbnail options

## Implementation Status

**Phase 1 (Foundation)**: ✅ Directory created  
**Phase 6 (Tools)**: ✅ Complete (12/12 tools implemented)  
**Phase 7 (Omni Integration)**: 🔄 Planned — Omni Flash video generation + conversational editing

## Dependencies

- Required NPM packages: ffmpeg-static, ffprobe-static, gif-encoder, video-stitch, subtitle
- Already available: fluent-ffmpeg, sharp
- FFmpeg binary configuration required
