# Built-in tool reference

WP oOS registers a suite of default tools through the central registry so every assistant can opt-in without custom code. The registry initialises on `plugins_loaded`, loads the bundled implementations, and exposes extension hooks for third parties to add their own integrations.【F:includes/class-wp-mcp-ai-tool-registry.php†L12-L124】【F:includes/tools/tools-init.php†L12-L14】

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
- **Get Rank Math SEO Overview** (`get_rankmath_seo`) inspects Rank Math SEO settings for a post, returning focus keywords, SEO scores, robots directives, schema configuration, and accessibility helpers when the Rank Math plugin is active.【F:includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php†L15-L220】

## Media generation and transcription

- **Generate OpenAI Image** (`generate_openai_image`) calls the Images API with configurable defaults (model, size, quality, response format), saves the binary to the Media Library, and lets assistants override prompt, size, quality, timeout, and filename options per request. GPT-Image-1 now forwards the configured response format so assistants can request hosted URLs instead of base64 payloads without extra filters.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L1177】【F:includes/class-wp-mcp-ai-openai-client.php†L16-L33】
- **Generate Gemini Image** (`generate_gemini_image`) renders images with Google’s Gemini multimodal endpoint, supporting aspect-ratio and MIME controls plus optional timeout overrides before saving the attachment to the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php†L17-L200】
- **Generate OpenAI Speech** (`generate_openai_speech`) converts supplied text into audio, honouring the default speech model, voice, and format configured in the settings screen while allowing overrides and enforcing authenticated access.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L983-L1110】
- **Transcribe OpenAI Audio** (`transcribe_openai_audio`) accepts uploaded audio attachments up to 25 MB, forwards them to OpenAI for transcription or translation, and returns structured responses with language, duration, and segment data.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】
- **Edit Gemini Image** (`edit_gemini_image`) edits an existing image using Gemini Nano Banana (text + image-to-image) and stores the result in the Media Library, enabling AI-powered image modifications.【F:includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php†L17-L200】
- **Generate Veo Video** (`generate_veo_video`) generates realistic videos from text descriptions using Google's Veo models. Automatically uses Veo 3.1 (preferred) with fallback to Veo 2.0 if quota limits are reached. Supports async mode for long-running video generation tasks.【F:includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php†L17-L300】
- **Check Video Status** (`check_video_status`) checks the status of an async video generation job. Use this to poll for completion after calling generate_veo_video in async mode.【F:includes/tools/class-wp-mcp-ai-tool-check-video-status.php†L17-L150】
- **Generate Music** (`generate_music`) generates instrumental music from a text description using Google Gemini Lyria model with controls for genre, mood, duration, and tempo, and saves the result to the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-music.php†L17-L200】


## Image manipulation (Graphic Editor Suite)

