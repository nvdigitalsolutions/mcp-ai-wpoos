# WP oOS - Feature Matrix (Core vs Pro)

This document outlines the features available in the Core plugin versus the Pro addon.

## Overview

| Aspect | Core (Base) | Pro Addon |
|--------|-------------|-----------|
| License | GPL-3.0-or-later | Proprietary |
| Distribution | Included in main plugin | Separate addon plugin |
| Tool Count | 71 tools | 6 additional tools (77 total) |
| External Dependencies | None | FFmpeg, WP-CLI, Python/rembg, Jukebox |
| Support | GitHub issues | Email/helpdesk |
| Updates | WordPress.org | Direct updates |

## Tool Distribution

### Core Tools (71)
All WordPress-native tools that don't require external executables:
- Content management (posts, pages, attachments)
- Media generation (OpenAI images, Gemini images, speech, video)
- Image manipulation (14 graphic editor tools)
- Research & external data
- Cache management
- Communications
- Automation (cron, external actions)
- WooCommerce integration (when WooCommerce active)
- JetEngine integration (when JetEngine active)
- Elementor integration (when Elementor active)

### Pro Tools (6)
Specialized tools requiring external executables (Pro addon required):

| Tool | Executable Required | Purpose |
|------|-------------------|---------|
| `extract_video_frames` | FFmpeg | Extract frames from videos |
| `get_video_metadata` | FFmpeg | Read video file metadata |
| `remove_background` | Python + rembg OR remove.bg API | AI background removal |
| `generate_jukebox_music` | OpenAI Jukebox | Music with vocal synthesis |
| `check_jukebox_status` | OpenAI Jukebox | Check Jukebox installation |
| `check_wp_cli` | WP-CLI | Inspect WP-CLI environment |

## MCP Server Features

| Feature | Core | Pro |
|---------|------|-----|
| MCP Protocol Implementation | ✅ | ✅ |
| REST API Endpoints | ✅ | ✅ |
| Tool Registry | ✅ | ✅ |
| Tool Execution | ✅ | ✅ |
| Basic Authentication | ✅ | ✅ |
| Rate Limiting (Basic) | ✅ | ✅ |
| Rate Limiting (Advanced) | ✅ | ✅ |
| Field-level Permissions | ✅ | ✅ |
| Analytics Dashboard | ✅ | ✅ |
| Exec-Based Tools | ❌ | ✅ |

## Core Tools (71 - Included in Base Plugin)

These tools are available in the base WP oOS plugin without any addons:

### Content Management
| Tool | Description |
|------|-------------|
| `get_recent_posts` | Query recent posts |
| `search_content` | Search across post types |
| `save_post` | Create/update posts |
| `search_attachments` | Search media library |
| `submit_document_prompt` | Upload files with prompts |
| `get_user_info` | Query user information |
| `get_site_summary` | Site metadata summary |

### Media Generation
| Tool | Description |
|------|-------------|
| `generate_openai_image` | OpenAI DALL-E image generation |
| `generate_gemini_image` | Google Gemini image generation |
| `generate_openai_speech` | OpenAI TTS (text-to-speech) |
| `transcribe_openai_audio` | OpenAI Whisper transcription |
| `edit_gemini_image` | Gemini image editing |
| `generate_veo_video` | Google Veo video generation |
| `check_video_status` | Check async video status |
| `generate_music` | Google Gemini music generation |
| `generate_perfume_lifestyle_image` | Specialized product imagery |

### Image Manipulation (14 tools)
| Tool | Description |
|------|-------------|
| `resize_image` | Resize images |
| `crop_image` | Crop images |
| `rotate_image` | Rotate/flip images |
| `flip_image` | Flip images |
| `adjust_brightness` | Adjust image brightness |
| `adjust_contrast` | Adjust image contrast |
| `convert_image_format` | Convert image formats |
| `compress_image` | Compress images |
| `add_watermark` | Add watermarks |
| `blur_image` | Blur images |
| `sharpen_image` | Sharpen images |
| `grayscale_image` | Convert to grayscale |
| `sepia_image` | Apply sepia filter |
| `add_image_border` | Add borders to images |

