# Pro Tool Classes

## Purpose

~55 Pro tool classes and 2 shared traits that implement the MCP tool contract for CPT operations, Elementor, WooCommerce, Shopify, JetEngine, QuickBooks, GitHub, social media posting, email (Brevo/Mailjet/Mailgun), chat channels, scheduling, image validation, and site creation — every Pro-tier tool that is not owned by a per-toolkit `tools/` subfolder.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` → per-toolkit init files — most tools are auto-registered when their parent toolkit flag is enabled; some are loaded unconditionally (CPT, generic REST, update-option, install plugins/themes) |
| **Optional dependencies** | Elementor (`ELEMENTOR_VERSION`), WooCommerce (`class_exists('WooCommerce')`), JetEngine (`function_exists('jet_engine')`), Shopify (API credentials), QuickBooks, GitHub (OAuth), Brevo/Mailjet/Mailgun (API keys) — each tool self-reports `is_available()` and returns a clear `unavailable_reason` when its dependency is missing |

## Public Surface

All classes implement `WP_MCP_AI_Tool_Interface`. Most also implement `WP_MCP_AI_Tool_Capability_Flags_Interface` for self-describing capability tags (`pro`, `write`, `read-only`, `external-api`, `requires-plugin`, etc.).

| Tool class | Domain |
|---|---|
| `WP_MCP_AI_Pro_Tool_CPT` | Generic CPT CRUD (list, get, create, update, delete, bulk-create) |
| `WP_MCP_AI_Pro_Tool_Elementor` | Elementor template listing/search |
| `WP_MCP_AI_Pro_Tool_Woo_Products` | WooCommerce product CRUD |
| `WP_MCP_AI_Pro_Tool_Woo_Orders` | WooCommerce order queries |
| `WP_MCP_AI_Pro_Tool_Woo_Customers` | WooCommerce customer queries |
| `WP_MCP_AI_Pro_Tool_Woo_Coupons` | WooCommerce coupon CRUD |
| `WP_MCP_AI_Pro_Tool_Shopify_Products` | Shopify product sync |
| `WP_MCP_AI_Pro_Tool_Shopify_Orders` | Shopify order queries |
| `WP_MCP_AI_Pro_Tool_Shopify_Customers` | Shopify customer queries |
| `WP_MCP_AI_Pro_Tool_Shopify_Catalog` | Shopify catalog sync |
| `WP_MCP_AI_Pro_Tool_Shopify_Inventory` | Shopify inventory management |
| `WP_MCP_AI_Pro_Tool_Jetengine_*` (8 classes) | JetEngine CCT, post-type, taxonomy, meta-field, relations, prompts, site-context, MCP bridge |
| `WP_MCP_AI_Pro_Tool_GitHub_Repository_Operations` | GitHub repo CRUD |
| `WP_MCP_AI_Pro_Tool_List_GitHub_Repositories` | List GitHub repos |
| `WP_MCP_AI_Pro_Tool_Manage_GitHub_Codespace` | GitHub Codespace management |
| `WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync` | QuickBooks Desktop sync |
| `WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report` | QuickBooks report queries |
| `WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram` | Facebook/Instagram posting |
| `WP_MCP_AI_Pro_Tool_Post_LinkedIn_Update` | LinkedIn posting |
| `WP_MCP_AI_Pro_Tool_Post_TikTok_Video` | TikTok video posting |
| `WP_MCP_AI_Pro_Tool_Post_Google_Business_Update` | Google Business Profile posting |
| `WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights` | Facebook/Instagram analytics |
| `WP_MCP_AI_Pro_Tool_Get_LinkedIn_Insights` | LinkedIn analytics |
| `WP_MCP_AI_Pro_Tool_Get_TikTok_Insights` | TikTok analytics |
| `WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report` | Google Analytics reporting |
| `WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights` | Google Business insights |
| `WP_MCP_AI_Pro_Tool_Send_Brevo_Email` / `Send_Mailjet_Email` / `Send_Mailgun_Email` | Email sending |
| `WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics` / `Get_Mailjet_Statistics` | Email analytics |
| `WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts` / `Manage_Mailjet_Contacts` | Email contact management |
| `WP_MCP_AI_Pro_Tool_Send_Telegram_Message` / `Send_WhatsApp_Message` | Chat messaging |
| `WP_MCP_AI_Pro_Tool_Search_Gmail` / `Search_Drive` | Google Workspace search |
| `WP_MCP_AI_Pro_Tool_Product_Actualization` | Woo product actualization |
| `WP_MCP_AI_Pro_Tool_Lookup_Product_Price` | Product price lookup |
| `WP_MCP_AI_Pro_Tool_Validate_Image_For_Product` / `_Vehicle` | Image validation |
| `WP_MCP_AI_Pro_Tool_Site_Creator` | Site creation wizard |
| `WP_MCP_AI_Pro_Tool_Schedule_Notify_SMS` | SMS schedule notifications |
| `WP_MCP_AI_Pro_Tool_Generic_REST` | Generic REST API caller |
| `WP_MCP_AI_Pro_Tool_Update_Option` | WordPress option update |
| `WP_MCP_AI_Pro_Tool_Install_And_Activate_Plugin` / `_Theme` | Plugin/theme installer |
| `WP_MCP_AI_Pro_Tool_Create_WPCode_Snippet` | WPCode snippet creation |
| `WP_MCP_AI_Pro_Tool_Create_Google_Calendar_Event` | Google Calendar event creation |
| `WP_MCP_AI_Pro_Tool_Get_Import_Duty` | Import duty lookup |
| `WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images` / `_Instagram_*` / `_Google_Maps_*` | Image download tools |

Traits: `Trait_WP_MCP_AI_Shopify_Connection_Resolver`, `Trait_WP_MCP_AI_Shopify_Smart_Search`.

## Inputs / Outputs / Neighbors

- **Reads from:** tool arguments (sanitized at entry per the two-gate rule), `wp_mcp_ai_settings`, per-integration API key options, WordPress post meta / options / transients, third-party API responses.
- **Writes to:** WordPress DB (posts, post meta, options, terms), external APIs (Shopify, QuickBooks, GitHub, social platforms, email services), the WP MCP AI event log.
- **Upstream callers:** the global tool registry (`WP_MCP_AI_Tool_Registry`), MCP server controllers, chat REST, slash commands, CLI, scheduled jobs.
- **Downstream collaborators:** external REST APIs (HTTP calls via `wp_remote_*`), `WP_MCP_AI_Logger`, the Shopify traits for connection resolution.
- **Events fired:** per-tool capability filters (e.g. `wp_mcp_ai_send_discord_message_capability`), `wp_mcp_ai_broadcast_message` action.
- **Events listened to:** none — tools are passive executors.

## Conventions

- **Every tool MUST implement `WP_MCP_AI_Tool_Interface`.** The `get_slug()`, `get_name()`, `get_description()`, `get_parameters_schema()`, and `execute()` methods form the canonical MCP contract.
- **Canonical envelope:** `execute()` returns `array` (success) or `WP_Error` (failure). Never return `array( 'success' => false, ... )` — use `WP_Error` for failures.  Enforced by PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope`.
- **Two-gate sanitisation:** Sanitize every `$arguments[...]` at entry; escape every value at exit. Enforced by `WPMCPAI.Tools.SanitizeAtEntry`.
- **Optional-dependency tools** MUST implement `is_available()` (static) and return `false` with a clear `get_unavailable_reason()` when the dependency is missing. The registry skips unavailable tools.
- **Capability flags** SHOULD be declared via `WP_MCP_AI_Tool_Capability_Flags_Interface::get_capability_flags()`. Standard flags: `pro`, `read-only`, `write`, `external-api`, `network-dependent`, `requires-plugin`, `requires-capability`, `local-only`.
- **Shopify tools** MUST use the shared traits (`Connection_Resolver`, `Smart_Search`) instead of duplicating connection logic.