- **Resize Image** (`resize_image`) resizes an image to specific dimensions or scales proportionally while maintaining aspect ratio. Supports width, height, and percentage-based scaling.【F:includes/tools/class-wp-mcp-ai-tool-resize-image.php†L17-L150】
- **Crop Image** (`crop_image`) crops an image to a specific region defined by coordinates and dimensions, or to a target aspect ratio.【F:includes/tools/class-wp-mcp-ai-tool-crop-image.php†L17-L150】
- **Rotate Image** (`rotate_image`) rotates an image by degrees or flips it horizontally/vertically.【F:includes/tools/class-wp-mcp-ai-tool-rotate-image.php†L17-L150】
- **Convert Image Format** (`convert_image_format`) converts an image to a different format (PNG, JPEG, WebP, GIF) with optional quality control.【F:includes/tools/class-wp-mcp-ai-tool-convert-image-format.php†L17-L150】
- **Remove Background** (`remove_background`) removes the background from an image, making it transparent. Supports two methods: free (Python rembg library, requires `pip3 install rembg pillow`) and paid (remove.bg API, requires API key from https://www.remove.bg/api). The `method` parameter allows selection: "auto" (default, tries free first then paid), "free" (rembg only), or "paid" (remove.bg API only). Automatically detects Python availability and rembg installation status. When using the free method, requires Python 3.x and the rembg package installed on the server. The paid method requires a remove.bg API key configured in Settings → WP oOS → Tools → External Tools.【F:includes/tools/class-wp-mcp-ai-tool-remove-background.php†L17-L370】

## AI-powered media analysis

- **Generate Image Alt Text** (`generate_image_alt_text`) generates descriptive alt text for images to improve accessibility and SEO using AI vision capabilities.【F:includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text.php†L17-L150】
- **Generate Image Caption** (`generate_image_caption`) generates detailed captions for images to provide context and enhance content using AI vision capabilities.【F:includes/tools/class-wp-mcp-ai-tool-generate-image-caption.php†L17-L150】
- **Analyze Video** (`analyze_video`) analyzes video content to extract information, describe scenes, identify objects, and provide insights using AI vision models with video understanding capabilities.【F:includes/tools/class-wp-mcp-ai-tool-analyze-video.php†L17-L200】
- **Generate Video Caption** (`generate_video_caption`) generates concise, descriptive captions for videos to provide context and enhance accessibility using AI vision models.【F:includes/tools/class-wp-mcp-ai-tool-generate-video-caption.php†L17-L150】
- **Extract Video Frames** (`extract_video_frames`) extracts specific frames from a video file at given timestamps or intervals. Useful for detailed analysis of specific moments or creating thumbnails.【F:includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php†L17-L150】
- **Get Video Metadata** (`get_video_metadata`) retrieves detailed technical metadata about a video file including duration, dimensions, format, codecs, bitrate, and frame rate.【F:includes/tools/class-wp-mcp-ai-tool-get-video-metadata.php†L17-L150】
- **Analyze Comment Content** (`analyze_comment_content`) analyzes comment content for spam, toxicity, and moderation concerns using AI to assist with comment moderation.【F:includes/tools/class-wp-mcp-ai-tool-analyze-comment-content.php†L17-L150】

## Data visualization

- **Create Chart** (`create_chart`) creates interactive charts using Chart.js. Supports bar, line, pie, doughnut, radar, and polar area charts. Returns HTML/JavaScript or saves as attachment.【F:includes/tools/class-wp-mcp-ai-tool-create-chart.php†L17-L300】

## External data and automations

- **Run OpenAI External Action** (`run_openai_external_action`) triggers preconfigured OpenAI workflows or assistants through the Responses API, performing payload sanitisation, timeout handling, and capability checks that restrict access to administrators.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】
- **Run Crawl4AI Job** (`run_crawl4ai_job`) executes Crawl4AI collections locally or via remote endpoints, collating Markdown, HTML, and error metadata so assistants can ingest large batches of URLs in a single invocation. Remote submissions now return an immediate task token while the new background crawler schedules WP-Cron polling so results are cached and surfaced to the assistant once Crawl4AI finishes processing.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】
- **Crawl4AI Wholesale Price Lookup** (`crawl4ai_price_lookup`) queries the Crawl4AI web search endpoint against BJ’s, Sam’s Club, and Costco domains, extracting pricing snippets and normalised metadata for authorised merchandising reviews.【F:includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php†L17-L189】
- **Get QuickBooks Report** (`quickbooks_report`) requests Profit and Loss, Balance Sheet, or any supported QuickBooks Online report using the configured company ID and bearer token. Optional `start_date`, `end_date`, `accounting_method`, and `minor_version` arguments provide fine-grained control while enforcing multisite membership and a filterable capability requirement before contacting the QuickBooks API.【F:includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php†L15-L214】
- **Lookup Import Duty** (`get_import_duty`) calls the ITA Tariff Rates API to locate tariff lines for HS codes or free-form descriptions, returning duty percentages, effective dates, and commodity notes for supported countries.【F:includes/tools/class-wp-mcp-ai-tool-get-import-duty.php†L15-L152】
- **Google Analytics Report** (`google_analytics_report`) exchanges configured OAuth credentials for an Analytics Data API token, then runs GA4 reports with metrics, dimensions, ordering, and aggregation controls tailored to the assistant request.【F:includes/tools/class-wp-mcp-ai-tool-get-google-analytics-report.php†L15-L158】
- **Google Business Insights** (`get_google_business_insights`) issues Business Profile insights requests with metric selections, time ranges, and timezone hints, returning normalised totals for the specified location resource.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-business-insights.php†L15-L149】
- **Meta Social Insights** (`get_facebook_instagram_insights`) pulls Facebook Page or Instagram business analytics through the Graph API, supporting custom metric sets, aggregation periods, and optional since/until boundaries.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php†L15-L146】
- **LinkedIn Insights** (`get_linkedin_insights`) requests organisational share statistics from the LinkedIn Marketing API with optional timeframe start/end values and granularity controls to track campaign performance.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php†L15-L138】
- **TikTok Insights** (`get_tiktok_insights`) contacts the TikTok Open API to retrieve account-level metrics, allowing optional ISO time windows and aggregation granularity for growth monitoring.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php†L15-L136】
- **Get GDACS Events** (`get_gdacs_events`) queries the Global Disaster Alert and Coordination System for tropical cyclone and flood alerts, supporting optional date filters while enforcing authenticated access. The response now advertises GPT-5 as the recommended chat model so assistants can automatically opt into the higher-capacity reasoning tier when analysing the feed.【F:includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php†L12-L200】
- **Get NHC Active Storms** (`get_nhc_active_storms`) calls the National Hurricane Center `CurrentStorms.json` endpoint, applies site membership and capability checks, and returns a sanitised array of the active storm payload for assistants that need the latest advisory snapshot.【F:includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php†L15-L146】
- **Web Search** (`web_search`) performs lightweight lookups against the configured provider (DuckDuckGo Instant Answer or Brave Search), normalises related topic results when available, and enforces per-user permission and result-count limits for safe research flows.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L320】
- **Create Google Calendar Event** (`create_google_calendar_event`) validates event timing, attendee, and reminder data before calling the Google Calendar API with either a supplied OAuth token or a signed service-account assertion, exposing filters so sites can control capabilities, calendar defaults, and request timeouts.【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L17-L378】【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L380-L688】
- **Search Gmail Messages** (`search_gmail`) exchanges the configured refresh token for an access token, issues Gmail query requests with optional label filters or pagination, and returns normalised sender, subject, snippet, and message URLs while logging transport failures for administrators.【F:includes/tools/class-wp-mcp-ai-tool-search-gmail.php†L1-L200】【F:includes/tools/class-wp-mcp-ai-tool-search-gmail.php†L200-L400】
- **Search ReliefWeb Reports** (`reliefweb_reports`) queries ReliefWeb's humanitarian dataset by country or disaster type, supports optional keyword filtering, and normalises report metadata including URLs, sources, and publication timestamps.【F:includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php†L15-L234】
- **Get Open-Meteo Forecast** (`get_open_meteo_forecast`) retrieves hourly weather data from the Open-Meteo API with coordinate, forecast window, timezone, and variable controls, then sanitises units and readings for downstream prompts.【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L15-L213】【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L215-L309】
- **Publish Meta Social Post** (`post_facebook_instagram`) publishes content to Facebook Pages or Instagram business accounts using the Graph API, handling captions, links, photos, and structured error reporting for administrators.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php†L15-L170】
- **Publish Google Business Update** (`post_google_business_update`) creates Google Business Profile local posts with summaries, language codes, and optional call-to-action buttons, logging API responses for audit trails.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-google-business-update.php†L15-L168】
- **Publish LinkedIn Update** (`post_linkedin_update`) submits LinkedIn UGC posts on behalf of members or organisations with optional share URLs, capability filters, and structured error messages from the Marketing API.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-linkedin-update.php†L15-L160】
- **Publish TikTok Video** (`post_tiktok_video`) uploads externally hosted video assets to TikTok’s Open API share endpoint with optional captions, returning publish identifiers and status metadata for follow-up actions.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-tiktok-video.php†L15-L152】
- **Generic REST API** (`generic_rest`) provides a flexible HTTP client for AI assistants to integrate with any REST API endpoint, including plugins without explicit integrations. Supports all standard HTTP methods (GET, POST, PUT, PATCH, DELETE), custom headers, JSON or form-data request bodies, query parameters, and multiple authentication types (bearer token, basic auth, custom header auth). Enforces security controls including `manage_options` capability requirement, URL validation, blocking of localhost and private IP addresses (configurable via filter), and response size limits. Use this tool when you need to call external APIs, third-party services, or WordPress plugin REST endpoints that do not have dedicated tools.【F:includes/tools/class-wp-mcp-ai-tool-generic-rest.php†L17-L560】

## JetEngine REST utilities

- **List JetEngine REST Routes** (`list_jetengine_rest_routes`) returns metadata about JetEngine’s REST namespace, including method, callback, and capability guidance for each bundled route. Access is limited to users with `manage_options` permissions.【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】
- **Invoke JetEngine REST Route** (`invoke_jetengine_route`) proxies CRUD operations to JetEngine controllers using the authenticated user context, validates required identifiers and instance keys, and supports REST or HTTP fallbacks when routes are unavailable.【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】【F:includes/class-wp-mcp-ai-jetengine-tool-handlers.php†L12-L213】 When the helper falls back to an HTTP request it forwards all cookies from the operator's session to the JetEngine endpoint so hosts with strict cookie policies can account for the behaviour in their governance controls.【F:includes/class-wp-mcp-ai-jetengine-tool-handlers.php†L330-L367】

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
- **Send Group Email** (`send_group_email`) orchestrates group email campaigns through WordPress `wp_mail()`, supporting both JSON and plain text email definitions uploaded as attachments. Accepts subject, message, recipients (as strings or objects with email/name fields), CC, BCC, custom headers, and sender overrides. Enforces configurable capability requirements (default: `publish_posts`) and recipient limits (default: 100). Features automatic deduplication, header injection prevention, attachment size validation (1MB limit), and extensive filter hooks for customization. Recipients can be combined from multiple attachments, with messages concatenated and addresses deduplicated across TO/CC/BCC fields. Full documentation: [`docs/send-group-email-usage.md`](send-group-email-usage.md).【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L650】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L169-L170】【F:tests/test-send-group-email-tool.php†L1-L445】
- **Send Mailjet Email** (`send_mailjet_email`) delivers transactional mail through Mailjet, honouring capability filters, configured sender defaults, and optional CC/BCC or reply-to overrides. Requests are logged, sanitised, and return Mailjet status payloads so assistants can confirm delivery or surface actionable error details.【F:includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php†L19-L405】
- **Send Telegram Message** (`send_telegram_message`) posts formatted updates to Telegram chats or channels using bot credentials, honours capability filters, strips unsafe markup, and records request/response metadata for operational visibility.【F:includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php†L16-L232】【F:includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php†L234-L314】
- **Send WhatsApp Message** (`send_whatsapp_message`) sends text messages through the WhatsApp Cloud API with preview controls, recipient sanitisation, and detailed error handling for capability-restricted operators.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php†L15-L178】
- **Schedule Notify.lk SMS** (`schedule_notify_sms`) queues Notify.lk SMS payloads for future delivery, resolving natural-language schedule times, storing contact metadata, and handing execution off to a dedicated WP-Cron processor.【F:includes/tools/class-wp-mcp-ai-tool-schedule-notify-sms.php†L15-L220】
- **Get System Logs** (`get_system_logs`) aggregates recent WP oOS logger entries, tails the WordPress debug/PHP error logs, and scans plugin directories for readable `.log` files so administrators can diagnose issues without leaving the assistant workflow.【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L12-L352】【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L353-L668】
- **Get MCP Environment Status** (`get_environment_status`) compiles the WordPress version, PHP version, plugin defaults, assistant counts, supported dependency status, and configuration warnings so operators can triage live incidents quickly.【F:includes/tools/class-wp-mcp-ai-tool-get-environment-status.php†L12-L178】
- **Get Update Status** (`get_update_status`) requires the `update_core` capability and returns pending WordPress core, plugin, and theme updates with versions, download URLs, and optional component-type filtering so assistants can prioritise maintenance tasks programmatically.【F:includes/tools/class-wp-mcp-ai-tool-get-update-status.php†L12-L182】
- **Get Site Health Status** (`get_site_health`) bootstraps the WordPress Site Health API to run direct and asynchronous diagnostics, grouping results into critical, warning, and pass buckets with sanitised descriptions, actionable recommendations, and extracted follow-up links for operators who can `view_site_health_checks`.【F:includes/tools/class-wp-mcp-ai-tool-get-site-health.php†L12-L255】
- **Check Site Security** (`check_site_security`) performs comprehensive security audits specifically focused on risks related to using this AI plugin. The tool checks for HTTPS configuration, debug mode in production, file editing permissions, default admin usernames, WordPress version vulnerabilities, SSL verification settings, forced SSL admin, and database prefix security. Results are categorised into critical issues, warnings, and passing checks, with an overall risk level assessment (safe, low, medium, high, critical). The tool automatically runs during plugin activation and displays admin notices for high-risk or critical configurations, warning administrators that the plugin handles sensitive AI API keys and should not be enabled on insecure sites. Administrators can bypass the activation check with `define( 'WP_MCP_AI_SKIP_SECURITY_CHECK', true );` in wp-config.php if they understand the risks. Requires `manage_options` capability.【F:includes/tools/class-wp-mcp-ai-tool-check-site-security.php†L12-L492】
- **Probe Assistant Chat** (`probe_chat`) invokes the REST controller with the probe flag so administrators can confirm assistant visibility, message sanitisation, and configuration health without triggering a paid completion request.【F:includes/tools/class-wp-mcp-ai-tool-probe-chat.php†L12-L178】
- **Probe Remote MCP REST** (`probe_remote_mcp`) wraps the CLI remote tester, exercising `/assistants` and `/chat` on external MCP deployments while forwarding bearer, guest, or nonce credentials for side-by-side troubleshooting from the live site.【F:includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php†L12-L164】【F:includes/class-wp-mcp-ai-cli-command.php†L137-L280】
- **Open OpenAI Logs** (`open_openai_logs`) and **Open OpenAI Usage** (`open_openai_usage`) surface dashboard links so administrators can audit provider activity without leaving the assistant interface.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】
- **Create WPCode Snippet** (`create_wpcode_snippet`) provisions or updates WPCode-managed snippets, validating code types, auto-insert locations, and capabilities before calling the WPCode API. The response returns activation status, the resolved location label, and the shortcode that operators can embed in content.【F:includes/tools/class-wp-mcp-ai-tool-create-wpcode-snippet.php†L15-L224】
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

## GitHub integration tools

- **List GitHub Repositories** (`list_github_repositories`) lists GitHub repositories for the authenticated user. Requires a GitHub personal access token to be configured.【F:includes/tools/class-wp-mcp-ai-tool-list-github-repositories.php†L17-L150】
- **GitHub Repository Operations** (`github_repository_operations`) performs GitHub repository operations such as creating branches and managing files in the custom-tools directory. Supports CRUD operations on repository content.【F:includes/tools/class-wp-mcp-ai-tool-github-repository-operations.php†L17-L300】
- **Manage GitHub Codespace** (`manage_github_codespace`) creates, starts, stops, or lists GitHub Codespaces for repository development. Enables AI-assisted development environment management.【F:includes/tools/class-wp-mcp-ai-tool-manage-github-codespace.php†L17-L200】

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

### Product Price Discovery (Pro)

- **Lookup Product Price** (`lookup_product_price`) provides multi-source product price discovery and comparison. Works like Google Lens Shopping, Amazon Visual Search, and browser price comparison extensions. Accepts product images via Google Cloud Vision identification. Processes documents (invoices/quotes) in PDF, Word, Excel, TXT, CSV formats with LLM-powered line item extraction. Supports single URLs or batch URLs for multi-retailer price comparison. Extracts pricing from Schema.org structured data, CSS selectors, or regex patterns. Supports multiple retailers (Amazon, Walmart, eBay, Target) with extensible filter system. Returns normalized pricing data including currency, availability, and timestamps. Requires Crawl4AI integration. Optional Google Cloud Vision for image recognition. See comprehensive guide: [Product Price Lookup Guide](PRODUCT-PRICE-LOOKUP-GUIDE.md).【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-lookup-product-price.php†L17-L400】


Each tool automatically inherits the assistant context and authentication details passed through the REST layer, allowing developers to compose complex workflows or replace default behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】