### Research & External Data
| Tool | Description |
|------|-------------|
| `web_search` | DuckDuckGo/Brave web search |
| `crawl4ai_price_lookup` | Product price lookup |
| `get_gdacs_events` | Global disaster alerts |
| `get_open_meteo_forecast` | Weather forecasts |
| `get_nhc_active_storms` | Hurricane tracking |
| `reliefweb_reports` | Humanitarian reports |
| `get_import_duty` | Import duty calculations |

### Automation & Scheduling
| Tool | Description |
|------|-------------|
| `create_cron_job` | Schedule WordPress cron jobs |
| `list_cron_jobs` | List scheduled jobs |
| `get_cron_job` | Get cron job details |
| `delete_cron_job` | Delete cron jobs |
| `run_openai_external_action` | Execute external actions |
| `run_crawl4ai_job` | Run Crawl4AI scraping jobs |

### Cache Management
| Tool | Description |
|------|-------------|
| `purge_cache` | Purge all caches |
| `purge_cloudflare_cache` | Purge Cloudflare CDN |
| `purge_varnish_cache` | Purge Varnish cache |

### Communications
| Tool | Description |
|------|-------------|
| `send_group_email` | Send emails to user groups |

### Diagnostics
| Tool | Description |
|------|-------------|
| `get_site_health` | WordPress site health |
| `get_environment_status` | Server environment info |
| `get_system_logs` | System logs |
| `get_update_status` | Available updates |
| `probe_chat` | Chat connectivity probe |
| `probe_remote_mcp` | Remote MCP probe |
| `open_openai_usage` | OpenAI usage dashboard |
| `open_openai_logs` | OpenAI logs |

### WooCommerce (When Active)
| Tool | Description |
|------|-------------|
| `create_woo_product` | Create product drafts |
| `get_woo_products` | Query products |
| `get_woo_recent_orders` | Query recent orders |

### JetEngine (When Active)
| Tool | Description |
|------|-------------|
| `get_jetengine_items` | Query CCT items |
| `list_jetengine_routes` | List REST routes |
| `invoke_jetengine_route` | Invoke REST routes |
| `get_jetformbuilder_forms` | Query forms |
| `get_jetformbuilder_submissions` | Query form submissions |

### Elementor (When Active)
| Tool | Description |
|------|-------------|
| `get_elementor_templates` | Query Elementor templates |

### SEO & Code (When Plugins Active)
| Tool | Description |
|------|-------------|
| `get_rankmath_seo` | RankMath SEO analysis |
| `create_wpcode_snippet` | Create WPCode snippets |

### Authentication (When Simple JWT Active)
| Tool | Description |
|------|-------------|
| `generate_simple_jwt_token` | Generate JWT tokens |

## Pro Addon Tools (6 - Requires Pro Plugin + External Executables)

These tools require the Pro addon plugin **and** external executables to be installed on the server:

### Video Processing (FFmpeg Required)

| Tool | Description | Setup |
|------|-------------|-------|
| `extract_video_frames` | Extract frames from video files | Install FFmpeg: `sudo apt install ffmpeg` |
| `get_video_metadata` | Read video metadata (duration, codec, etc.) | Install FFmpeg: `sudo apt install ffmpeg` |

**FFmpeg Installation:**
```bash
# Debian/Ubuntu
sudo apt update
sudo apt install ffmpeg

# Verify installation
ffmpeg -version
```

### Audio Generation (Jukebox Required)

| Tool | Description | Setup |
|------|-------------|-------|
| `generate_jukebox_music` | Generate music with vocals using OpenAI Jukebox | Install Jukebox from OpenAI |
| `check_jukebox_status` | Check Jukebox installation status | Install Jukebox from OpenAI |

**Jukebox Installation:**
```bash
# Requires Python 3.7+, CUDA (for GPU support)
git clone https://github.com/openai/jukebox.git
cd jukebox
pip install -r requirements.txt
pip install -e .
```

### Image Processing (Python/rembg Required)

| Tool | Description | Setup |
|------|-------------|-------|
| `remove_background` | AI-powered background removal | Option 1: Install Python + rembg<br>Option 2: Get remove.bg API key |

**rembg Installation (Free Option):**
```bash
# Requires Python 3.7+
pip install rembg[gpu]  # With GPU support
# OR
pip install rembg       # CPU only

# Verify installation
rembg --help
```

