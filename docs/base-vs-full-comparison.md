# Base Version vs. Full Version Comparison

This document provides a detailed comparison between the Base Version and Full Version of WP oOS.

## Quick Comparison

| Feature | Base Version | Full Version (with Pro Addon) |
|---------|-------------|-------------------------------|
| **Setup Complexity** | Simple - WordPress only | Advanced - requires Pro addon for exec tools |
| **Third-Party Plugins Required** | None | WooCommerce, JetEngine, etc. (optional) |
| **External Executables Required** | None | FFmpeg, WP-CLI, Python/rembg, Jukebox (for Pro tools) |
| **External APIs Required** | Only OpenAI/Gemini | Many (Google, Social Media, etc.) |
| **Total Tools** | 71 | 77 |
| **Core Tools** | 71 | 71 |
| **Pro Tools** | 0 | 6 (exec-based) |
| **Memory Footprint** | Lower | Higher |
| **Best For** | Testing, development, simple sites | Production, video editing, advanced media |

## Tool Comparison by Category

### Content Management & Search

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get Recent Posts | ✅ | ✅ | WordPress core |
| Search Content | ✅ | ✅ | WordPress core |
| Search Attachments | ✅ | ✅ | WordPress core |
| Get User Info | ✅ | ✅ | WordPress core |
| Save Post | ✅ | ✅ | WordPress core |
| Submit Document Prompt | ✅ | ✅ | WordPress core |
| Get Elementor Templates | ❌ | ✅ | Elementor plugin |
| Get RankMath SEO | ❌ | ✅ | RankMath plugin |
| Create WPCode Snippet | ❌ | ✅ | WPCode plugin |

### Media Generation & Transcription

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Generate OpenAI Image | ✅ | ✅ | OpenAI API key |
| Generate Gemini Image | ✅ | ✅ | Gemini API key |
| Generate OpenAI Speech | ✅ | ✅ | OpenAI API key |
| Transcribe OpenAI Audio | ✅ | ✅ | OpenAI API key |
| Generate Perfume Lifestyle Image | ✅ | ✅ | OpenAI API key |
| Edit Gemini Image | ✅ | ✅ | Gemini API key |
| Generate Veo Video | ✅ | ✅ | Gemini API key |
| Check Video Status | ✅ | ✅ | Gemini API key |
| Generate Music | ✅ | ✅ | Gemini API key |
| **Extract Video Frames** | ❌ | ✅ | **Pro addon + FFmpeg** |
| **Get Video Metadata** | ❌ | ✅ | **Pro addon + FFmpeg** |
| **Generate Jukebox Music** | ❌ | ✅ | **Pro addon + Jukebox** |
| **Check Jukebox Status** | ❌ | ✅ | **Pro addon + Jukebox** |

### Image Manipulation (Graphic Editor Suite)

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Resize Image | ✅ | ✅ | WordPress core |
| Crop Image | ✅ | ✅ | WordPress core |
| Rotate Image | ✅ | ✅ | WordPress core |
| Flip Image | ✅ | ✅ | WordPress core |
| Adjust Brightness | ✅ | ✅ | WordPress core |
| Adjust Contrast | ✅ | ✅ | WordPress core |
| Convert Image Format | ✅ | ✅ | WordPress core |
| Compress Image | ✅ | ✅ | WordPress core |
| Add Watermark | ✅ | ✅ | WordPress core |
| Blur Image | ✅ | ✅ | WordPress core |
| Sharpen Image | ✅ | ✅ | WordPress core |
| Grayscale Image | ✅ | ✅ | WordPress core |
| Sepia Image | ✅ | ✅ | WordPress core |
| Add Image Border | ✅ | ✅ | WordPress core |
| **Remove Background** | ❌ | ✅ | **Pro addon + Python/rembg OR remove.bg API** |

### Research & External Data

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Web Search | ✅ | ✅ | WordPress core |
| Crawl4AI Price Lookup | ✅ | ✅ | WordPress core |
| Get GDACS Events | ✅ | ✅ | WordPress core |
| Get Open-Meteo Forecast | ✅ | ✅ | WordPress core |
| Get NHC Active Storms | ✅ | ✅ | WordPress core |
| ReliefWeb Reports | ✅ | ✅ | WordPress core |
| Get Import Duty | ✅ | ✅ | WordPress core |
| Vision Product Search | ❌ | ✅ | Google Cloud Vision API |
| Vision Object Localization | ❌ | ✅ | Google Cloud Vision API |

