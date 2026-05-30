# Pro `src/` — Shared Pro Source Tree

> **Migration notice (May 2026):** The `src/Tools/` subdirectory has been consolidated into [`addons/pro/includes/tools/`](../tools/) as part of the Unix-theory migration (Phase 4). All ~110 integration tool files (Shopify, WooCommerce, JetEngine, social media, email, Google Workspace, ChatChannels, etc.) now live in their respective domain folders under `tools/`. The `src/Tools/` directory no longer exists.

## Purpose

Houses Pro infrastructure classes that don't fit neatly under `tools/<domain>/`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Various init files in [`addons/pro/includes/`](../) |
| **Optional dependencies** | Varies by class |

## Public Surface

The contract is the **tool slug** registered with `WP_MCP_AI_Tool_Registry`, not the class name. New Pro tools that touch a third-party SaaS should land here.

| Group | Location | Examples |
|---|---|---|
| Shopify | `Tools/class-wp-mcp-ai-pro-tool-shopify-*.php` + `Tools/trait-wp-mcp-ai-shopify-*.php` | `WP_MCP_AI_Pro_Tool_Shopify_Products`, `…_Orders`, `…_Customers`, `…_Inventory`, `…_Catalog`; smart-search + connection-resolver traits |
| WooCommerce | `Tools/class-wp-mcp-ai-pro-tool-woo-*.php` | `WP_MCP_AI_Pro_Tool_Woo_Products`, `…_Orders`, `…_Customers`, `…_Coupons` |
| JetEngine | `Tools/class-wp-mcp-ai-pro-tool-jetengine*.php` | Bridge, post-type / taxonomy / meta-field creators, relations, site-context, prompts |
| Elementor | `Tools/class-wp-mcp-ai-pro-tool-elementor.php` | Template + widget CRUD via Elementor's own API |
| Social media — publishing | `Tools/class-wp-mcp-ai-pro-tool-post-*.php` | Facebook+Instagram, TikTok, LinkedIn, Google Business |
| Social media — insights | `Tools/class-wp-mcp-ai-pro-tool-get-*-insights.php` | Facebook+Instagram, TikTok, LinkedIn, Google Business |
| Social media — image harvest | `Tools/class-wp-mcp-ai-pro-tool-download-*-images.php` | Google Maps, Facebook, Instagram |
| Email | `Tools/class-wp-mcp-ai-pro-tool-send-{brevo,mailjet,mailgun}-email.php`, `…-manage-{brevo,mailjet}-contacts.php`, `…-get-{brevo,mailjet}-statistics.php` | Brevo, Mailjet, Mailgun |
| Google Workspace | `Tools/class-wp-mcp-ai-pro-tool-{search-gmail,search-drive,create-google-calendar-event,get-google-analytics-report}.php` | Workspace + Analytics |
| Accounting / commerce ops | `Tools/class-wp-mcp-ai-pro-tool-{get-quickbooks-report,quickbooks-desktop-sync,get-import-duty,lookup-product-price,product-actualization,validate-image-for-{product,vehicle}}.php` | QuickBooks, duty, product hygiene |
| Messaging (1:1) | `Tools/class-wp-mcp-ai-pro-tool-send-{telegram,whatsapp}-message.php`, `…schedule-notify-sms.php` | Direct SMS / chat sends |
| GitHub | `Tools/class-wp-mcp-ai-pro-tool-{github-repository-operations,list-github-repositories,manage-github-codespace}.php` | Repos + Codespaces |
| Site-builder ops | `Tools/class-wp-mcp-ai-pro-tool-{site-creator,install-and-activate-plugin,install-and-activate-theme,update-option}.php` | Full-site bootstrap from chat |
| Generic infra | `Tools/class-wp-mcp-ai-pro-tool-{cpt,generic-rest,create-wpcode-snippet}.php` | Toolkit CPT CRUD, generic REST passthrough, WPCode snippet creator |
| Chat-channel tools | `Tools/ChatChannels/class-wp-mcp-ai-pro-tool-*.php` | Send + read + manage tools for Slack, Discord, Teams, Messenger, Google Chat, Apple Messages, iCloud, Telegram, WhatsApp + the unified broadcast tool |
| Chat-channel webhook handler | `ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php` | Legacy Google Chat webhook (the full controller lives under [`../rest/`](../rest/)) |
| Google service-account helper | `Tools/ChatChannels/class-wp-mcp-ai-pro-google-service-account.php` | Shared OAuth/JWT helper required by Google Chat + Drive tools |

## Inputs / Outputs / Neighbors

