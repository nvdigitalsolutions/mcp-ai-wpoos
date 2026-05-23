# Video Production Toolkit

> Professional video editing and optimization powered by **FFmpeg** plus Remotion for
> React-based programmatic video. AI video generation via **Gemini Omni Flash** (May 2026)
> replaces Veo for multimodal text/image/audio/video → video creation.

| | |
|---|---|
| **Activation setting** | `enable_video_production_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Video Production |
| **Tools** | 13 (FFmpeg) + Omni generation (Planned) |
| **NPM** | `fluent-ffmpeg`, `ffmpeg-static`, `ffprobe-static`, `subtitle` |
| **System requirement** | FFmpeg installed on the host |
| **AI Models** | Gemini Omni Flash (video gen), Veo 3.1/2.0 (legacy fallback) |

---

## Tools

- `create_video_from_images` — slideshow / Ken-Burns videos
- `create_remotion_video` — React-based programmatic video (Remotion)
- `merge_videos`, `trim_video`, `adjust_video_speed`, `resize_video_resolution`
- `convert_video_format`, `compress_video`, `optimize_for_platform`
  (YouTube / TikTok / Instagram presets)
- `extract_video_metadata`, `extract_video_frames`, `generate_video_thumbnails`
- `generate_video_captions` — auto-captions / subtitles via Whisper-style ASR
- `add_watermark_to_video`

Tool source: `addons/pro/includes/tools/video-production/`.

---

## Activation

1. Install FFmpeg on the host (`apt install ffmpeg`, `brew install ffmpeg`, or use
   `ffmpeg-static`).
2. Activate the Pro add-on.
3. Toggle **Video Production** under **NV oOS → Settings → Pro Features**.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/video-production/README.md`](../../includes/tools/video-production/README.md)