### Operations & Diagnostics

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get Site Summary | ✅ | ✅ | WordPress core |
| Get Site Health | ✅ | ✅ | WordPress core |
| Get Environment Status | ✅ | ✅ | WordPress core |
| Get System Logs | ✅ | ✅ | WordPress core |
| Get Update Status | ✅ | ✅ | WordPress core |
| Create Cron Job | ✅ | ✅ | WordPress core |
| List Cron Jobs | ✅ | ✅ | WordPress core |
| Get Cron Job | ✅ | ✅ | WordPress core |
| Delete Cron Job | ✅ | ✅ | WordPress core |
| Probe Chat | ✅ | ✅ | WordPress core |
| Probe Remote MCP | ✅ | ✅ | WordPress core |
| Open OpenAI Usage | ✅ | ✅ | WordPress core |
| Open OpenAI Logs | ✅ | ✅ | WordPress core |
| **Check WP-CLI** | ❌ | ✅ | **Pro addon + WP-CLI** |

### Automation

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Run OpenAI External Action | ✅ | ✅ | OpenAI API key |
| Run Crawl4AI Job | ✅ | ✅ | WordPress core |
| Create Google Calendar Event | ❌ | ✅ | Google Calendar API |
| Search Gmail | ❌ | ✅ | Gmail API |

### Cache Management

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Purge Cache | ✅ | ✅ | WordPress core |
| Purge Cloudflare Cache | ✅ | ✅ | Cloudflare API (optional) |
| Purge Varnish Cache | ✅ | ✅ | Varnish (optional) |

### Communication

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Send Group Email | ✅ | ✅ | WordPress wp_mail() |
| Send Mailjet Email | ❌ | ✅ | Mailjet API |
| Send Telegram Message | ❌ | ✅ | Telegram Bot API |
| Send WhatsApp Message | ❌ | ✅ | WhatsApp Cloud API |
| Schedule Notify SMS | ❌ | ✅ | Notify.lk API |

### Commerce & Finance

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Create WooCommerce Product | ❌ | ✅ | WooCommerce plugin |
| Get WooCommerce Products | ❌ | ✅ | WooCommerce plugin |
| Get WooCommerce Orders | ❌ | ✅ | WooCommerce plugin |
| QuickBooks Report | ❌ | ✅ | QuickBooks Online API |

### Social Media

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Post Facebook/Instagram | ❌ | ✅ | Meta Graph API |
| Get Facebook/Instagram Insights | ❌ | ✅ | Meta Graph API |
| Post TikTok Video | ❌ | ✅ | TikTok Open API |
| Get TikTok Insights | ❌ | ✅ | TikTok Open API |
| Post LinkedIn Update | ❌ | ✅ | LinkedIn Marketing API |
| Get LinkedIn Insights | ❌ | ✅ | LinkedIn Marketing API |
| Post Google Business Update | ❌ | ✅ | Google Business API |
| Get Google Business Insights | ❌ | ✅ | Google Business API |

### Analytics

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Google Analytics Report | ❌ | ✅ | Google Analytics 4 API |

### JetEngine/JetFormBuilder

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get JetEngine Items | ❌ | ✅ | JetEngine plugin |
| List JetEngine Routes | ❌ | ✅ | JetEngine plugin |
| Invoke JetEngine Route | ❌ | ✅ | JetEngine plugin |
| Get JetFormBuilder Forms | ❌ | ✅ | JetFormBuilder plugin |
| Get JetFormBuilder Submissions | ❌ | ✅ | JetFormBuilder plugin |

### Authentication

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Generate Simple JWT Token | ❌ | ✅ | Simple JWT Login plugin |

## Integration Support

### Base Version
- ✅ WordPress Core (71 tools)
- ✅ OpenAI API
- ✅ Gemini API
- ✅ Ollama (local AI)
- ✅ LM Studio (local AI)

### Full Version (Base + Pro Addon)
- ✅ All Base Version integrations (71 tools)
- ✅ **Pro Addon - Exec Service Tools** (6 tools):
  - FFmpeg (video frame extraction, metadata)
  - WP-CLI (environment inspection)
  - Python rembg (background removal)
  - OpenAI Jukebox (music generation with vocals)
- ✅ WooCommerce
- ✅ JetEngine
- ✅ JetFormBuilder
- ✅ Elementor
- ✅ RankMath SEO
- ✅ WPCode
- ✅ ChatKit
- ✅ Simple JWT Login
- ✅ All external APIs listed above