**remove.bg API (Paid Option):**
- Sign up at https://remove.bg/api
- Get API key
- Add to WP oOS settings
- No local installation needed

### WordPress Management (WP-CLI Required)

| Tool | Description | Setup |
|------|-------------|-------|
| `check_wp_cli` | Inspect WP-CLI environment and capabilities | Install WP-CLI |

**WP-CLI Installation:**
```bash
# Download
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# Make executable
chmod +x wp-cli.phar

# Move to PATH
sudo mv wp-cli.phar /usr/local/bin/wp

# Verify installation
wp --info
```

## Capability Flags

Tools use capability flags to indicate requirements and permissions:

### Core Tools
- Most core tools have no special flags
- Some require specific WordPress capabilities (e.g., `manage_options`)
- No external dependencies

### Pro Tools
All Pro tools have the following capability flags:
- `'pro'` - Requires Pro addon to be active
- `'exec'` - Requires external executable on server

Example from tool code:
```php
public function get_capability_flags() {
    return array( 'pro', 'exec' );
}
```

## Installation & Activation

### Core Plugin
1. Install WP oOS from WordPress.org or GitHub
2. Activate plugin
3. Configure OpenAI/Gemini API keys
4. **71 tools ready to use**

### Pro Addon
1. Obtain Pro addon plugin
2. Install and activate Pro addon
3. Install required executables for tools you want:
   - FFmpeg (video tools)
   - WP-CLI (management tools)
   - Python + rembg (background removal)
   - Jukebox (music generation)
4. Configure tools in WP oOS → Tools & Features
5. **6 additional tools available (77 total)**

## Feature Comparison Summary

| Feature Category | Core | Pro | Total |
|-----------------|------|-----|-------|
| Content Management | 7 | 0 | 7 |
| Media Generation | 7 | 0 | 7 |
| Image Manipulation | 14 | 1 | 15 |
| Video Processing | 2 | 2 | 4 |
| Audio Generation | 1 | 2 | 3 |
| Research & Data | 7 | 0 | 7 |
| Automation | 6 | 0 | 6 |
| Cache Management | 3 | 0 | 3 |
| Communications | 1 | 0 | 1 |
| Diagnostics | 8 | 1 | 9 |
| WooCommerce | 3 | 0 | 3 |
| JetEngine | 5 | 0 | 5 |
| Elementor | 1 | 0 | 1 |
| SEO & Code | 2 | 0 | 2 |
| Authentication | 1 | 0 | 1 |
| **TOTAL** | **71** | **6** | **77** |

## Performance Considerations

### Core Tools
- **Memory:** Standard WordPress plugin footprint
- **Processing:** All processing done by WordPress/PHP or cloud APIs
- **Server Load:** Minimal (API calls offload processing)

### Pro Tools
- **Memory:** Higher (FFmpeg, Jukebox use significant RAM)
- **Processing:** Local server processing required
- **Server Load:** High for video/audio processing
- **Storage:** Large model files for Jukebox (~10GB+)
- **GPU:** Recommended for Jukebox and rembg (optional)

### Recommendations
- **Core only:** Suitable for shared hosting
- **Pro addon:** Requires VPS or dedicated server
- **FFmpeg:** Lightweight, works on most servers
- **Jukebox:** Requires powerful server with GPU
- **rembg:** Can use CPU but GPU recommended
- **WP-CLI:** Very lightweight

## Cost Considerations

### Core Tools
- **Plugin:** Free (GPL-3.0)
- **API Costs:**
  - OpenAI API (pay-per-use)
  - Google Gemini API (pay-per-use)
  - Ollama/LM Studio (free, local AI)
- **Hosting:** Standard WordPress hosting

### Pro Tools
- **Plugin:** Commercial license required
- **Executables:** All open-source and free
- **Hosting:** VPS/dedicated server recommended
- **API Costs (Optional):**
  - remove.bg API (paid alternative to rembg)

## Migration & Compatibility

### Upgrading from Core to Pro
1. Install Pro addon
2. Activate Pro addon
3. Install executables as needed
4. No data migration required
5. All core tools remain available
6. Pro tools appear automatically when executables detected

### Downgrading from Pro to Core
1. Deactivate Pro addon
2. Pro tools become unavailable
3. Core tools continue working normally
4. No data loss
5. Executables can remain installed or be removed
