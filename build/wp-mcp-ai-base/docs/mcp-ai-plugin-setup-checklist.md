# WP oOS Plugin Setup Checklist

Use this checklist to confirm the plugin is configured and ready for production use.

## Configuration
- Enter the OpenAI API key under **Settings → WP oOS → OpenAI API Key** so requests authenticate correctly.
- Provide the Gemini API key in **Settings → WP oOS → Gemini API Key** when you plan to route conversations through Google’s models.
- Review or change the default model in **Settings → WP oOS → Default Model**. The plugin ships with `gpt-4o-mini` selected.
- Pick a default Gemini model in **Settings → WP oOS → Default Gemini Model** before activating Gemini-powered assistants.
- Choose the global default provider in **Settings → WP oOS → Default Provider** so new assistants inherit the preferred vendor.
- Adjust **Settings → WP oOS → Request Timeout** (minimum 5 seconds, default 30 seconds) to match your host limits.
- Lock down the attachment allowlist with **Settings → WP oOS → Attachments** if your compliance rules require stricter MIME types. The default profile accepts Markdown, CSV/TSV, HTML, JSON/JSONL/NDJSON, XML, PDFs, Microsoft Office documents, AAC/FLAC/M4A/MP3/OGG/OPUS/WAV/WEBM audio, and MP4 or QuickTime video, and enabling JSON Lines support automatically registers the `.jsonl`/`.ndjson` extensions with WordPress for uploads.【F:includes/class-wp-mcp-ai-message-attachments.php†L642-L703】【F:wp-mcp-ai.php†L236-L272】
- Configure the **Settings → WP oOS → Group Email Capability** and **Group Email Recipient Limit** fields to control who can trigger bulk emails and how many recipients each request may include.
- Supply the QuickBooks Online company ID and API key in **Settings → WP oOS → QuickBooks Company ID / API Key** so finance-focused assistants can fetch reports when permitted.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L955】
- Enter the Mailjet API key, secret, and sender defaults at **Settings → WP oOS → Mailjet API Key / Mailjet API Secret / Mailjet From Email / Mailjet From Name** to enable Mailjet-powered automations.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1008-L1054】
- Set the defaults for generated speech audio under **Settings → WP oOS → OpenAI Speech Model / Default Speech Voice / Default Speech Format** so text-to-speech results match your publishing requirements.
- Tune the OpenAI image defaults under **Settings → WP oOS → OpenAI Image Model / Size / Quality / Response Format** before exposing image generation to assistants.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L1177】
- Set a fallback assistant in **Settings → WP oOS → Default Assistant** for shortcode or REST calls that omit an explicit assistant ID.
- Decide whether to enable request logging at **Settings → WP oOS → Enable Logging** for diagnostics.
- Choose an uninstall behavior in **Settings → WP oOS → Remove Data on Uninstall** if you want plugin data purged when the plugin is removed.

## Authentication & Security
- For remote MCP assistants, provision Auth0 bearer tokens with the API audience and scopes that match the plugin settings. Same-origin UIs continue using the WordPress REST nonce.
- When partners cannot consume Auth0, generate assistant-specific credentials from the *API Credentials* meta box, deliver the one-time token securely, and document who received it for future revocation or rotation.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】【F:includes/class-wp-mcp-ai-credentials.php†L94-L185】
- Before sharing new credentials, run `wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=...` (adding `--guest-token` or `--nonce` when applicable) to confirm the remote site recognises the token, that SSL and timeouts behave as expected, and that a chat probe succeeds.【F:includes/class-wp-mcp-ai-cli-command.php†L137-L280】【F:includes/class-wp-mcp-ai-remote-tester.php†L29-L331】
- Familiarize yourself with the structured REST errors returned by the plugin so clients can present actionable remediation guidance when authentication fails.
- If public visitors need access, enable `allow_guests="true"` on the chat surface and confirm the generated one-hour guest tokens reach the REST API via the `X-WP-MCP-AI-Guest` header or `guest_token` parameter so capability checks downgrade to `public` safely.【F:includes/class-wp-mcp-ai-shortcode.php†L31-L331】【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】

## Assistant Content & Tools
- For each AI Assistant post, curate the allowed tools (core, WooCommerce, JetEngine, or custom), set the assistant defaults (model, temperature, system prompt), and attach any media knowledge or vector store IDs needed for retrieval workflows.
- Remember that REST or shortcode requests without an explicit `assistant` parameter fall back to the default assistant configured earlier.
- Decide whether public visitors should chat with the assistant by enabling `allow_guests="true"` on the shortcode or the Elementor widget when appropriate.
- Confirm any headless integrations can call both `POST /wp-json/mcp-ai/v1/chat` and `POST /wp-json/mcp-ai/v1/tools`, handle structured errors, and supply the authentication scheme outlined in [docs/rest-api.md](rest-api.md).【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L931-L1321】

## Optional Local Development Setup
- Run `docker compose up -d` to start the local environment, then complete the standard WordPress installation at `http://localhost:8000`.
- Execute `bin/setup-dev.sh` to install development dependencies.
- Use the provided Composer scripts for linting, compatibility checks, formatting, translation generation, and PHPUnit tests during development.
