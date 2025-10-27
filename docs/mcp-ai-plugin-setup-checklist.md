# MCP AI Plugin Setup Checklist

Use this checklist to confirm the plugin is configured and ready for production use.

## Configuration
- Enter the OpenAI API key under **Settings → MCP AI → OpenAI API Key** so requests authenticate correctly.
- Provide the Gemini API key in **Settings → MCP AI → Gemini API Key** when you plan to route conversations through Google’s models.
- Review or change the default model in **Settings → MCP AI → Default Model**. The plugin ships with `gpt-4o-mini` selected.
- Pick a default Gemini model in **Settings → MCP AI → Default Gemini Model** before activating Gemini-powered assistants.
- Choose the global default provider in **Settings → MCP AI → Default Provider** so new assistants inherit the preferred vendor.
- Adjust **Settings → MCP AI → Request Timeout** (minimum 5 seconds, default 30 seconds) to match your host limits.
- Lock down the attachment allowlist with **Settings → MCP AI → Attachments** if your compliance rules require stricter MIME types.
- Set a fallback assistant in **Settings → MCP AI → Default Assistant** for shortcode or REST calls that omit an explicit assistant ID.
- Decide whether to enable request logging at **Settings → MCP AI → Enable Logging** for diagnostics.
- Choose an uninstall behavior in **Settings → MCP AI → Remove Data on Uninstall** if you want plugin data purged when the plugin is removed.

## Authentication & Security
- For remote MCP assistants, provision Auth0 bearer tokens with the API audience and scopes that match the plugin settings. Same-origin UIs continue using the WordPress REST nonce.
- Familiarize yourself with the structured REST errors returned by the plugin so clients can present actionable remediation guidance when authentication fails.

## Assistant Content & Tools
- For each AI Assistant post, curate the allowed tools (core, WooCommerce, JetEngine, or custom), set the assistant defaults (model, temperature, system prompt), and attach any media knowledge or vector store IDs needed for retrieval workflows.
- Remember that REST or shortcode requests without an explicit `assistant` parameter fall back to the default assistant configured earlier.
- Decide whether public visitors should chat with the assistant by enabling `allow_guests="true"` on the shortcode, the Elementor widget, or the Deep Chat component when appropriate.

## Optional Local Development Setup
- Run `docker compose up -d` to start the local environment, then complete the standard WordPress installation at `http://localhost:8000`.
- Execute `bin/setup-dev.sh` to install development dependencies.
- Use the provided Composer scripts for linting, compatibility checks, formatting, translation generation, and PHPUnit tests during development.