## Tests

```bash
# Shopify
vendor/bin/phpunit addons/pro/tests/test-shopify-connection-resolver.php
vendor/bin/phpunit addons/pro/tests/test-shopify-smart-search.php

# JetEngine
vendor/bin/phpunit addons/pro/tests/test-jetengine-mcp-bridge-tool.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-cpt-taxonomy-integration.php

# WooCommerce
vendor/bin/phpunit tests/test-create-woo-product-validated-tool.php
vendor/bin/phpunit tests/test-create-woo-variable-product.php

# QuickBooks
vendor/bin/phpunit addons/pro/tests/test-quickbooks-desktop-sync.php

# Image validation
vendor/bin/phpunit addons/pro/tests/test-vehicle-estimation-tools.php

# Site Creator
vendor/bin/phpunit addons/pro/tests/test-seed-template-library-tool.php
vendor/bin/phpunit addons/pro/tests/test-tool-extract-site-design-from-mockups.php
```

Additional Pro tool coverage lives in `addons/pro/tests/tools/` and scattered across the Base test suite for cross-cutting tool scenarios.

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — capability checks, external API sanitisation (always)
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration, availability, capability flags
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro tool placement vs Base tools
- [`CLAUDE.md`](../../../../../CLAUDE.md) — canonical envelope + two-gate sanitisation + PHP 8.1+

## See Also

- Sub-folder: [`ChatChannels/`](./ChatChannels/) — 48 chat-channel tool implementations
- Sibling: [`addons/pro/includes/tools/`](../../tools/) — per-toolkit tool libraries
- Base tools: [`includes/tools/`](../../../../../includes/tools/) — ~195 Base tool implementations
- Registry: [`includes/class-wp-mcp-ai-tool-registry.php`](../../../../../includes/class-wp-mcp-ai-tool-registry.php)