## Configuration Requirements

### Base Version (Default)
1. WordPress 6.0+ with PHP 7.4+
2. OpenAI API key OR Gemini API key OR Ollama/LM Studio
3. **71 tools included** - No additional configuration needed

### Full Version (Base + Pro Addon)
1. WordPress 6.0+ with PHP 7.4+
2. OpenAI API key OR Gemini API key OR Ollama/LM Studio
3. **Activate Pro addon** for 6 additional exec-based tools
4. Install external executables for Pro tools:
   - FFmpeg (for video frame extraction and metadata)
   - WP-CLI (for environment inspection)
   - Python + rembg library (for background removal)
   - OpenAI Jukebox (for music generation with vocals)
5. Optional: WooCommerce, JetEngine, Elementor, etc.
6. Optional: External API keys for services you want to use

## Use Cases

### When to Use Base Version

✅ **Perfect for:**
- Learning and experimenting with AI
- Development and testing environments
- Simple blogs and content sites
- Sites without video/audio processing needs
- Users who want minimal setup
- Privacy-conscious deployments (local AI only)
- Budget-conscious projects
- Most WordPress sites (71 tools cover common use cases)

❌ **Not ideal for:**
- Video editing and frame extraction workflows
- Advanced audio generation with vocals (Jukebox)
- Python-based image processing (rembg)
- WP-CLI automation scripts

### When to Use Full Version (with Pro Addon)

✅ **Perfect for:**
- Video production and editing sites
- Media agencies requiring frame extraction
- Music generation with vocal synthesis
- Advanced image processing (background removal)
- Development environments needing WP-CLI inspection
- Sites with complex media processing workflows
- Professional content creators

❌ **Not needed for:**
- Simple blogs
- Standard content sites
- Sites without exec-based tool requirements
- Users wanting simplicity
- Environments where installing FFmpeg/Python/Jukebox is not feasible

## Migration Path

### From Base to Full (Adding Pro Addon)

1. Install the Pro addon plugin
2. Activate the Pro addon
3. Install required executables for tools you want:
   - `sudo apt install ffmpeg` (for video tools)
   - `sudo apt install wp-cli` (for WP-CLI tool)
   - Install Python + rembg (for background removal)
   - Install OpenAI Jukebox (for music generation)
4. The additional 6 Pro tools will automatically become available
5. Configure tools in WP oOS → Tools & Features

### From Full to Base (Removing Pro Addon)

1. Deactivate the Pro addon
2. The 6 Pro tools will be hidden from assistants
3. External executables can remain installed or be removed
4. No data is lost - reactivating Pro addon restores everything

## Performance Impact

### Base Version
- **Memory Usage**: Standard WordPress plugin footprint
- **Load Time**: Faster (71 core tools)
- **Admin Complexity**: Streamlined (no exec-based tools)
- **Server Requirements**: WordPress + PHP only

### Full Version (with Pro Addon)
- **Memory Usage**: Slightly higher (6 additional tools)
- **Load Time**: Minimal impact (Pro tools lazy-load)
- **Admin Complexity**: More options (77 total tools)
- **Server Requirements**: WordPress + PHP + external executables

## Recommended Approach

1. **Start with Base Version** - 71 tools cover most WordPress use cases
2. **Add Pro Addon** only if you need exec-based media processing
3. **Install Executables** only for tools you'll actually use
4. **Test First** - Verify FFmpeg/Python/Jukebox work before relying on them

## Support and Documentation

- Base Version Guide: [BASE-VERSION.md](../BASE-VERSION.md)
- Full Documentation: [README.md](../README.md)
- Tool Reference: [docs/tool-reference.md](tool-reference.md)
- Pro Tool Setup: See individual tool documentation
- GitHub Issues: Report problems or request features

## Summary

The **Base Version** provides a comprehensive, WordPress-native experience with **71 essential tools**, covering content management, media generation, research, automation, caching, communications, and more.

The **Pro Addon** adds **6 specialized tools** that require external executables:
- **FFmpeg** - Video frame extraction and metadata
- **WP-CLI** - Environment inspection and automation
- **Python/rembg** - AI-powered background removal
- **Jukebox** - Music generation with vocal synthesis

**Total: 77 tools** when both are active.

Choose **Base Version** for standard WordPress AI features. Add **Pro Addon** only if you need exec-based media processing capabilities.
