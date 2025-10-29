# Built-in tool reference

WP MCP AI registers a suite of default tools through the central registry so every assistant can opt-in without custom code. The registry initialises on `plugins_loaded`, loads the bundled implementations, and exposes extension hooks for third parties to add their own integrations.【F:includes/class-wp-mcp-ai-tool-registry.php†L12-L124】【F:includes/tools/tools-init.php†L12-L14】

## Content ingestion and retrieval

- **Submit Document Prompt** (`submit_document_prompt`) uploads one or more WordPress attachments or existing OpenAI file IDs alongside a follow-up instruction so models can reason over the supplied files. The tool validates that a prompt and at least one document were provided before assembling multimodal segments for the Responses API.【F:includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php†L20-L214】
- **Get Recent Posts** (`get_recent_posts`) returns the latest published entries for a given post type with titles, permalinks, excerpts, and publication timestamps. The executor enforces that the acting user can `read` content on the current site before performing the query.【F:includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php†L12-L104】
- **Get JetEngine Items** (`get_jetengine_items`) surfaces JetEngine managed post types when the plugin is available. It requires the caller to be able to `read` and satisfy the target post type’s `edit_posts` capability before returning item metadata.【F:includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php†L12-L118】
- **Get Recent WooCommerce Orders** (`get_woo_recent_orders`) summarises recent orders with totals, billing details, and ISO timestamps. The helper only activates when WooCommerce is loaded and restricts access to users who can manage or report on orders.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php†L12-L117】
- **Get Site Summary** (`get_site_summary`) captures the site name, description, URL, admin email, and basic content/user counts. Because the payload exposes administrative metadata, the tool requires `manage_options` access.【F:includes/tools/class-wp-mcp-ai-tool-get-site-summary.php†L12-L66】
- **Get User Information** (`get_user_info`) inspects profile data for the requested user ID (defaulting to the acting user) while respecting multisite membership and the `list_users` / `manage_options` capability checks when viewing other accounts.【F:includes/tools/class-wp-mcp-ai-tool-get-user-info.php†L12-L89】

## Media generation and transcription

- **Generate OpenAI Image** (`generate_openai_image`) calls the Images API with configurable defaults (model, size, quality, response format), saves the binary to the Media Library, and lets assistants override prompt, size, quality, timeout, and filename options per request. GPT-Image-1 now forwards the configured response format so assistants can request hosted URLs instead of base64 payloads without extra filters.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L1177】【F:includes/class-wp-mcp-ai-openai-client.php†L16-L33】
- **Generate OpenAI Speech** (`generate_openai_speech`) converts supplied text into audio, honouring the default speech model, voice, and format configured in the settings screen while allowing overrides and enforcing authenticated access.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L983-L1110】
- **Transcribe OpenAI Audio** (`transcribe_openai_audio`) accepts uploaded audio attachments up to 25 MB, forwards them to OpenAI for transcription or translation, and returns structured responses with language, duration, and segment data.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】

## External data and automations

- **Run OpenAI External Action** (`run_openai_external_action`) triggers preconfigured OpenAI workflows or assistants through the Responses API, performing payload sanitisation, timeout handling, and capability checks that restrict access to administrators.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】
- **Run Crawl4AI Job** (`run_crawl4ai_job`) executes Crawl4AI collections locally or via remote endpoints, collating Markdown, HTML, and error metadata so assistants can ingest large batches of URLs in a single invocation.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】
- **Web Search** (`web_search`) performs lightweight DuckDuckGo lookups, normalises related topic results, and enforces per-user permission and result-count limits for safe research flows.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L164】

## JetEngine REST utilities

- **List JetEngine REST Routes** (`list_jetengine_rest_routes`) returns metadata about JetEngine’s REST namespace, including method, callback, and capability guidance for each bundled route. Access is limited to users with `manage_options` permissions.【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】
- **Invoke JetEngine REST Route** (`invoke_jetengine_route`) proxies CRUD operations to JetEngine controllers using the authenticated user context, validates required identifiers and instance keys, and supports REST or HTTP fallbacks when routes are unavailable.【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】【F:includes/class-wp-mcp-ai-jetengine-tool-handlers.php†L12-L213】

## Operational helpers

- **Create Cron Job** (`create_cron_job`) sanitises hook names, schedules single-run or recurring events, and blocks duplicates so assistants can safely automate WP-Cron tasks for privileged operators.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L16-L142】
- **Send Group Email** (`send_group_email`) parses structured or free-form instructions to assemble subject lines, bodies, and recipient lists while enforcing capability and recipient limits from the admin settings.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L234】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L818-L954】
- **Open OpenAI Logs** (`open_openai_logs`) and **Open OpenAI Usage** (`open_openai_usage`) surface dashboard links so administrators can audit provider activity without leaving the assistant interface.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】

Each tool automatically inherits the assistant context and authentication details passed through the REST layer, allowing developers to compose complex workflows or replace default behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】
