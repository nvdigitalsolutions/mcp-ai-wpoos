# Built-in tool reference

**Status:** ✅ UPDATED - September 2026
**Tool Count:** ~303 base tools + ~1,262 Pro tools = ~1,565 total (live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
**Last Updated:** September 3, 2026

NV oOS registers a suite of default tools through the central registry so every assistant can opt-in without custom code. The registry initialises on `plugins_loaded`, loads the bundled implementations, and exposes extension hooks for third parties to add their own integrations.【F:includes/class-wp-mcp-ai-tool-registry.php†L12-L124】【F:includes/tools/tools-init.php†L12-L14】

## Autonomous automation capabilities for AI agents

### Cron Management: From reactive to proactive AI

The **Cron Management Suite** (`create_cron_job`, `list_cron_jobs`, `get_cron_job`, `delete_cron_job`) fundamentally transforms how AI assistants interact with WordPress by enabling autonomous task scheduling and background automation. Instead of only responding to user requests in real-time, agents can now:

**Schedule future actions independently**
- Agents can plan multi-step workflows that execute over hours or days
- Content publishing can be scheduled for optimal engagement times discovered through analytics
- Maintenance tasks can be automated to run during off-peak hours
- Recurring operations (daily reports, weekly cleanups, periodic syncs) can be established once and run indefinitely

**Monitor and manage scheduled automation**
- Agents maintain visibility into all scheduled background tasks through `list_cron_jobs`
- Detailed inspection of individual jobs via `get_cron_job` enables troubleshooting and verification
- Stale or unnecessary automation can be identified and removed with `delete_cron_job`
- Full audit trails (creator, creation time, next run time) support accountability and debugging

**Coordinate complex workflows**
- Agents can chain operations: schedule a post to publish at 9 AM, then schedule cache invalidation for 9:05 AM
- Background data processing can be scheduled to avoid interrupting user-facing operations
- Integration with external systems can be coordinated through scheduled API calls
- Recurring business processes (invoicing, reporting, backups) can be fully automated

**Technical safeguards**
All cron operations require `manage_options` capability, ensuring only authorized administrators can delegate scheduling authority to agents. The cron manager (`WP_MCP_AI_Cron_Manager`) maintains a dedicated tracking database separate from WordPress's native cron array, providing:
- Unique job IDs for reliable management across recurring schedules
- Automatic pruning of completed single-run jobs
- Normalized argument handling to prevent duplicate scheduling
- User attribution for accountability and audit trails

### Cache management: Multi-layer invalidation orchestration

The cache management tools (`purge_cache`, `purge_cloudflare_cache`, `purge_varnish_cache`) enable agents to coordinate content freshness across distributed caching layers:

**Intelligent cache invalidation**
- `purge_cache` master orchestrator detects active caching layers and executes purges in the correct sequence
- Cloudflare CDN edge caches can be cleared globally or for specific URLs/tags
- Varnish reverse proxy caches can be purged for immediate server-side updates
- WordPress object and page caches are automatically cleared when configured

**Integration with content workflows**
Agents can chain cache purging with content operations:
1. Agent updates a high-traffic page via `save_post`
2. Agent immediately calls `purge_cache` to ensure changes are visible
3. Agent schedules a follow-up cache warming task via `create_cron_job` for 5 minutes later

This is particularly powerful for publishing workflows where content updates must be immediately visible across global CDN edge nodes and server-side caching layers.

### Authentication tooling: Enabling headless integrations

**Generate Simple JWT Token** (`generate_simple_jwt_token`) empowers agents to facilitate headless WordPress integrations by generating bearer tokens on behalf of authenticated users. Agents can:
- Help users configure mobile apps or SPAs with time-limited authentication tokens
- Generate API credentials for third-party integrations without exposing passwords
- Facilitate testing and debugging of headless WordPress APIs
- Support multi-device authentication workflows where users need tokens for different contexts

The tool validates Simple JWT Login configuration (plugin active, authentication enabled, valid keys) before generating tokens, ensuring secure token issuance.

## Content ingestion and retrieval

- **Submit Document Prompt** (`submit_document_prompt`) uploads one or more WordPress attachments or existing OpenAI file IDs alongside a follow-up instruction so models can reason over the supplied files. The tool validates that a prompt and at least one document were provided before assembling multimodal segments for the Responses API.【F:includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php†L20-L214】
- **Search Content** (`search_content`) performs keyword-based lookups across any public post type and supports optional taxonomy or post meta filters before returning structured post metadata. The executor enforces the `read` capability, multisite membership, and sanitises every filter before passing arguments to `WP_Query`.【F:includes/tools/class-wp-mcp-ai-tool-search-content.php†L12-L280】
- **Get Recent Posts** (`get_recent_posts`) returns the latest published entries for a given post type with titles, permalinks, excerpts, and publication timestamps. The executor enforces that the acting user can `read` content on the current site before performing the query.【F:includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php†L12-L104】
- **Get Elementor Templates** (`get_elementor_templates`) surfaces the Elementor template library with status, template type, and edit links so assistants can summarise theme assets. It requires Elementor (free or Pro) to be active and the caller to have the Elementor library edit capability before returning results.【F:includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php†L12-L239】
- **Get JetEngine Items** (`get_jetengine_items`) surfaces JetEngine managed post types when the plugin is available. It requires the caller to be able to `read` and satisfy the target post type’s `edit_posts` capability before returning item metadata.【F:includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php†L12-L118】
- **Get JetFormBuilder Forms** (`get_jetformbuilder_forms`) proxies JetFormBuilder’s REST controllers to return paginated form metadata, supports status/search filters, honours capability checks, and automatically falls back between REST and HTTP transports based on route availability.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php†L15-L155】【F:includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php†L33-L200】
- **Get Recent WooCommerce Orders** (`get_woo_recent_orders`) summarises recent orders with totals, billing details, and ISO timestamps. The helper only activates when WooCommerce is loaded and restricts access to users who can manage or report on orders.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php†L12-L117】
- **Get WooCommerce Products** (`get_woo_products`) exposes catalog listings with pricing, stock, and publish metadata. It honours optional SKU, status, and stock status filters while enforcing the same WooCommerce capabilities as the orders helper.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-products.php†L12-L140】
- **Get Site Summary** (`get_site_summary`) captures the site name, description, URL, admin email, and basic content/user counts. Because the payload exposes administrative metadata, the tool requires `manage_options` access.【F:includes/tools/class-wp-mcp-ai-tool-get-site-summary.php†L12-L66】
- **Get User Information** (`get_user_info`) inspects profile data for the requested user ID (defaulting to the acting user) while respecting multisite membership and the `list_users` / `manage_options` capability checks when viewing other accounts.【F:includes/tools/class-wp-mcp-ai-tool-get-user-info.php†L12-L89】
- **Get JetFormBuilder Submissions** (`get_jetformbuilder_submissions`) lists recent entries for a specific form, enforces JetFormBuilder’s records capability, normalises field snapshots, and shares the same transport handling and error normalisation as the forms endpoint.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php†L15-L154】【F:includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php†L33-L200】
- **Search Attachments** (`search_attachments`) scans the Media Library with optional keyword or MIME filters and returns download URLs for files that pass `WP_MCP_AI_Message_Attachments::user_can_access_attachment()`, keeping private knowledge assets hidden from unauthorised requests.【F:includes/tools/class-wp-mcp-ai-tool-search-attachments.php†L15-L207】【F:includes/class-wp-mcp-ai-message-attachments.php†L480-L575】
- **Create or Update Post** (`save_post`) creates new posts or updates existing entries with capability-aware validation, Gutenberg block normalisation, slug overrides, and edit links so assistants can collaborate on publishing workflows safely.【F:includes/tools/class-wp-mcp-ai-tool-save-post.php†L15-L268】
- **Create WooCommerce Product Draft** (`create_woo_product`) builds draft WooCommerce products from merchandising data, applying brand metadata, pricing, descriptions, and optional sideloaded imagery before saving the product for further editing.【F:includes/tools/class-wp-mcp-ai-tool-create-woo-product.php†L15-L258】
- **Get Post** (`get_post`) retrieves a single WordPress post by ID, including its content, metadata, and taxonomy terms. Requires `read` capability; enforces multisite membership.【F:includes/tools/class-wp-mcp-ai-tool-get-post.php†L17-L150】
- **Delete Post** (`delete_post`) deletes a WordPress post by ID. By default moves the post to the trash; set `force_delete` to `true` to permanently remove it. Requires `delete_posts` capability.【F:includes/tools/class-wp-mcp-ai-tool-delete-post.php†L17-L150】
- **Get Post Type Schema** (`get_post_type_schema`) returns the schema of a registered WordPress post type: labels, capabilities, supported features, registered taxonomies, available statuses, and (when the Pro addon is active) the custom meta field definitions used by each Pro CPT toolkit.【F:includes/tools/class-wp-mcp-ai-tool-get-post-type-schema.php†L17-L200】
- **Create Term** (`create_term`) creates a new taxonomy term (category, tag, or custom taxonomy) with optional parent, description, and metadata. Supports hierarchical taxonomies and term metadata.【F:includes/tools/class-wp-mcp-ai-tool-create-term.php†L17-L150】
- **Update Term** (`update_term`) updates an existing taxonomy term with new properties, parent relationships, and metadata.【F:includes/tools/class-wp-mcp-ai-tool-update-term.php†L17-L150】
- **List Terms** (`list_terms`) lists terms in a taxonomy (categories, tags, or custom taxonomies) with IDs, names, parents, counts, and links. Supports search, pagination, hide-empty, and parent filtering. Read-only discovery companion to `create_term`/`update_term`.【F:includes/tools/class-wp-mcp-ai-tool-list-terms.php†L17-L240】
- **List Taxonomies** (`list_taxonomies`) lists registered taxonomies with labels, hierarchy, visibility, REST bases, and object types. Optional filters for `public` and `object_type`. Read-only discovery companion for taxonomy work.【F:includes/tools/class-wp-mcp-ai-tool-list-taxonomies.php†L17-L200】
- **Get Rank Math SEO Overview** (`get_rankmath_seo`) inspects Rank Math SEO settings for a post, returning focus keywords, SEO scores, robots directives, schema configuration, and accessibility helpers when the Rank Math plugin is active. **Pro Enhancement**: When Rank Math Pro is installed, automatically includes Content AI suggestions, Analytics data (impressions, clicks, CTR, position, top keywords), Link Counter (internal/external links), Image SEO scores, enhanced Video Schema, Local SEO data, and advanced Schema Templates. The tool detects Pro availability and enriches the response with `pro_features` object containing all Pro-specific data without requiring any configuration changes.【F:includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php†L15-L489】

## Media generation and transcription

- **Generate OpenAI Image** (`generate_openai_image`) calls the Images API with configurable defaults (model, size, quality, response format), saves the binary to the Media Library, and lets assistants override prompt, size, quality, timeout, and filename options per request. GPT-Image-1 now forwards the configured response format so assistants can request hosted URLs instead of base64 payloads without extra filters.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L1177】【F:includes/class-wp-mcp-ai-openai-client.php†L16-L33】
- **Generate Gemini Image** (`generate_gemini_image`) renders images with Google’s Gemini multimodal endpoint, supporting aspect-ratio and MIME controls plus optional timeout overrides before saving the attachment to the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php†L17-L200】
- **Generate Sora Video** (`generate_sora_video`) generates videos from text prompts using OpenAI's Sora 2 models (sora-2 and sora-2-pro). Supports configurable resolution (480p-1080p), duration (5-60 seconds), FPS (24/30/60), and aspect ratios (16:9, 9:16, 1:1). Automatically uses async execution for long-running video generation (5-10 minutes). Saves results to the Media Library with full metadata tracking. Pricing: $0.10/second for sora-2, $0.20/second for sora-2-pro.
- **Generate OpenAI Speech** (`generate_openai_speech`) converts supplied text into audio, honouring the default speech model, voice, and format configured in the settings screen while allowing overrides and enforcing authenticated access.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L983-L1110】
- **Transcribe OpenAI Audio** (`transcribe_openai_audio`) accepts uploaded audio attachments up to 25 MB, forwards them to OpenAI for transcription or translation, and returns structured responses with language, duration, and segment data.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】
- **Edit Gemini Image** (`edit_gemini_image`) edits an existing image using Gemini Nano Banana (text + image-to-image) and stores the result in the Media Library, enabling AI-powered image modifications.【F:includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php†L17-L200】
- **Generate Veo Video** (`generate_veo_video`) generates realistic videos from text descriptions using Google's Veo models. Automatically uses Veo 3.1 (preferred) with fallback to Veo 2.0 if quota limits are reached. Supports async mode for long-running video generation tasks.【F:includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php†L17-L300】
- **Check Video Status** (`check_video_status`) checks the status of an async video generation job. Use this to poll for completion after calling generate_veo_video in async mode.【F:includes/tools/class-wp-mcp-ai-tool-check-video-status.php†L17-L150】
- **Generate Music** (`generate_music`) generates instrumental music from a text description using Google Gemini Lyria model with controls for genre, mood, duration, and tempo, and saves the result to the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-music.php†L17-L200】
- **Edit OpenAI Image** (`edit_openai_image`) edits an existing image using OpenAI's DALL-E image editing API. Accepts an image attachment ID and an optional mask attachment ID to specify which areas to modify, then saves the result to the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php†L17-L200】
- **Create Image Variation** (`create_image_variation`) creates variations of an existing image using OpenAI's DALL-E API. Accepts a source image attachment ID and returns one or more variations saved to the Media Library. Useful for generating alternative versions of logos, illustrations, or product shots.【F:includes/tools/class-wp-mcp-ai-tool-create-image-variation.php†L17-L200】
- **Generate Cloudflare AI Image** (`generate_cloudflareai_image`) creates an image with Cloudflare Workers AI using any of the configured Workers AI image-generation models and stores the result in the Media Library. No OpenAI or Gemini key required.【F:includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php†L17-L200】
- **Generate Architectural Drawing** (`generate_architectural_drawing`) **[PRO]** creates professional architectural drawings for construction and design projects. Supports 10 drawing types (floor_plan, elevation, section, detail, site_plan, reflected_ceiling_plan, roof_plan, 3d_axonometric, isometric, construction_detail) and 6 presentation styles (technical, sketched, rendered, line_drawing, annotated, schematic). Includes dimensional specifications (width, depth, height), architectural scale notation (1/4"=1'-0", 1:100, 1:50), material lists and callouts, and building code compliance (IBC, IRC, NBC, Eurocode). Dual AI providers (OpenAI DALL-E/GPT-Image-1.5 and Gemini) with automatic SVG vectorization using the vectorize_image tool. Perfect for architects, structural engineers, and construction professionals needing technical drawings with accurate dimensions and material specifications.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php†L1-L1136】

## Content Safety & Moderation

- **Moderate Content** (`moderate_content`) analyzes text or images for potentially harmful content using OpenAI's Moderation API. Checks for violations across 14 categories including sexual content, hate speech, harassment, self-harm, violence, and illicit content. Returns detailed results with confidence scores (0-1) for each category and supports batch processing of multiple inputs. The moderation API is free to use and supports both `omni-moderation-latest` (multimodal: text + images) and `text-moderation-latest` models. Results include a safety summary with flagged categories and actionable recommendations for content review.【F:includes/tools/class-wp-mcp-ai-tool-moderate-content.php†L17-L350】【F:includes/class-wp-mcp-ai-openai-client.php†L1056-L1230】

## Pro Addon Tools (Exec-Based)

The following tools require the **Pro addon** and external executables to be installed on the server. These tools are not available in the base version.

### Video Processing (FFmpeg Required)

- **Extract Video Frames** (`extract_video_frames`) **[PRO]** extracts individual frames from a video file using FFmpeg. Supports frame selection by time or frame number, quality control, and automatic Media Library integration. Requires FFmpeg to be installed on the server.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php†L17-L250】
- **Get Video Metadata** (`get_video_metadata`) **[PRO]** reads comprehensive metadata from video files using FFmpeg, including duration, resolution, codec information, bitrate, and frame rate. Returns structured JSON data for further processing. Requires FFmpeg to be installed on the server.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-get-video-metadata.php†L17-L150】

### Audio Generation (Jukebox Required)

- **Generate Jukebox Music** (`generate_jukebox_music`) **[PRO]** generates music with vocals from a text description using locally-installed OpenAI Jukebox model. Supports artist style emulation, genre specification, and custom lyrics. Requires Jukebox installation on the server. Saves the result to the Media Library.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php†L17-L450】
- **Check Jukebox Status** (`check_jukebox_status`) **[PRO]** checks if OpenAI Jukebox is installed and properly configured on the server. Returns installation status, Python path, Jukebox installation path, and setup instructions if not installed.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-check-jukebox-status.php†L17-L130】

### Image Processing (Python/rembg Required)

- **Remove Background** (`remove_background`) **[PRO]** removes the background from an image using either the free Python rembg library (local processing) or the paid remove.bg API (cloud processing). Saves the result to the Media Library. Requires Python + rembg library for free mode, or remove.bg API key for paid mode.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-remove-background.php†L17-L250】

### WordPress Management (WP-CLI Required)

- **Check WP-CLI** (`check_wp_cli`) **[PRO]** inspects the WP-CLI environment, checking installation status, version, and available commands. Useful for automation and diagnostics. Requires WP-CLI to be installed on the server.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php†L17-L130】


## Image manipulation (Graphic Editor Suite)

- **Vectorize Image** (`vectorize_image`) converts raster images (PNG, JPEG, WebP, GIF) to SVG vector format with configurable quality settings. Uses @neplex/vectorizer library for high-quality vectorization with controls for color mode (color/binary), color precision (1-8), speckle filtering (0-100), path simplification mode (spline/polygon/none), and hierarchical layer stacking (stacked/cutout). Requires Node.js 14+ to be installed on the server. Processes images locally without external APIs. Perfect for converting logos, icons, and graphics to scalable vector format. Returns SVG file saved to WordPress Media Library with detailed conversion metrics (file size ratio, processing duration).【F:includes/tools/class-wp-mcp-ai-tool-vectorize-image.php†L1-L430】
- **Graphic Editor Plus** (`graphic_editor_plus`) comprehensive graphic editing tool combining local operations (logo overlay, smart resize, canvas expansion) and AI-powered features (style transfer, background removal, intelligent enhancement). **Local operations** include: add_logo (overlay logo with positioning and transparency), resize_graphic (smart resize with format conversion), expand_scene (canvas expansion with background color). **AI operations** powered by Gemini include: ai_enhance (AI-powered photo enhancement), ai_style (change image style to watercolor, sketch, etc.), ai_background (remove or change background), ai_retouch (general AI-powered retouching). Use local operations for speed and precision, AI operations for intelligent transformations. Supports multiple image formats and aspect ratios.【F:includes/tools/class-wp-mcp-ai-tool-graphic-editor-plus.php†L1-L784】
- **Resize Image** (`resize_image`) resizes an image to specific dimensions or scales proportionally while maintaining aspect ratio. Supports width, height, and percentage-based scaling.【F:includes/tools/class-wp-mcp-ai-tool-resize-image.php†L17-L150】
- **Crop Image** (`crop_image`) crops an image to a specific region defined by coordinates and dimensions, or to a target aspect ratio.【F:includes/tools/class-wp-mcp-ai-tool-crop-image.php†L17-L150】
- **Rotate Image** (`rotate_image`) rotates an image by degrees or flips it horizontally/vertically.【F:includes/tools/class-wp-mcp-ai-tool-rotate-image.php†L17-L150】
- **Convert Image Format** (`convert_image_format`) converts an image to a different format (PNG, JPEG, WebP, GIF) with optional quality control.【F:includes/tools/class-wp-mcp-ai-tool-convert-image-format.php†L17-L150】
- **Remove Background** (`remove_background`) removes the background from an image, making it transparent. Supports two methods: free (Python rembg library, requires `pip3 install rembg pillow`) and paid (remove.bg API, requires API key from https://www.remove.bg/api). The `method` parameter allows selection: "auto" (default, tries free first then paid), "free" (rembg only), or "paid" (remove.bg API only). Automatically detects Python availability and rembg installation status. When using the free method, requires Python 3.x and the rembg package installed on the server. The paid method requires a remove.bg API key configured in Settings → NV oOS → Tools → External Tools.【F:includes/tools/class-wp-mcp-ai-tool-remove-background.php†L17-L370】

## Architectural & Construction Tools

### Professional Drawing Generation

**Generate Architectural Drawing** (`generate_architectural_drawing`) **[PRO]** is a comprehensive tool for creating professional architectural and construction drawings using AI. Designed specifically for architects, structural engineers, construction managers, and design professionals.

**Key Features:**
- **10 Drawing Types**: floor_plan, elevation, section, detail, site_plan, reflected_ceiling_plan, roof_plan, 3d_axonometric, isometric, construction_detail
- **6 Presentation Styles**: technical (precise line weights, architectural symbols), sketched (hand-drawn style), rendered (realistic with materials/lighting), line_drawing (clean uniform lines), annotated (extensive dimensions and callouts), schematic (simplified diagrams)
- **Dimensional Specifications**: Width, depth, height with units (feet, meters, inches, centimeters)
- **Architectural Scale Notation**: 1/4"=1'-0", 1:100, 1:50, and custom scales
- **Material Lists**: Specify materials and finishes for automatic callouts
- **Building Code Compliance**: IBC (International Building Code), IRC (International Residential Code), NBC (National Building Code), Eurocode standards
- **Annotation Controls**: Dimension lines, measurement annotations, material callouts
- **Dual AI Providers**: OpenAI (DALL-E, GPT-Image-1.5) or Gemini (gemini-2.5-flash-image)
- **Output Formats**: PNG (raster) or SVG (vector) with automatic vectorization via `vectorize_image` tool
- **Quality Controls**: High-quality output optimized for architectural documentation

**Use Cases:**
- Residential floor plans with room layouts and square footage
- Commercial building elevations with material specifications
- Construction details showing assembly methods and connections
- Site plans with property boundaries and landscaping
- Reflected ceiling plans for lighting and HVAC coordination
- Isometric views for client presentations
- Technical sections for building permit submissions

**Integration:**
- Automatically uses `vectorize_image` for SVG output when requested
- Follows same pattern as `graphic_editor_plus` for consistency
- Supports both raster and vector outputs for different use cases
- Professional metadata storage for project documentation

Perfect for construction workflows where accurate dimensions, code compliance, and professional presentation are essential.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php†L1-L1136】

## AI-powered media analysis

- **Generate Image Alt Text** (`generate_image_alt_text`) generates descriptive alt text for images to improve accessibility and SEO using AI vision capabilities.【F:includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text.php†L17-L150】
- **Generate Image Caption** (`generate_image_caption`) generates detailed captions for images to provide context and enhance content using AI vision capabilities.【F:includes/tools/class-wp-mcp-ai-tool-generate-image-caption.php†L17-L150】
- **Analyze Video** (`analyze_video`) analyzes video content to extract information, describe scenes, identify objects, and provide insights using AI vision models with video understanding capabilities.【F:includes/tools/class-wp-mcp-ai-tool-analyze-video.php†L17-L200】
- **Generate Video Caption** (`generate_video_caption`) generates concise, descriptive captions for videos to provide context and enhance accessibility using AI vision models.【F:includes/tools/class-wp-mcp-ai-tool-generate-video-caption.php†L17-L150】
- **Extract Video Frames** (`extract_video_frames`) extracts specific frames from a video file at given timestamps or intervals. Useful for detailed analysis of specific moments or creating thumbnails.【F:includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php†L17-L150】
- **Get Video Metadata** (`get_video_metadata`) retrieves detailed technical metadata about a video file including duration, dimensions, format, codecs, bitrate, and frame rate.【F:includes/tools/class-wp-mcp-ai-tool-get-video-metadata.php†L17-L150】
- **Analyze Comment Content** (`analyze_comment_content`) analyzes comment content for spam, toxicity, and moderation concerns using AI to assist with comment moderation.【F:includes/tools/class-wp-mcp-ai-tool-analyze-comment-content.php†L17-L150】
- **Analyze Image** (`analyze_image`) analyzes images using AI vision capabilities from OpenAI, Anthropic, or Gemini. Supports detailed image description, object detection, OCR text extraction, and visual question answering. Automatically selects the best available provider.【F:includes/tools/class-wp-mcp-ai-tool-analyze-image.php†L17-L250】
- **Extract Image Text** (`extract_image_text`) extracts all visible text from images using AI OCR capabilities from OpenAI, Anthropic, or Gemini. Supports documents, screenshots, handwriting, and complex layouts with multi-provider fallback.【F:includes/tools/class-wp-mcp-ai-tool-extract-image-text.php†L17-L200】

## Data visualization

- **Create Chart** (`create_chart`) creates interactive charts using Chart.js. Supports bar, line, pie, doughnut, radar, and polar area charts. Returns HTML/JavaScript or saves as attachment.【F:includes/tools/class-wp-mcp-ai-tool-create-chart.php†L17-L300】

## Spreadsheet & Data Analysis

- **Pro Excel** (`pro_excel`) generates and manipulates Microsoft Excel formulas using AI-powered natural language processing. Recognizes Excel as a Turing-complete programming language (since Excel 2021 with LAMBDA functions) and provides comprehensive spreadsheet automation capabilities. Supports 6 operations:
  - **Generate**: Creates Excel formulas from natural language descriptions (e.g., "sum A1 to A10" → `=SUM(A1:A10)`)
  - **Explain**: Provides step-by-step explanations of complex formulas with plain English descriptions of each component
  - **Debug**: Identifies and fixes errors in formulas, suggesting corrections and best practices
  - **Document**: Adds inline comments and documentation to formulas for maintainability
  - **Convert**: Transforms formulas between Excel versions (legacy → modern, traditional → LAMBDA-based)
  - **Lambda**: Generates custom LAMBDA functions for recursive, reusable, and advanced calculations (Excel 2021+/Microsoft 365 only)
  
  **Excel Version Targeting**: Supports three Excel version modes:
  - `modern` (default): Excel 2021+/Microsoft 365 with LAMBDA, LET, XLOOKUP, XMATCH, FILTER, SORT, UNIQUE, SEQUENCE
  - `legacy`: Excel 2019 and earlier with traditional formulas (VLOOKUP, INDEX/MATCH, nested IFs)
  - `online`: Excel Online cloud-specific features and web compatibility
  
  **LAMBDA Functions & Turing Completeness**: When `excel_enable_lambda` is enabled (default), the tool can generate custom LAMBDA functions that make Excel Turing-complete. These enable:
  - Custom reusable functions with named parameters
  - Recursive calculations (factorial, Fibonacci, tree traversal)
  - Functional programming patterns (MAP, REDUCE, FILTER operations)
  - Complex algorithms requiring iteration and state management
  - Modular formula libraries that can be shared across workbooks
  
  **Formula Complexity Control**: The `excel_max_complexity` setting controls formula sophistication:
  - `simple`: Basic formulas for beginners (SUM, AVERAGE, IF, VLOOKUP)
  - `moderate` (default): Nested functions with 2-3 levels (SUMIFS, INDEX/MATCH combinations)
  - `complex`: Advanced multi-step calculations (array formulas, complex conditionals)
  - `advanced`: Expert-level formulas with LAMBDA, LET, recursive logic, and cutting-edge features
  
  **Formula Comments**: When `excel_include_comments` is enabled (default), generated formulas include inline explanatory comments for each step, making complex calculations easier to understand and maintain.
  
  **Optimization Modes**: The `excel_optimization_level` setting balances readability vs. performance:
  - `readability`: Clear, maintainable formulas with descriptive intermediate steps
  - `balanced` (default): Compromise between clarity and efficiency
  - `performance`: Optimized for calculation speed using array formulas and minimal operations
  
  **Provider Integration**: Works with all supported AI providers (OpenAI, Google Gemini, Ollama) using provider-agnostic execution. Settings can be configured per-provider in the Providers tab (OpenAI/Gemini/Ollama subtabs).
  
  **JSON Response Format**: Returns structured responses with:
  - `formula`: The generated Excel formula
  - `explanation`: Plain English description of how it works
  - `complexity`: Estimated difficulty level
  - `excel_version`: Target Excel version
  - `alternative_approaches`: Optional suggestions for different solution methods
  - `warnings`: Potential issues or performance considerations
  
  **Capability Flags**: Marked as `pro`, `requires-credentials`, `requires-model`, `consumes-tokens`, `requires-capability` (edit_posts). Integrates with agentic workflows for multi-step formula development and debugging.
  
  **Use Cases**:
  - Financial modeling: Generate complex financial formulas (NPV, IRR, amortization schedules)
  - Data analysis: Create dynamic reports with FILTER, SORT, UNIQUE, and aggregation functions
  - Business intelligence: Build dashboard formulas with conditional formatting and data validation
  - Scientific calculations: Develop custom mathematical functions using LAMBDA
  - Legacy migration: Convert old Excel formulas to modern equivalents for better performance
  - Formula debugging: Diagnose and fix errors in existing spreadsheets
  - Training: Generate documented formulas as learning resources for Excel users
  
  【F:includes/tools/class-wp-mcp-ai-tool-pro-excel.php†L1-L840】

## External data and automations

- **Run OpenAI External Action** (`run_openai_external_action`) triggers preconfigured OpenAI workflows or assistants through the Responses API, performing payload sanitisation, timeout handling, and capability checks that restrict access to administrators.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】
- **Run Crawl4AI Job** (`run_crawl4ai_job`) executes Crawl4AI collections locally or via remote endpoints, collating Markdown, HTML, and error metadata so assistants can ingest large batches of URLs in a single invocation. Remote submissions now return an immediate task token while the new background crawler schedules WP-Cron polling so results are cached and surfaced to the assistant once Crawl4AI finishes processing.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】
- **Crawl4AI Wholesale Price Lookup** (`crawl4ai_price_lookup`) queries the Crawl4AI web search endpoint against BJ’s, Sam’s Club, and Costco domains, extracting pricing snippets and normalised metadata for authorised merchandising reviews.【F:includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php†L17-L189】
- **Get QuickBooks Report 🌟** (`quickbooks_report`) requests Profit and Loss, Balance Sheet, or any supported QuickBooks Online report using the configured company ID and bearer token. Optional `start_date`, `end_date`, `accounting_method`, and `minor_version` arguments provide fine-grained control while enforcing multisite membership and a filterable capability requirement before contacting the QuickBooks API. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php†L15-L214】
- **Lookup Import Duty 🌟** (`get_import_duty`) calls the ITA Tariff Rates API to locate tariff lines for HS codes or free-form descriptions, returning duty percentages, effective dates, and commodity notes for supported countries. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php†L15-L152】
- **Google Analytics Report** (`google_analytics_report`) exchanges configured OAuth credentials for an Analytics Data API token, then runs GA4 reports with metrics, dimensions, ordering, and aggregation controls tailored to the assistant request.【F:includes/tools/class-wp-mcp-ai-tool-get-google-analytics-report.php†L15-L158】
- **Search Google Drive** (`search_drive`) searches Google Drive and returns matching files and folders with names, types, and metadata. Supports simple text queries (e.g., `"report"`) or advanced Drive query syntax. Automatically excludes trashed items. Requires Google Drive OAuth credentials.【F:includes/tools/class-wp-mcp-ai-tool-search-drive.php†L17-L200】
- **OpenAI Usage Analytics** (`openai_usage_analytics`) provides analytics on OpenAI API usage including total requests, tokens used, and estimated costs. Helps monitor and optimize API usage. Requires `manage_options` capability.【F:includes/tools/class-wp-mcp-ai-tool-openai-usage-analytics.php†L17-L200】
- **Analyze File Suitability** (`analyze_file_suitability`) analyzes if a WordPress attachment file is suitable for OpenAI processing. Checks file size, format compatibility, and provides actionable recommendations before upload.【F:includes/tools/class-wp-mcp-ai-tool-analyze-file-suitability.php†L17-L200】
- **Google Business Insights 🌟** (`get_google_business_insights`) issues Business Profile insights requests with metric selections, time ranges, and timezone hints, returning normalised totals for the specified location resource. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-business-insights.php†L15-L149】
- **Meta Social Insights 🌟** (`get_facebook_instagram_insights`) pulls Facebook Page or Instagram business analytics through the Graph API, supporting custom metric sets, aggregation periods, and optional since/until boundaries. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php†L15-L146】
- **LinkedIn Insights 🌟** (`get_linkedin_insights`) requests organisational share statistics from the LinkedIn Marketing API with optional timeframe start/end values and granularity controls to track campaign performance. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php†L15-L138】
- **TikTok Insights 🌟** (`get_tiktok_insights`) contacts the TikTok Open API to retrieve account-level metrics, allowing optional ISO time windows and aggregation granularity for growth monitoring. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php†L15-L136】
- **Get GDACS Events** (`get_gdacs_events`) queries the Global Disaster Alert and Coordination System for tropical cyclone and flood alerts, supporting optional date filters while enforcing authenticated access. The response now advertises GPT-5 as the recommended chat model so assistants can automatically opt into the higher-capacity reasoning tier when analysing the feed.【F:includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php†L12-L200】
- **Get NHC Active Storms** (`get_nhc_active_storms`) calls the National Hurricane Center `CurrentStorms.json` endpoint, applies site membership and capability checks, and returns a sanitised array of the active storm payload for assistants that need the latest advisory snapshot.【F:includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php†L15-L146】
- **Web Search** (`web_search`) performs lightweight lookups against the configured provider (DuckDuckGo Instant Answer or Brave Search), normalises related topic results when available, and enforces per-user permission and result-count limits for safe research flows.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L320】
- **Create Google Calendar Event 🌟** (`create_google_calendar_event`) validates event timing, attendee, and reminder data before calling the Google Calendar API, resolving credentials from an optional `connection_id` (Remote Sites) or the base Tools → Connections → Google Calendar settings, and enforcing that the granted OAuth scope actually covers the write. Supports `create_meet_link` for automatic Google Meet conferencing, exposes filters so sites can control capabilities, calendar defaults, and request timeouts, and returns the canonical envelope. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php†L20-L400】【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php†L400-L820】
- **List Google Calendars 🌟** (`list_google_calendars`) lists the calendars the connected account can see, returning each calendar ID, display name, time zone, and access role so assistants discover the `calendar_id` required by the other Calendar tools instead of assuming `"primary"`. Accepts an optional `connection_id` and a `max_results` page size (1–250, default 100). **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendars.php†L24-L240】
- **List Google Calendar Events 🌟** (`list_google_calendar_events`) reads events from a calendar with `time_min` / `time_max` RFC3339 bounds, free-text `query`, `order_by` (`startTime` or `updated`), `single_events` expansion, `show_deleted`, and IANA `timezone` controls, paginating through `page_token` / `next_page_token` with a `max_results` ceiling of 2500. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendar-events.php†L31-L480】
- **Update Google Calendar Event 🌟** (`update_google_calendar_event`) revises an existing event's `summary`, `description`, `location`, `start_time`, `end_time`, `timezone`, or `status`, honouring the exclusive all-day `end.date` rule and rejecting UTC offsets in place of IANA time zones. Defaults to a get+update pair (2 quota units); `partial: true` issues a PATCH instead (3 quota units) and is required when the caller is not the event organiser. `send_updates` controls attendee notification. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-update-google-calendar-event.php†L36-L512】
- **Delete Google Calendar Event 🌟** (`delete_google_calendar_event`) removes an event by `event_id`, with `send_updates` controlling whether attendees are emailed about the cancellation. A `410 deleted` response is treated as **success** rather than an error, because the desired end state already holds. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-delete-google-calendar-event.php†L27-L237】
- **Check Google Calendar Availability 🌟** (`check_google_calendar_availability`) queries the Calendar freeBusy endpoint across up to 50 calendar IDs or attendee email addresses for a required `time_min` / `time_max` window, returning busy intervals in an optional IANA `timezone` so assistants can propose conflict-free slots. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-check-google-calendar-availability.php†L29-L421】
- **Quick Add Google Calendar Event 🌟** (`quick_add_google_calendar_event`) creates an event from a natural-language `text` string such as `"Lunch with Alice Friday at noon"`, letting Google resolve relative dates against the calendar time zone, with optional `calendar_id`, `connection_id`, and `send_updates`. **Pro addon tool**.【F:addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-quick-add-google-calendar-event.php†L29-L266】
- **Search Gmail Messages 🌟** (`search_gmail`) exchanges the configured refresh token for an access token, issues Gmail query requests with optional label filters or pagination, and returns normalised sender, subject, snippet, and message URLs while logging transport failures for administrators. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php†L1-L200】【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php†L200-L400】
- **Search ReliefWeb Reports** (`reliefweb_reports`) queries ReliefWeb's humanitarian dataset by country or disaster type, supports optional keyword filtering, and normalises report metadata including URLs, sources, and publication timestamps.【F:includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php†L15-L234】
- **Get Open-Meteo Forecast** (`get_open_meteo_forecast`) retrieves hourly weather data from the Open-Meteo API with coordinate, forecast window, timezone, and variable controls, then sanitises units and readings for downstream prompts.【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L15-L213】【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L215-L309】
- **Publish Meta Social Post 🌟** (`post_facebook_instagram`) publishes content to Facebook Pages or Instagram business accounts using the Graph API, handling captions, links, photos, and structured error reporting for administrators. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php†L15-L170】
- **Publish Google Business Update 🌟** (`post_google_business_update`) creates Google Business Profile local posts with summaries, language codes, and optional call-to-action buttons, logging API responses for audit trails. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-google-business-update.php†L15-L168】
- **Publish LinkedIn Update 🌟** (`post_linkedin_update`) submits LinkedIn UGC posts on behalf of members or organisations with optional share URLs, capability filters, and structured error messages from the Marketing API. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-linkedin-update.php†L15-L160】
- **Publish TikTok Video 🌟** (`post_tiktok_video`) uploads externally hosted video assets to TikTok’s Open API share endpoint with optional captions, returning publish identifiers and status metadata for follow-up actions. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-tiktok-video.php†L15-L152】
- **Generic REST API** 🌟 (`generic_rest`) provides a flexible HTTP client for AI assistants to integrate with any REST API endpoint, including plugins without explicit integrations. Supports all standard HTTP methods (GET, POST, PUT, PATCH, DELETE), custom headers, JSON or form-data request bodies, query parameters, and multiple authentication types (bearer token, basic auth, custom header auth). Enforces security controls including `manage_options` capability requirement, URL validation, blocking of localhost and private IP addresses (configurable via filter), and response size limits. Use this tool when you need to call external APIs, third-party services, or WordPress plugin REST endpoints that do not have dedicated tools. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-generic-rest.php†L17-L560】
- **Query iSAMS** 🌟 (`isams_query`) provides access to iSAMS School Management System data through authenticated REST API requests. Supports querying pupils, employees, departments, houses, terms, subjects, year groups, and admission applicants with pagination controls. The tool automatically handles authentication token management with caching (55-minute TTL) and enforces read-only access with `read` capability requirement. Requires iSAMS API credentials (URL, key, secret) to be configured in plugin settings. Returns structured school data including student records, staff information, organizational structures, and academic terms. **Pro addon tool**.【F:addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php†L1-L400】

## JetEngine REST utilities

- **List JetEngine REST Routes** (`list_jetengine_rest_routes`) returns metadata about JetEngine’s REST namespace, including method, callback, and capability guidance for each bundled route. Access is limited to users with `manage_options` permissions.【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】
- **Invoke JetEngine REST Route** (`invoke_jetengine_route`) proxies CRUD operations to JetEngine controllers using the authenticated user context, validates required identifiers and instance keys, and supports REST or HTTP fallbacks when routes are unavailable.【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】【F:includes/class-wp-mcp-ai-jetengine-tool-handlers.php†L12-L213】 When the helper falls back to an HTTP request it forwards all cookies from the operator's session to the JetEngine endpoint so hosts with strict cookie policies can account for the behaviour in their governance controls.【F:includes/class-wp-mcp-ai-jetengine-tool-handlers.php†L330-L367】
- **JetEngine MCP Bridge** (`jetengine_mcp`) discovers and proxies JetEngine 3.8+ MCP Server tools. Actions: discover_tools, call_tool, get_site_context. Requires `manage_options`. Risk level: elevated.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-mcp-bridge.php†L1-L50】
- **JetEngine Create Post Type** (`jetengine_create_post_type`) creates custom post types via JetEngine MCP with validated slug/labels/settings. Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-create-post-type.php†L1-L50】
- **JetEngine Create Taxonomy** (`jetengine_create_taxonomy`) creates custom taxonomies via JetEngine MCP with hierarchy and attachment configuration. Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-create-taxonomy.php†L1-L50】
- **JetEngine Create Meta Field** (`jetengine_create_meta_field`) adds meta fields to post types, taxonomies, or users via JetEngine MCP. Supports 16 field types. Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-create-meta-field.php†L1-L50】
- **JetEngine Manage Relations** (`jetengine_manage_relations`) lists and creates JetEngine relations (one-to-one, one-to-many, many-to-many). Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-manage-relations.php†L1-L50】
- **JetEngine Site Context** (`jetengine_site_context`) retrieves comprehensive site structure from JetEngine MCP for AI grounding. Read-only. Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-site-context.php†L1-L50】
- **JetEngine Prompts** (`jetengine_prompts`) discovers and renders JetEngine MCP prompt templates. Actions: list, get. Requires `manage_options`.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine-prompts.php†L1-L50】

## Operational helpers

- **Count Tokens** (`count_tokens`) estimates token counts for text or messages to help plan API requests and manage token budgets. Supports two methods: accurate tiktoken tokenizer (uses OpenAI's BPE encoding, default) or fast heuristic estimation (~4 chars/token). Can count plain text strings or chat message arrays with proper message formatting overhead. When a model is specified, returns context limits, usage percentage, and budget recommendations with 10% safety margin. **Note:** OpenAI does not provide a dedicated token counting API endpoint; this tool uses client-side tokenization. The tiktoken method requires the `rahul900day/tiktoken-php` composer package (installed by default).【F:includes/tools/class-wp-mcp-ai-tool-count-tokens.php†L15-L300】
- **Create Cron Job** (`create_cron_job`) sanitises hook names, schedules single-run or recurring events, and blocks duplicates so assistants can safely automate WP-Cron tasks for privileged operators.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L16-L142】 Administrators (or any role with `manage_options`) can provide:
  - `hook` – required action hook name, normalised with `sanitize_text_field()` so assistant-provided slugs cannot register arbitrary callbacks.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L70-L88】
  - `timestamp` – optional Unix timestamp for the first run; missing or zero values default to one minute in the future and past timestamps are rejected so the scheduler never backdates jobs.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L90-L112】
  - `schedule` – either `single` for a one-off event or any registered recurrence slug such as `hourly`; invalid schedules return a descriptive error before touching WP-Cron.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L114-L124】
  - `args` – optional positional arguments that are normalised to avoid reindexing associative arrays while still preventing duplicate hook+args combinations from being queued twice.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L126-L156】
    The tool surfaces the scheduled ISO 8601 datetime in its response so operators can confirm the outcome.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L158-L168】
- **List Cron Jobs** (`list_cron_jobs`) retrieves all WordPress cron jobs scheduled through the plugin and returns job details including hook name, schedule type, next run timestamp, creator, and job ID for subsequent operations.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L17-L141】 This tool:
  - Automatically prunes stale jobs before listing to ensure accuracy.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L66-L68】
  - Returns formatted timestamps in ISO 8601 format for consistency.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L111-L114】
  - Identifies the user who created each job or labels it as "System".【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L94-L103】
  - Enables assistants to provide visibility into scheduled automation tasks.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L78-L80】
- **Get Cron Job** (`get_cron_job`) fetches detailed information about a specific cron job by its job ID, including schedule interval details for recurring jobs, current status, and complete execution metadata.【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L17-L145】 The response includes:
  - Job status indicating whether the job is actively scheduled or missing from WP-Cron.【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L118-L126】
  - Schedule display name and interval for recurring jobs (e.g., "Once Hourly", 3600 seconds).【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L138-L143】
  - Complete audit information including creation timestamp and first scheduled run.【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L128-L136】
- **Delete Cron Job** (`delete_cron_job`) removes a scheduled cron job from both the plugin's tracking system and WordPress's cron scheduler, supporting both single and recurring events.【F:includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php†L17-L90】 The tool:
  - Verifies the job exists before attempting deletion.【F:includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php†L79-L82】
  - Unschedules the event from WP-Cron for both single-run and recurring jobs.【F:includes/class-wp-mcp-ai-cron-manager.php†L151-L157】
  - Returns confirmation including the deleted job's hook and ID.【F:includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php†L90-L93】
  - Enables assistants to cancel outdated or unnecessary automation tasks on behalf of operators.
- **Check WP-CLI Status** (`check_wp_cli`) scans common install locations and the current PATH for a `wp` binary or `wp-cli.phar`, returning the resolved path, binary type, captured `wp --version` output, and any environment warnings so administrators can confirm the CLI is reachable from the web server.【F:includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php†L17-L309】
- **Purge Cloudflare Cache** (`purge_cloudflare_cache`) submits cache purge jobs for a configured Cloudflare zone, supporting targeted URL, host, or tag purges as well as full-zone invalidations while enforcing administrator-only access and configurable request timeouts.【F:includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php†L17-L292】
- **Purge Varnish Cache** (`purge_varnish_cache`) purges the local Varnish cache with support for full-cache bans and specific URL purges. Requires `manage_options` capability and can target specific Varnish hosts or use the default localhost configuration. The tool validates Varnish connectivity and reports detailed success/failure status including which URLs were purged.【F:includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php†L17-L200】
- **Purge Cache** (`purge_cache`) master cache purge orchestrator that coordinates multi-layer cache clearing (Cloudflare, Varnish, object cache, page cache) in the correct order to ensure content updates are properly reflected. Automatically detects which caching layers are configured and executes purges sequentially, reporting results for each layer. Agents can use this to perform comprehensive cache invalidation with a single tool call.【F:includes/tools/class-wp-mcp-ai-tool-purge-cache.php†L17-L200】
- **Send Group Email** (`send_group_email`) orchestrates group email campaigns through WordPress `wp_mail()`, supporting both JSON and plain text email definitions uploaded as attachments. Accepts subject, message, recipients (as strings or objects with email/name fields), CC, BCC, custom headers, and sender overrides. Enforces configurable capability requirements (default: `publish_posts`) and recipient limits (default: 100). Features automatic deduplication, header injection prevention, attachment size validation (1MB limit), and extensive filter hooks for customization. Recipients can be combined from multiple attachments, with messages concatenated and addresses deduplicated across TO/CC/BCC fields. Full documentation: [`docs/send-group-email-usage.md`](../../features/tools/communication/send-group-email-usage.md).【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L650】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L169-L170】【F:tests/test-send-group-email-tool.php†L1-L445】
- **Send Mailjet Email** (`send_mailjet_email`) delivers transactional mail through Mailjet, honouring capability filters, configured sender defaults, and optional CC/BCC or reply-to overrides. Requests are logged, sanitised, and return Mailjet status payloads so assistants can confirm delivery or surface actionable error details.【F:includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php†L19-L405】
- **Send Telegram Message** (`send_telegram_message`) posts formatted updates to Telegram chats or channels using bot credentials, honours capability filters, strips unsafe markup, and records request/response metadata for operational visibility.【F:includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php†L16-L232】【F:includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php†L234-L314】
- **Send WhatsApp Message** (`send_whatsapp_message`) sends text messages through the WhatsApp Cloud API with preview controls, recipient sanitisation, and detailed error handling for capability-restricted operators.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php†L15-L178】
- **Schedule Notify.lk SMS** (`schedule_notify_sms`) queues Notify.lk SMS payloads for future delivery, resolving natural-language schedule times, storing contact metadata, and handing execution off to a dedicated WP-Cron processor.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-schedule-notify-sms.php†L15-L220】
- **Get System Logs** (`get_system_logs`) aggregates recent NV oOS logger entries, tails the WordPress debug/PHP error logs, and scans plugin directories for readable `.log` files so administrators can diagnose issues without leaving the assistant workflow.【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L12-L352】【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L353-L668】
- **Get MCP Environment Status** (`get_environment_status`) compiles the WordPress version, PHP version, plugin defaults, assistant counts, supported dependency status, and configuration warnings so operators can triage live incidents quickly.【F:includes/tools/class-wp-mcp-ai-tool-get-environment-status.php†L12-L178】
- **Get Update Status** (`get_update_status`) requires the `update_core` capability and returns pending WordPress core, plugin, and theme updates with versions, download URLs, and optional component-type filtering so assistants can prioritise maintenance tasks programmatically.【F:includes/tools/class-wp-mcp-ai-tool-get-update-status.php†L12-L182】
- **Get Site Health Status** (`get_site_health`) bootstraps the WordPress Site Health API to run direct and asynchronous diagnostics, grouping results into critical, warning, and pass buckets with sanitised descriptions, actionable recommendations, and extracted follow-up links for operators who can `view_site_health_checks`.【F:includes/tools/class-wp-mcp-ai-tool-get-site-health.php†L12-L255】
- **Check Site Security** (`check_site_security`) performs comprehensive security audits specifically focused on risks related to using this AI plugin. The tool checks for HTTPS configuration, debug mode in production, file editing permissions, default admin usernames, WordPress version vulnerabilities, SSL verification settings, forced SSL admin, and database prefix security. Results are categorised into critical issues, warnings, and passing checks, with an overall risk level assessment (safe, low, medium, high, critical). The tool automatically runs during plugin activation and displays admin notices for high-risk or critical configurations, warning administrators that the plugin handles sensitive AI API keys and should not be enabled on insecure sites. Administrators can bypass the activation check with `define( 'WP_MCP_AI_SKIP_SECURITY_CHECK', true );` in wp-config.php if they understand the risks. Requires `manage_options` capability.【F:includes/tools/class-wp-mcp-ai-tool-check-site-security.php†L12-L492】
- **Probe Assistant Chat** (`probe_chat`) invokes the REST controller with the probe flag so administrators can confirm assistant visibility, message sanitisation, and configuration health without triggering a paid completion request.【F:includes/tools/class-wp-mcp-ai-tool-probe-chat.php†L12-L178】
- **Probe Remote MCP REST** (`probe_remote_mcp`) wraps the CLI remote tester, exercising `/assistants` and `/chat` on external MCP deployments while forwarding bearer, guest, or nonce credentials for side-by-side troubleshooting from the live site.【F:includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php†L12-L164】【F:includes/class-wp-mcp-ai-cli-command.php†L137-L280】
- **Open OpenAI Logs** (`open_openai_logs`) and **Open OpenAI Usage** (`open_openai_usage`) surface dashboard links so administrators can audit provider activity without leaving the assistant interface.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】
- **Create WPCode Snippet 🌟** (`create_wpcode_snippet`) provisions or updates WPCode-managed snippets, validating code types, auto-insert locations, and capabilities before calling the WPCode API. The response returns activation status, the resolved location label, and the shortcode that operators can embed in content. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php†L15-L224】
- **Generate Simple JWT Token** (`generate_simple_jwt_token`) generates a Simple JWT Login bearer token for the current user, enabling authenticated API access across sessions. Requires the Simple JWT Login plugin to be active with authentication enabled and valid JWT keys configured. The tool validates JWT configuration before token generation and returns a time-limited bearer token that can be used for headless WordPress integrations. Agents can help users obtain authentication tokens for mobile apps, SPAs, or third-party integrations without exposing credentials.【F:includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php†L15-L200】
- **Generate Auth0 Token** (`generate_auth0_token`) generates an Auth0 bearer token using OAuth 2.0 client credentials flow. Requires Auth0 Management API client ID and client secret. The tool supports custom audience parameters or defaults to the Auth0 Management API audience (https://DOMAIN/api/v2/). Returns an access token with expiration metadata and scope information. Requires `manage_options` capability for security. Agents can help administrators obtain Auth0 bearer tokens for 1-click setup workflows, API testing, or programmatic access to Auth0 resources without manually going through the OAuth flow.【F:includes/tools/class-wp-mcp-ai-tool-generate-auth0-token.php†L15-L248】


## Assistant and profession management

- **Create Assistant** (`create_assistant`) creates a new AI assistant. Can be used in two modes: (1) Manual mode - select from predefined professions and regions, or (2) Prompt mode - provide a free-form description and optional custom system prompt. Supports attachment IDs for knowledge base files. The assistant will be saved as a draft.【F:includes/tools/class-wp-mcp-ai-tool-create-assistant.php†L17-L200】
- **List Professions** (`list_professions`) lists all available professions that can be used when creating AI assistants. Professions include advisory services, creative roles, STEM fields, healthcare, emergency management, and more.【F:includes/tools/class-wp-mcp-ai-tool-list-professions.php†L17-L150】
- **Get Profession** (`get_profession`) retrieves detailed information about a specific profession including expertise areas, role description, warnings, knowledge base content, and default tools.【F:includes/tools/class-wp-mcp-ai-tool-get-profession.php†L17-L150】
- **Save Profession** (`save_profession`) creates a new profession or updates an existing one. Professions define roles that can be used when creating AI assistants, including their expertise areas, default tools, and knowledge base.【F:includes/tools/class-wp-mcp-ai-tool-save-profession.php†L17-L200】
- **Get Profession Stats** (`get_profession_stats`) retrieves statistics about profession usage and availability, helping administrators understand how professions are being used across the site.【F:includes/tools/class-wp-mcp-ai-tool-profession-stats.php†L17-L150】

## Site management and configuration

- **Update Option** (`update_option`) updates a WordPress option value. Can also be used to create a new option. Requires `manage_options` capability for security.【F:includes/tools/class-wp-mcp-ai-tool-update-option.php†L17-L100】
- **Install and Activate Plugin** (`install_and_activate_plugin`) installs a plugin from the WordPress.org repository and activates it. Requires the plugin slug and `install_plugins` capability.【F:includes/tools/class-wp-mcp-ai-tool-install-and-activate-plugin.php†L17-L200】
- **Install and Activate Theme** (`install_and_activate_theme`) installs a theme from the WordPress.org repository and activates it. Requires the theme slug and `install_themes` capability.【F:includes/tools/class-wp-mcp-ai-tool-install-and-activate-theme.php†L17-L200】
- **Site Creator** (`site_creator`) creates a complete WordPress site from a plan. The plan can include site options, plugins to install, themes to activate, and content to create (pages, posts). Designed for automated site provisioning workflows.【F:includes/tools/class-wp-mcp-ai-tool-site-creator.php†L17-L300】
- **Import Elementor Template Kit** (`import_elementor_template_kit`) imports an Elementor template kit ZIP file from the Media Library and creates pages. Requires Elementor to be active.【F:includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php†L17-L200】

## Google Maps Platform tools

- **Geocode Address** (`geocode_address`) converts addresses to geographic coordinates (latitude/longitude) or coordinates to addresses using Google Maps Geocoding API. Requires a Google Maps API key.【F:includes/tools/class-wp-mcp-ai-tool-geocode-address.php†L17-L150】
- **Search Places** (`search_places`) searches for businesses, landmarks, and points of interest using Google Maps Places API. Supports nearby search and text search with AI-powered contextual results. Requires a Google Maps API key.【F:includes/tools/class-wp-mcp-ai-tool-search-places.php†L17-L200】
- **Gemini Geospatial Query** (`gemini_geospatial_query`) asks location-based questions using Gemini AI with Google Maps grounding. Returns AI-generated answers about places, directions, and local information with map context tokens for visualization. Requires a Gemini API key.【F:includes/tools/class-wp-mcp-ai-tool-gemini-geospatial-query.php†L17-L200】

## GitHub integration tools 🌟

**All GitHub tools are Pro tier tools.**

- **List GitHub Repositories** 🌟 (`list_github_repositories`) lists GitHub repositories for the authenticated user. Requires a GitHub personal access token to be configured. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-list-github-repositories.php†L17-L150】
- **GitHub Repository Operations** 🌟 (`github_repository_operations`) performs GitHub repository operations such as creating branches and managing files in the custom-tools directory. Supports CRUD operations on repository content. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-github-repository-operations.php†L17-L300】
- **Manage GitHub Codespace** 🌟 (`manage_github_codespace`) creates, starts, stops, or lists GitHub Codespaces for repository development. Enables AI-assisted development environment management. **Pro tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-manage-github-codespace.php†L17-L200】

## Mesh networking tools

- **Query Remote Site** (`query_remote_site`) sends a prompt to a peer site in the mesh network and receives the response from its AI assistant. Requires `manage_options` capability and mesh networking to be enabled.【F:includes/tools/class-wp-mcp-ai-tool-query-remote-site.php†L17-L250】
- **Query Mesh Intelligent** (`query_mesh_intelligent`) sends a prompt to the mesh network with AI-powered peer selection and automatic failover. The system intelligently routes your request to the optimal peer site based on current load, response times, and task complexity.【F:includes/tools/class-wp-mcp-ai-tool-query-mesh-intelligent.php†L17-L300】

## Vision AI tools

- **Vision Product Search** (`vision_product_search`) searches for similar products using Google Cloud Vision API Product Search feature. Requires proper Google Cloud authentication credentials to succeed.【F:includes/tools/class-wp-mcp-ai-tool-vision-product-search.php†L17-L200】
- **Vision Object Localization** (`vision_object_localization`) detects and localizes multiple objects in an image using Google Cloud Vision API. Returns bounding boxes and labels for identified objects. Requires proper Google Cloud authentication credentials.【F:includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php†L17-L200】

## Product data extraction

- **Scrape Product** (`scrape_product`) scrapes product information (title, subtitle, description, images) from a product URL or saved HTML file. Downloads highest resolution images to WordPress media library. Enhanced with Schema.org JSON-LD extraction for automatic parsing of product schemas. Extracts offers, pricing, availability, and identifiers (SKU, GTIN, brand, model, MPN). Supports multiple extraction methods with fallbacks. Multi-currency support (USD, EUR, GBP). Useful for e-commerce content migration and product data integration.【F:includes/tools/class-wp-mcp-ai-tool-scrape-product.php†L17-L200】

## Pro addon tools

### Product Actualization (Pro)

- **Product Actualization** (`product_actualization`) composites product images into AI-generated scenes or short videos while preserving original product pixels. Image mode creates static composited images. Video mode uses Google Gemini VEO to animate the scene around the product. Features automatic background removal, shadows, and reflections. Perfect for lifestyle marketing shots, social ads, and product visualization. Requires PHP Imagick or GD extension. Video mode requires Google Gemini API.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-product-actualization.php†L17-L300】

### Harmonization Sub-Toolkit (Pro)

A composable set of 14 tools that decompose `product_actualization` into reusable primitives. Lives under `addons/pro/includes/tools/image-production/harmonization/`. See [`docs/harmonization-architecture.md`](../../harmonization-architecture.md) for the full pipeline.

- **Background-handling** — `generate_scene_background` (text→background), `adapt_background_for_subject` (declutter / blur / inpaint a landing zone), `outpaint_background` (extend canvas to a new aspect ratio).
- **Foreground / matte** — `refine_subject_matte` (alpha feathering, halo suppression), `auto_clean_white_background` (catalog/white-cyc → transparent PNG with smart anti-aliasing).
- **Harmonization primitives** — `harmonize_color` (Reinhard mean/std, histogram, AI neural), `relight_subject` (re-illuminate to match background lighting), `generate_shadow` (contact + cast shadow layer), `generate_reflection` (ground/surface reflection layer), `refine_composite_boundary` (edge-aware feather + optional AI polish on a 1-2 px boundary band).
- **Helpers** — `analyze_scene_lighting` (heuristic + optional AI vision lighting estimate), `suggest_placement` (top-3 placement bounding boxes via saliency).
- **Orchestrator** — `harmonize_image_into_background` (end-to-end pipeline; each stage individually toggleable; `polish_strength` is the single opt-in for whole-frame AI modification — original product pixels remain the source of truth otherwise).
- **Batch** — `harmonize_batch` (orchestrator over a list of subjects sharing one background; max 50 per call).

### Product Price Discovery (Pro)

- **Lookup Product Price** (`lookup_product_price`) provides multi-source product price discovery and comparison. ... Requires Crawl4AI integration. Optional Google Cloud Vision for image recognition. See comprehensive guide: [Product Price Lookup Guide](../../guides/user/assistants/PRODUCT-PRICE-LOOKUP-GUIDE.md).【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-lookup-product-price.php†L17-L400】

### Shopify Sync Pro Toolkit (Pro)

Cache-first Shopify↔WooCommerce synchronization with JetEngine CCT caching. All read tools query local cache with zero GraphQL API cost. Background sync via Action Scheduler + Shopify webhooks. Requires WooCommerce, JetEngine, WP_MCP_AI_Shopify_Client, and a Shopify Admin API connection in Remote Sites. See [Shopify Sync Integration Guide](../../toolkits/shopify-sync-integration.md).

- **Shopify Sync Inventory** (`shopify_sync_inventory`) searches and retrieves Shopify inventory levels from the local CCT cache with zero GraphQL cost. Supports filtering by vendor, product type, location, and stock status (in_stock/low_stock/out_of_stock). Actions: `search`, `get_item`, `get_levels`, `list_locations`, `list_low_stock`, `refresh`. Capability: `manage_woocommerce`. CCT-first: all reads < 50ms, refresh triggers Bulk Operation sync (10 pts).【F:addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-inventory.php†L1-L416】

- **Shopify Sync Products** (`shopify_sync_products`) browses the Shopify product catalog from CCT cache. List by type, vendor, or status. Search by SKU or variant GID. All reads zero GraphQL cost. Actions: `search`, `get_product`, `get_by_sku`, `list_by_type`, `list_by_vendor`, `list_by_status`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-products.php†L1-L333】

- **Shopify Sync Orders** (`shopify_sync_orders`) lists recent orders with header-only caching for zero-cost listing. Full order detail requires a live API call (~15 GraphQL pts) and includes line items, fulfillments, and transactions. Actions: `list_recent`, `get_order`, `search`, `get_order_analytics`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-orders.php†L1-L334】

- **Shopify Sync Settings** (`shopify_sync_settings`) manages toolkit configuration including sync interval, direction, WC sync toggle, and connections. Views GraphQL cost reports and triggers manual syncs. Actions: `get_settings`, `update_settings`, `get_sync_status`, `get_cost_report`, `sync_now`. Capability: `manage_options`.【F:addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-settings.php†L1-L335】

- **Shopify Sync Analytics** (`shopify_sync_analytics`) computes aggregated analytics from CCT data with zero GraphQL cost. Inventory summaries, stock velocity (fast/slow movers), product performance rankings, and vendor breakdowns. Actions: `inventory_summary`, `stock_velocity`, `product_performance`, `vendor_breakdown`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-analytics.php†L1-L435】

### FlowHub Inventory Sync Pro Toolkit (Pro)

FlowHub POS dispensary inventory → WooCommerce synchronization with JetEngine CCT caching. Cannabis-industry-specific compliance fields, low-stock alerts, and dispensary location tracking. Requires WooCommerce, JetEngine, and FlowHub API credentials. See [FlowHub Integration Guide](../../toolkits/flowhub-integration.md).

- **FlowHub Inventory** (`flowhub_inventory`) searches and retrieves FlowHub dispensary inventory from CCT cache. Filters by category, location, stock status. Actions: `search`, `get_item`, `get_levels`, `refresh`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-inventory.php†L1-L392】

- **FlowHub Products** (`flowhub_products`) browses FlowHub product catalog from CCT cache. Search by SKU, list categories. Actions: `search`, `get_product`, `get_by_sku`, `list_categories`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-products.php†L1-L245】

- **FlowHub Locations** (`flowhub_locations`) lists dispensary locations with stock counts from CCT cache. Actions: `list`, `get_location`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-locations.php†L1-L246】

- **FlowHub Analytics** (`flowhub_analytics`) computes aggregated analytics including inventory summaries, stock velocity, category breakdowns, compliance summaries, and location comparisons. All from CCT with zero API cost. Actions: `inventory_summary`, `stock_velocity`, `category_breakdown`, `compliance_summary`, `location_comparison`. Capability: `manage_woocommerce`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-analytics.php†L1-L383】

- **FlowHub Sync** (`flowhub_sync`) triggers sync operations and checks status. Actions: `sync_now`, `sync_status`, `clear_cache`. Capability: `manage_options`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-sync.php†L1-L220】

- **FlowHub Settings** (`flowhub_settings`) manages toolkit configuration including credentials, sync interval, direction, and field mapping. Actions: `get_settings`, `update_settings`, `test_connection`, `get_field_mapping`. Capability: `manage_options`.【F:addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-settings.php†L1-L293】


Each tool automatically inherits the assistant context and authentication details passed through the REST layer, allowing developers to compose complex workflows or replace default behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】

---

## OpenAI file and model management

- **List OpenAI Files** (`list_openai_files`) lists files uploaded to OpenAI. Use this to audit uploaded files, find files by purpose (`assistants`, `fine-tune`), check file quotas, or clean up old/unused files. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-list-openai-files.php†L17-L200】
- **Get OpenAI File Details** (`get_openai_file_details`) retrieves detailed metadata about a specific OpenAI file. Use this to verify file upload success, check file processing status, get file size and format info, or debug file-related issues.【F:includes/tools/class-wp-mcp-ai-tool-get-openai-file-details.php†L17-L150】
- **List Available Models** (`list_available_models`) lists all OpenAI models available to the configured API key. Supports filtering by capability type (chat, embedding, image, audio) and optionally includes deprecated models. Use this for dynamic model selection or capability discovery.【F:includes/tools/class-wp-mcp-ai-tool-list-available-models.php†L17-L200】
- **Get Model Information** (`get_model_information`) retrieves detailed information about a specific OpenAI model including context length, capabilities, and availability. Useful for verifying a model exists before using it in an agentic workflow.【F:includes/tools/class-wp-mcp-ai-tool-get-model-information.php†L17-L150】
- **Research Model** (`research_model`) researches an AI model's specifications and capabilities using AI to extract information from provider documentation and APIs. Returns configuration data needed for orchestration layer integration.【F:includes/tools/class-wp-mcp-ai-tool-research-model.php†L17-L200】
- **Add Model Config** (`add_model_config`) adds or updates an AI model configuration in the orchestration layer. Takes model specification data and stores it for use in model selection and orchestration. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-add-model-config.php†L17-L200】
- **Discover New Models** (`discover_new_models`) discovers newly released AI models from providers by querying their APIs. Compares discovered models against existing configurations and recommends new models to add. Optionally auto-researches specifications for newly found models.【F:includes/tools/class-wp-mcp-ai-tool-discover-new-models.php†L17-L200】

---

## Text embeddings and vector stores

- **Create Text Embeddings** (`create_text_embeddings`) generates vector embeddings for text using OpenAI's embedding models. Use for semantic search preparation, content similarity comparison, text classification, recommendation systems, or vector database population. Supports single strings or arrays of texts (up to 8191 tokens each).【F:includes/tools/class-wp-mcp-ai-tool-create-text-embeddings.php†L17-L200】
- **Semantic Content Search** (`semantic_content_search`) performs semantic search across WordPress content and health records using vector embeddings. Embeddings are generated by the configured embedding provider (OpenAI, Gemini, Ollama, or DigitalOcean) independent of the assistant's chat provider. When no embedding provider is configured, the tool degrades to keyword search (`fallback_mode: "keyword"`) instead of failing. Stored vectors from a different embedding model are skipped and reported via `skipped_dimension_mismatch`. Can also search a configured OpenAI vector store in addition to local content.【F:includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php†L17-L560】
- **Suggest Best Model** (`suggest_best_model`) recommends the best OpenAI model for a given task based on requirements. Supports requirement flags including `speed`, `quality`, `cost`, `vision`, and `function_calling` for cost and performance optimization.【F:includes/tools/class-wp-mcp-ai-tool-suggest-best-model.php†L17-L200】
- **Batch Embed Content** (`batch_embed_content`) generates embeddings for multiple posts or pages in batch. Use this to prepare semantic search, index a content library, build recommendation systems, or initialize vector databases. Supports filtering by specific post IDs or post types.【F:includes/tools/class-wp-mcp-ai-tool-batch-embed-content.php†L17-L200】
- **Create Vector Store** (`create_vector_store`) creates a new OpenAI vector store for knowledge retrieval and semantic search (RAG). Optionally accepts an initial list of OpenAI file IDs to populate the store. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-create-vector-store.php†L17-L200】
- **List Vector Stores** (`list_vector_stores`) lists all OpenAI vector stores with optional filtering and pagination. Use this to discover available knowledge bases. Requires `read` capability.【F:includes/tools/class-wp-mcp-ai-tool-list-vector-stores.php†L17-L150】
- **Get Vector Store** (`get_vector_store`) retrieves detailed information about a specific OpenAI vector store including file counts, status, and metadata. When no ID is supplied, uses the assistant's configured vector store.【F:includes/tools/class-wp-mcp-ai-tool-get-vector-store.php†L17-L150】
- **Manage Vector Store Files** (`manage_vector_store_files`) adds, removes, or lists files in an OpenAI vector store. Manages the knowledge base contents for RAG applications. Best file formats: PDF, TXT, DOCX, MD, JSON, HTML. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-manage-vector-store-files.php†L17-L200】

---

## OKF knowledge bundles (Open Knowledge Format)

Curated, deterministic knowledge in vendor-neutral OKF bundles (markdown concepts with YAML frontmatter, OKF v0.2 trust signals). Bundles live under `wp-content/uploads/mcp-ai-wpoos/knowledge/` and are never served over HTTP. All bundle paths resolve through `WP_MCP_AI_OKF_Bundle_Manager` (strict slug validation, `realpath` containment). The `skill-knowledge` bundle is auto-generated from bundled skills and protected from writes.

- **Read OKF Concept** (`okf_read_concept`) reads a single concept's frontmatter and body from a bundle. Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-read-concept.php†L17-L151】
- **Browse OKF Bundle** (`okf_browse`) lists a bundle directory's entries via its `index.md` (progressive disclosure). Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-browse.php†L17-L143】
- **Traverse OKF Cross-Links** (`okf_traverse`) follows cross-links from a concept up to a configurable depth. Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-traverse.php†L17-L123】
- **Search OKF Concepts** (`okf_search`) searches a bundle by type, tag, trust tier, or full text, with staleness filtering. Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-search.php†L17-L169】
- **List OKF Bundles** (`okf_list_bundles`) lists every bundle with health statistics — concept count, stale/deprecated counts, conformance, issues, types, and a trust-tier histogram. Filesystem paths are deliberately not exposed. Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-list-bundles.php†L17-L110】
- **Validate OKF Attestation** (`okf_validate_attestation`) validates an Attested Computation concept's contract (runtime, parameters, computation, executor, attester). Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-validate-attestation.php†L17-L470】
- **Validate OKF Bundle** (`okf_validate_bundle`) runs the advisory OKF v0.2 conformance report for a bundle (issues, stale/deprecated counts, broken cross-links — never blocks reading). Requires `read`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-validate-bundle.php†L17-L144】
- **Write OKF Concept** (`okf_write_concept`) creates or updates a concept. Creates the bundle on first write (strict slug names), regenerates the root index, appends `log.md`, and accepts the OKF v0.2 provenance/trust fields (`resource`, `sources`, `usage_window`, `verified`). Refuses the protected `skill-knowledge` bundle. Requires `edit_posts`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-write-concept.php†L17-L379】
- **Delete OKF Concept** (`okf_delete_concept`) soft-deletes a concept (renames to `.deleted.<timestamp>` for manual recovery) and appends `log.md`. Refuses the protected `skill-knowledge` bundle. Requires `delete_posts`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-delete-concept.php†L17-L140】
- **Import OKF Bundle** (`okf_import_bundle`) imports a bundle from a server-side ZIP archive with ZipSlip/symlink rejection, entry/size caps, and a minimum-concept check; stamps `okf_version` on the imported root index. Requires `manage_options`.【F:includes/tools/okf/class-wp-mcp-ai-tool-okf-import-bundle.php†L17-L112】
- **Enrich OKF from Site Content** (`okf_enrich_site_content`) **[PRO]** crawls published posts, pages (any public post type), and optionally taxonomy terms, generating OKF concepts with the provenance schema and cross-links into a bundle (default `site-content`, created on first run). Deterministic and idempotent; descriptions upgradeable to AI summaries via the `wp_mcp_ai_okf_enrichment_description` filter. Requires `manage_options`.【F:addons/pro/includes/tools/okf/class-wp-mcp-ai-tool-okf-enrich-site-content.php†L17-L138】
- **Route Knowledge Query** (`route_knowledge_query`) **[PRO]** classifies a knowledge query into an ordered routing plan across OKF / vector / Paper stores (deterministic keyword signals, overridable via `wp_mcp_ai_hybrid_router_signals` and `wp_mcp_ai_hybrid_router_decision`) and, when OKF is the primary route, returns the top token-overlap matches from a bundle. Requires `read`.【F:addons/pro/includes/tools/okf/class-wp-mcp-ai-tool-route-knowledge-query.php†L17-L157】

---

## Multi-agent orchestration

- **Create Agent Team** (`create_agent_team`) creates a specialized multi-agent team for complex tasks. Teams consist of a planner (task decomposition), executors (specialized work), and optionally a critic (validation). The system selects appropriate professions based on task requirements.【F:includes/tools/class-wp-mcp-ai-tool-create-agent-team.php†L17-L200】
- **Delegate to Agent** (`delegate_to_agent`) delegates a subtask to a specialized agent. The agent will use its expertise and tools to complete the task. Use this for complex workflows where different specialists handle different aspects of the work.【F:includes/tools/class-wp-mcp-ai-tool-delegate-to-agent.php†L17-L200】
- **Delegate to A2A Agent** (`delegate_to_a2a_agent`) delegates a task to a remote A2A-compliant agent. Discovers the agent via the `/.well-known/agent.json` endpoint, sends a message, and returns the result. Use this when the task requires capabilities available only on an external agent.【F:includes/tools/class-wp-mcp-ai-tool-delegate-to-a2a-agent.php†L17-L200】
- **Aggregate Agent Results** (`aggregate_agent_results`) combines results from multiple agents using various aggregation strategies. Use this after receiving outputs from multiple specialized agents to synthesize a unified result.【F:includes/tools/class-wp-mcp-ai-tool-aggregate-agent-results.php†L17-L200】
- **Execute Workflow** (`execute_workflow`) creates and executes an enhanced multi-agent workflow with advanced features: parallel execution, dependency management, automatic retries, and state persistence. Use for complex tasks that benefit from coordinated multi-agent execution.【F:includes/tools/class-wp-mcp-ai-tool-execute-workflow.php†L17-L300】
- **Check Workflow Health** (`check_workflow_health`) checks the health status of workflows to detect if they are stuck in `initialized` state. Provides recommendations for fixing workflow issues including WP-Cron/async processing problems.【F:includes/tools/class-wp-mcp-ai-tool-check-workflow-health.php†L17-L200】

---

## Agent memory management

- **Store Agent Context** (`store_agent_context`) stores important context, learnings, or information for an agent to remember. Supports automatic content ingestion from Vector Stores, WordPress posts/pages, and URLs. Context can be retrieved later using `retrieve_agent_memory`.【F:includes/tools/class-wp-mcp-ai-tool-store-agent-context.php†L17-L300】
- **Retrieve Agent Memory** (`retrieve_agent_memory`) retrieves previously stored agent context and memory. Search by context ID for exact retrieval, or by agent ID, type, tags, and natural-language query for semantic search. Returns relevant contexts ranked by relevance and importance.【F:includes/tools/class-wp-mcp-ai-tool-retrieve-agent-memory.php†L17-L250】
- **Prioritize Context** (`prioritize_context`) prioritizes and filters context items to fit within a token budget. Ranks contexts by relevance to the current task, importance level, and recency. Returns an optimized subset of contexts that maximizes value while respecting token limits.【F:includes/tools/class-wp-mcp-ai-tool-prioritize-context.php†L17-L200】
- **Semantic Context Search** (`semantic_context_search`) searches agent contexts using semantic similarity based on vector embeddings. More accurate than keyword matching for understanding context relevance. Requires OpenAI API key for embedding generation.【F:includes/tools/class-wp-mcp-ai-tool-semantic-context-search.php†L17-L200】
- **Manage Context Lifecycle** (`manage_context_lifecycle`) provides advanced context lifecycle management: refresh TTL, apply compression, merge related contexts, update memory content, delete specific contexts, and manage retention policies. Implements RAG best practices for memory lifecycle.【F:includes/tools/class-wp-mcp-ai-tool-manage-context-lifecycle.php†L17-L300】
- **Batch Manage Memory** (`batch_manage_memory`) performs bulk operations on agent memory contexts: bulk update tags/importance, bulk delete, export to JSON, import from JSON, and batch tag management. Optimized for managing large-scale memory systems.【F:includes/tools/class-wp-mcp-ai-tool-batch-manage-memory.php†L17-L250】
- **Memory Audit Trail** (`memory_audit_trail`) tracks and manages memory version history with full audit trail. View change history, compare versions, rollback to previous states, and maintain compliance records for all memory modifications.【F:includes/tools/class-wp-mcp-ai-tool-memory-audit-trail.php†L17-L250】

---

## Reasoning and code analysis

- **Enable Reasoning Mode** (`enable_reasoning_mode`) activates enhanced reasoning mode for complex multi-step tasks. Analyzes task complexity across 5 indicators (multi-step, logical complexity, code generation, domain expertise, verification needs) and configures chain-of-thought prompting, lower temperature, and verification steps when the reasoning score exceeds a 0.7 threshold.【F:includes/tools/class-wp-mcp-ai-tool-enable-reasoning-mode.php†L17-L200】
- **Analyze Code Sequence** (`analyze_code_sequence`) analyzes and optimizes PHP code sequences. Performs syntax validation, WordPress Coding Standards checking, security scanning (eval, SQL injection, XSS, file inclusion), and provides improvement suggestions with line-level annotations.【F:includes/tools/class-wp-mcp-ai-tool-analyze-code-sequence.php†L17-L300】
- **Validate Reasoning Chain** (`validate_reasoning_chain`) validates logical reasoning chains for coherence and consistency. Checks step-by-step progression, verifies premises, identifies logical gaps, and ensures conclusions follow from reasoning. Returns a validation report with coherence score, consistency check, and identified issues.【F:includes/tools/class-wp-mcp-ai-tool-validate-reasoning-chain.php†L17-L200】

---

## Deep research

- **Deep Research** (`deep_research`) performs comprehensive deep research on any topic using multi-step web search and AI analysis. Works with all supported AI providers (OpenAI, Gemini, Anthropic, Cloudflare, HuggingFace, Ollama). Generates detailed research reports with findings and citations. Configure a dedicated research model via **Settings → NV oOS → `deep_research_model`**. Empty completions are retried across the configured provider chain (max 2 attempts by default, filterable via `wp_mcp_ai_deep_research_max_attempts`); DeepSeek-style reasoning-only outputs fall back to `reasoning_content`; `finish_reason: length` truncation retries once with a doubled token budget; empty reports are never cached. Marked as a Pro-level tool in terms of capability; available in the base plugin.【F:includes/tools/class-wp-mcp-ai-tool-deep-research.php†L17-L400】

---

## Browser-native AI (client-side NLP)

These tools run directly in the visitor's browser using the Web AI API (navigator.ml) without any server round-trip or API key. They require a Chromium-based browser with the experimental Web AI feature flag enabled.

- **Client Summarize Text** (`client_summarize_text`) generates a concise summary of the provided text using browser-native AI. Best for summarizing articles, documents, or long content without server costs.【F:includes/tools/class-wp-mcp-ai-tool-client-summarize-text.php†L17-L150】
- **Client Analyze Sentiment** (`client_analyze_sentiment`) analyzes the sentiment (positive or negative) of text using browser-native AI. Processes instantly without server round-trip.【F:includes/tools/class-wp-mcp-ai-tool-client-analyze-sentiment.php†L17-L100】
- **Client Extract Entities** (`client_extract_entities`) extracts named entities (people, places, organizations, etc.) from text using browser-native AI. Processes instantly without server round-trip.【F:includes/tools/class-wp-mcp-ai-tool-client-extract-entities.php†L17-L150】
- **Client Translate Text** (`client_translate_text`) translates text between 200+ languages using browser-native AI. Processes instantly without server round-trip. Supports all major world languages.【F:includes/tools/class-wp-mcp-ai-tool-client-translate-text.php†L17-L150】
- **Client Question Answering** (`client_question_answering`) extracts answers to questions from provided context using browser-native AI. Processes instantly without server round-trip.【F:includes/tools/class-wp-mcp-ai-tool-client-question-answering.php†L17-L150】
- **Client Semantic Search** (`client_semantic_search`) generates 384-dimensional text embeddings for semantic search using browser-native AI. Processes instantly without server round-trip, creating vectors suitable for cosine-similarity matching.【F:includes/tools/class-wp-mcp-ai-tool-client-semantic-search.php†L17-L150】

---

## Yahoo Fantasy Football toolkit

These tools require the Fantasy Football addon (`addons/fantasy-football/`) and Yahoo Fantasy Sports API credentials.

- **Yahoo FF Auth** (`yahoo_ff_auth`) initiates Yahoo Fantasy Sports API OAuth authentication. Generates an authorization URL for users to grant access to their fantasy football leagues. Returns OAuth status and stores credentials for subsequent tool calls.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-auth.php†L17-L200】
- **Yahoo FF Get Leagues** (`yahoo_ff_get_leagues`) retrieves the user's fantasy football leagues from Yahoo Fantasy Sports. Returns league details including name, ID, season, scoring type, and standings.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-leagues.php†L17-L150】
- **Yahoo FF Get Roster** (`yahoo_ff_get_roster`) retrieves a team roster from a Yahoo Fantasy Football league. Returns player details, positions, and current lineup status.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-roster.php†L17-L150】
- **Yahoo FF Get Player Stats** (`yahoo_ff_get_player_stats`) retrieves player statistics from Yahoo Fantasy Sports API including weekly and season stats with fantasy point totals.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-player-stats.php†L17-L150】
- **Yahoo FF Trade Analyzer** (`yahoo_ff_trade_analyzer`) analyzes fantasy football trade proposals by comparing player statistics and projections. Generates visual comparison charts showing fantasy points, trends, and trade value assessment.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-trade-analyzer.php†L17-L200】
- **Yahoo FF League Standings** (`yahoo_ff_league_standings`) retrieves league standings from Yahoo Fantasy Football and generates interactive visualizations showing team rankings, points scored, and win-loss records.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-league-standings.php†L17-L200】
- **FF Generate Team Logo** (`ff_generate_team_logo`) generates a custom team logo for a fantasy football team using AI image generation. Creates professional, sports-themed logos based on team name and preferences.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-ff-generate-team-logo.php†L17-L200】
- **FF Create League Report** (`ff_create_league_report`) creates a comprehensive league report with standings, team statistics, and analysis. Generates a formatted HTML or PDF document with charts and insights.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-ff-create-league-report.php†L17-L250】
- **FF Player Research** (`ff_player_research`) researches fantasy football players by name, position, or team. Compares statistics, views injury reports, checks expert rankings, and can add players to a watchlist.【F:addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-ff-player-research.php†L17-L200】

---

## Newsletter plugin integration

These tools require the [Newsletter plugin](https://wordpress.org/plugins/newsletter/) to be active.

- **Newsletter Add Subscriber** (`newsletter_add_subscriber`) adds a new email subscriber to the Newsletter plugin. Supports name, list assignments, and custom fields.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-add-subscriber.php†L17-L150】
- **Newsletter Get Subscribers** (`newsletter_get_subscribers`) retrieves Newsletter plugin subscribers with optional filtering by status, list, and search query. Returns paginated subscriber records.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-get-subscribers.php†L17-L150】
- **Newsletter Unsubscribe** (`newsletter_unsubscribe`) unsubscribes or removes a subscriber from the Newsletter plugin by email or subscriber ID.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-unsubscribe.php†L17-L100】
- **Newsletter Get Subscriber Stats** (`newsletter_get_subscriber_stats`) provides a statistical overview of Newsletter plugin subscribers including counts by status and lists.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-get-subscriber-stats.php†L17-L150】
- **Newsletter Create Email** (`newsletter_create_email`) creates a new newsletter email campaign with subject, content, and scheduling settings.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-create-email.php†L17-L200】
- **Newsletter Get Emails** (`newsletter_get_emails`) retrieves newsletter email campaigns with optional filtering by status and search. Returns paginated campaign records.【F:includes/tools/class-wp-mcp-ai-tool-newsletter-get-emails.php†L17-L150】

---

## WP All Import / WP All Export integration

These tools require the [WP All Import](https://wordpress.org/plugins/wp-all-import/) or [WP All Export](https://wordpress.org/plugins/wp-all-export/) plugins to be active.

- **List All Export Templates** (`list_all_export_templates`) returns a list of WP All Export templates configured on the site. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-list-all-export-templates.php†L17-L100】
- **Trigger All Export** (`trigger_all_export`) triggers a WP All Export template to execute and generate an export file. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-trigger-all-export.php†L17-L150】
- **List All Import Templates** (`list_all_import_templates`) returns a list of WP All Import templates configured on the site. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-list-all-import-templates.php†L17-L100】
- **Trigger All Import** (`trigger_all_import`) triggers a WP All Import template to execute and import data. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-trigger-all-import.php†L17-L150】
- **Get All Import Status** (`get_all_import_status`) gets the status and progress of a running WP All Import operation including percentage complete and records processed.【F:includes/tools/class-wp-mcp-ai-tool-get-all-import-status.php†L17-L100】

---

## Flowhub cannabis dispensary integration

These tools require a Flowhub API key configured in **Settings → NV oOS → Integrations → Flowhub**.

- **Flowhub Get Inventory** (`flowhub_get_inventory`) retrieves cannabis inventory data from Flowhub including packages, quantities, locations, and product details. Supports filtering by room and pagination.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php†L17-L200】
- **Flowhub Get Orders** (`flowhub_get_orders`) retrieves order and transaction data from Flowhub including sales, returns, customer details, and order status. Supports filtering and pagination.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-get-orders.php†L17-L200】
- **Flowhub Create Order** (`flowhub_create_order`) creates a new order/transaction in Flowhub. Supports sales orders with customer information, line items, payment details, and compliance tracking.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-create-order.php†L17-L200】
- **Flowhub Get Customers** (`flowhub_get_customers`) retrieves customer profiles from Flowhub including contact information, purchase history, loyalty data, and medical cannabis credentials. Supports search and pagination.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-get-customers.php†L17-L200】
- **Flowhub Manage Customer** (`flowhub_manage_customer`) creates or updates customer profiles in Flowhub. Supports managing contact information, medical cannabis credentials, loyalty data, and preferences.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-manage-customer.php†L17-L200】
- **Flowhub Get Products** (`flowhub_get_products`) retrieves the cannabis product catalog from Flowhub including strains, concentrates, edibles, and accessories with pricing, descriptions, THC/CBD content, and compliance information.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-get-products.php†L17-L200】
- **Flowhub Manage Product** (`flowhub_manage_product`) creates or updates cannabis products in Flowhub. Supports managing product details, pricing, THC/CBD content, categories, and compliance information.【F:includes/tools/class-wp-mcp-ai-tool-flowhub-manage-product.php†L17-L200】

---

## PayHere payment gateway integration

- **PayHere Get Payment** (`payhere_get_payment`) retrieves payment transaction details from the PayHere payment gateway by order ID. Returns payment status, customer details, amounts, fees, and payment method information. Requires a PayHere API key configured in **Settings → NV oOS → Integrations → PayHere**.【F:includes/tools/class-wp-mcp-ai-tool-payhere-get-payment.php†L17-L150】

---

## Erlang C queuing theory tools

The Erlang C formula is a teletraffic engineering model used to compute the probability that a visitor must wait in a queue, the minimum agents needed to meet a service-level target (e.g., "80% answered within 20 s"), and predicted average wait times under given load. All four tools ship in the base plugin (no Pro addon required) and use pure PHP math with no external dependencies.

The shared `WP_MCP_AI_Erlang_C` helper class (`includes/class-wp-mcp-ai-erlang-c.php`) exposes:
- `erlang_c( $agents, $traffic_intensity )` — probability of waiting
- `avg_wait_time( $agents, $arrival_rate, $avg_handle_time )` — expected queue wait in seconds
- `min_agents_for_service_level( $arrival_rate, $avg_handle_time, $target_seconds, $target_pct )` — staff-to-SLA solver
- `service_level( $agents, $arrival_rate, $avg_handle_time, $target_seconds )` — achieved SLA %

### Base tools (always available)

- **Calculate Erlang C** (`calculate_erlang_c`) is a standalone staffing calculator the AI assistant can invoke to answer any queue or staffing question. **Inputs:** `arrival_rate` (calls/chats per hour), `avg_handle_time` (seconds), `num_agents`, `target_service_level_seconds`, `target_service_level_pct`. **Outputs:** `agents_needed`, `probability_wait`, `avg_wait_time_seconds`, `utilization`, `service_level_achieved`. Requires `edit_posts`. Industry default is the 80/20 standard (80% answered in ≤ 20 s).【F:includes/tools/class-wp-mcp-ai-tool-calculate-erlang-c.php†L1-L300】
- **Erlang C Concurrency Advisor** (`erlang_c_concurrency_advisor`) reads the plugin's own session arrival-rate counters and transcript-duration averages, runs Erlang C, and returns a recommended number of concurrent AI sessions to configure plus a probability-of-waiting score. Gives site admins data-driven guidance on the **Max Concurrent Sessions** setting. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-erlang-c-concurrency-advisor.php†L1-L300】

### Extended tools (optional WFM/contact-centre endpoint)

- **Erlang C Staffing Advisor** (`erlang_c_staffing_advisor`) is a higher-level staffing recommendation tool combining Erlang C with multi-channel factors (chat × 2–4 concurrency multiplier, voice × 1) and a bot-containment-rate adjustment so deflected volume never reaches the human-agent calculation. Optionally pulls live queue stats from NICE WFM, Genesys, Verint, or Calabrio REST endpoints (configure the URL and bearer token in **Settings → NV oOS → Integrations → WFM**). Returns a structured staffing recommendation card. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-erlang-c-staffing-advisor.php†L1-L400】
- **Erlang C Queue Health** (`erlang_c_queue_health`) polls a configured contact-centre REST endpoint (or uses JetEngine CCT data) for current queue depth and available agents, runs Erlang C to compute the live service-level percentage, fires the `wp_mcp_ai_queue_alert` action when SLA is at risk, and stores a snapshot in a JetEngine CCT for trend reporting. Designed for real-time operations monitoring. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-erlang-c-queue-health.php†L1-L400】

---

## Conversation import

Imports external AI conversation exports into the JetEngine `ai_chat_transcripts` CCT — one row per conversation — with format detection, idempotent dedupe, background-queue execution, GDPR export/erase coverage, and optional memory mining. Supports ChatGPT `conversations.json` (including ZIP archives), Google Takeout Gemini activity, Claude `conversations.jsonl`, ShareGPT datasets, and OpenAI fine-tuning JSONL. Requires JetEngine (Full version). See the [user guide](../../user-guides/conversation-import.md) and [implementation plan](../../project/plans/CONVERSATION-IMPORT-CCT-IMPLEMENTATION-PLAN.md).【F:includes/conversation-import/README.md†L1-L150】

- **Detect Conversation Import Format** (`conversation_import_detect`) inspects an export file (path or media attachment ID) and reports the detected platform, byte size, and estimated conversation count without importing anything. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-conversation-import-detect.php†L1-L220】
- **Import Conversations to CCT** (`conversation_import_run`) runs an import with dry-run previews, `skip`/`refresh` dedupe policies, batch sizing, limits, image sideloading (`sideload_media`), and checkpoint resume tokens. One CCT row is written per conversation; provenance lives in row metadata. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-conversation-import-run.php†L1-L280】
- **Get Conversation Import Status** (`conversation_import_status`) returns the checkpoint status of a running import by its run token. Requires `manage_options`.【F:includes/tools/class-wp-mcp-ai-tool-conversation-import-status.php†L1-L180】
- **Delete Imported Conversations** (`conversation_import_delete`) deletes imported rows scoped by platform (and optionally importing user), with dry-run counting and a 500-row safety cap. Requires `manage_options`; irreversible.【F:includes/tools/class-wp-mcp-ai-tool-conversation-import-delete.php†L1-L220】

---

Each tool automatically inherits the assistant context and authentication details passed through the REST layer, allowing developers to compose complex workflows or replace default behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】