- **Reads from:** `$arguments` (LLM input, sanitised at entry); third-party API responses; the credential vault; remote-site connection records; Pro CPT/CCT meta
- **Writes to:** the third-party SaaS being integrated (Shopify Admin API, Woo REST, Brevo, Mailjet, Google APIs, GitHub, Telegram/WhatsApp Cloud, …); WordPress posts/users/options when a tool bridges into core; channel CCTs for chat-channel sends
- **Upstream callers:** `WP_MCP_AI_Tool_Registry::execute_tool()` via REST [`../rest/`](../rest/), CLI [`../cli/`](../cli/), slash commands [`../slash-commands/`](../slash-commands/), the agentic loop, per-toolkit MCP servers in [`../mcp-servers/`](../mcp-servers/)
- **Downstream collaborators:** [`../services/`](../services/) (HTTP clients, OAuth helpers), [`../providers/`](../providers/), [`../class-wp-mcp-ai-shopify-client.php`](../class-wp-mcp-ai-shopify-client.php), [`../class-wp-mcp-ai-upwork-client.php`](../class-wp-mcp-ai-upwork-client.php), [`../class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php), Base [`includes/interfaces/`](../../../../includes/interfaces/), Base [`includes/traits/`](../../../../includes/traits/)
- **Events fired:** the standard tool-envelope hooks plus integration-specific actions (`wp_mcp_ai_shopify_*`, `wp_mcp_ai_jetengine_*`, `wp_mcp_ai_channel_*`, …)
- **Events listened to:** the registration filters fired by `wp_mcp_ai_pro_register_tools()`; chat-channel webhook handlers in [`../rest/`](../rest/) call into these tools synchronously

## Conventions

Folder-specific deltas (canonical rules in [`.context/tool-registry.md`](../../../../.context/tool-registry.md) and [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- The directory layout uses **StudlyCase folders** (`Tools/`, `ChatChannels/`, `Tools/ChatChannels/`) so the tree already matches a future PSR-4 root. Classes themselves are still globally scoped (`WP_MCP_AI_Pro_Tool_*`) — **do not** add `namespace …;` declarations until the project switches on the PSR-4 autoloader (tracked separately; see proposal P7). File names continue to follow the kebab-case `class-wp-mcp-ai-pro-tool-{slug}.php` convention used everywhere else.
- Every class implements `WP_MCP_AI_Tool_Interface` (and `WP_MCP_AI_Tool_Capability_Flags_Interface` where relevant) from Base. Pro adds no separate interface.
- Tools registered from this folder MUST be added to the classmap in `wp_mcp_ai_pro_register_tools()` and gated behind their toolkit's setting (`enable_chat_channels_toolkit`, `enable_woocommerce_tools`, `enable_jetengine_tools`, etc.). Never auto-register at file load time.
- Shopify tools use the shared traits `WP_MCP_AI_Shopify_Connection_Resolver` and `WP_MCP_AI_Shopify_Smart_Search` (lazy-required by the bootstrap) — do not duplicate that logic per tool.
- Chat-channel tools share `WP_MCP_AI_Pro_Google_Service_Account` (under `Tools/ChatChannels/`) for OAuth/JWT — reuse it; do not reimplement service-account signing.
- The canonical return envelope and the two-gate sanitisation rule apply to every class in this tree; PHPCS sniffs `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry` are enforced.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-shopify-connection-resolver.php
vendor/bin/phpunit addons/pro/tests/test-shopify-smart-search.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-mcp-bridge-tool.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-mcp-client.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-cpt-taxonomy-integration.php
vendor/bin/phpunit addons/pro/tests/test-ai-cpt-management-integration.php
vendor/bin/phpunit addons/pro/tests/test-quickbooks-desktop-sync.php
vendor/bin/phpunit addons/pro/tests/test-vehicle-estimation-tools.php
vendor/bin/phpunit addons/pro/tests/test-shipping-tools.php
vendor/bin/phpunit addons/pro/tests/test-tool-extract-site-design-from-mockups.php
```

End-to-end registry + envelope coverage lives in the root suite under [`tests/`](../../../../tests/) (e.g. `test-tool-registry.php`, `test-tool-envelope-trait.php`).

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper + credential-handling rules (always)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical return envelope, slug rules, capability gating
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`.context/testing.md`](../../../../.context/testing.md) — how to add a tool test
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat + two-gate sanitisation

## See Also

- Sibling: [`../tools/`](../tools/) — the older flat-layout Pro tool tree (domain-specific tools live there; integration-heavy tools live here)
- Sibling surfaces: [`../rest/`](../rest/) (webhook controllers that call into `Tools/ChatChannels/` tools), [`../cli/`](../cli/), [`../slash-commands/`](../slash-commands/)
- Collaborators: [`../services/`](../services/), [`../providers/`](../providers/), [`../class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php)
- Base counterparts: [`includes/tools/`](../../../../includes/tools/), [`includes/interfaces/`](../../../../includes/interfaces/), [`includes/traits/`](../../../../includes/traits/)
