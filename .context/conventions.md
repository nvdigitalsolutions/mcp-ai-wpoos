# NV oOS Coding Conventions

> **GSD Context File** — Load this at the start of every AI development session.
> Keep this file under 500 lines. Last reviewed: August 2026.

---

## PHP Compatibility Requirements

NV oOS has **two PHP version targets** — always match the target to the code location:

| Distribution | Minimum PHP | Why |
|-------------|-------------|-----|
| **Base plugin** (`includes/`, `mcp-ai-wpoos.php`) | **PHP 7.4+** | WordPress.org compatibility; widest host support |
| **Pro addon** (`addons/pro/`) | **PHP 8.1+** | Uses enums, fibers, `readonly` properties, named args, intersection types |

### Practical Rules

- **Base plugin code:** must run on PHP 7.4. No enums, no named arguments, no `readonly`, no union types `int|string` (use PHPDoc instead).
- **Pro addon code:** may use PHP 8.1+ features freely.
- The `composer run lint:compat` check covers PHP 7.4–8.3 for the base plugin.
- `CONTRIBUTING.md` asks contributors to have **PHP 8.1+** locally — this is so they can work on both tiers without switching runtimes.
- When writing a PRD/Architecture Spec, the "Compatibility" field should read **PHP 7.4+** for base features or **PHP 8.1+** for Pro features.

---

## Class & Function Naming

| Type | Convention | Example |
|------|-----------|---------|
| PHP Classes | `WP_MCP_AI_{FeatureName}_{Component}` | `WP_MCP_AI_Tool_Manage_Redirects` |
| Tool Classes | `WP_MCP_AI_Tool_{ToolName}` | `WP_MCP_AI_Tool_Log_Vital_Signs` |
| PHP Functions | `wp_mcp_ai_{function_name}()` | `wp_mcp_ai_get_assistant()` |
| Action Hooks | `wp_mcp_ai_{hook_name}` | `wp_mcp_ai_register_tools` |
| Filter Hooks | `wp_mcp_ai_{filter_name}` | `wp_mcp_ai_tool_response` |
| Nonces | `wp_mcp_ai_{context}_{action}` | `wp_mcp_ai_assistant_save` |
| Option Keys | `wp_mcp_ai_{setting_name}` | `wp_mcp_ai_settings` |
| CPT Slugs | `mcp_ai_{post_type}` | `mcp_ai_assistant` |
| CCT Slugs | `{descriptive_name}` | `vitals_log`, `channel_messages` |

## File Organization

```
mcp-ai-wpoos.php                       ← Plugin entry point (no logic here)
mcp-ai-wpoos-base.php                  ← Alternate entry for base-only distribution
includes/
├── bootstrap/                         ← Boot sequence: constants → autoload → helpers
│                                        → cron → hooks → loader → activation
├── class-wp-mcp-ai-plugin.php         ← Main singleton + DI container wiring
├── class-wp-mcp-ai-container.php      ← Service locator / DI
│   ├── tools/                             ← ~303 base tools; ~303 enabled in base mode
│   ├── class-wp-mcp-ai-tool-{name}.php
│   ├── okf/                            ← OKF knowledge tools (10 tools)
│   └── orchestration/                 ← Multi-tool orchestration
├── admin/                             ← All wp-admin UI + AJAX handlers
│   ├── class-wp-mcp-ai-admin-settings.php
│   ├── class-wp-mcp-ai-admin-ajax-handlers.php
│   ├── sections/                      ← Settings tab section classes
│   └── widgets/                       ← Dashboard widget classes
├── assistants/                        ← Assistant CPT + metaboxes
├── services/                          ← Business logic (20+ service classes)
├── repositories/                      ← Data access layer
├── integrations/                  ← JetEngine, Elementor, Auth0, ChatKit
├── analytics/                     ← Shared Analytics Service (Pro, v1.1.53)
├── infrastructure/                ← HTTP client, options-store, provider adapters
├── okf/                                ← OKF engine (parser, reader, writer)
├── interfaces/                        ← PHP interfaces (OptionsStore, HttpClient…)
├── knowledge-base/                    ← KB documents, professions, playbooks
├── blocks/                            ← WordPress blocks (chat, tools-grid…)
├── bundled-skills/                    ← SKILL.md files (MCP, PDF, Excel, video…)
└── helpers/                           ← Utility helpers
addons/pro/
├── mcp-ai-wpoos-pro.php               ← Pro entry (no WP plugin header in repo)
└── includes/
    ├── tools/                         ← 1,232+ pro tool classes (same naming convention)
    ├── tools/{category}/              ← Categorized pro tools
    ├── admin/                         ← Pro admin pages
    ├── rest/                          ← Pro REST controllers
    ├── integrations/                  ← WooCommerce, Shopify, social media, Google
    └── services/                      ← Pro-specific services
assets/
├── js/                                ← 100+ JS files; *.min.js served
│   ├── chat.js                        ← Main chat UI
│   ├── admin-settings.js              ← Settings page JS
│   └── vendor/                        ← Vendored JS (chart.js, vectorizer…)
└── css/                               ← Styles; *.min.css served
packages/                              ← 9 standalone NPM packages
```

## Key Constants

| Constant | Default | Effect |
|---|---|---|
| `WP_MCP_AI_BASE_VERSION` | `true` | `true` = ~303 base tools; `false` = ~1,552 total |
| `WP_MCP_AI_FILE` | (plugin file path) | Used by lifecycle hooks — do not redefine |
| `WP_MCP_AI_PRO_VERSION` | set by Pro at boot | Prevents double-loading of Pro addon |
| `WP_DEBUG` | WordPress default | Enables extra error logging throughout |

To test base-only mode in a clone (where Pro is auto-loaded):
```php
define( 'WP_MCP_AI_BASE_VERSION', true );  // in wp-config.php
```

## Build Commands

### PHP (run before every PR)
```bash
composer run lint:base        # PHPCS – base plugin only
composer run lint             # PHPCS – full codebase including Pro
composer run lint:compat      # PHP 7.4–8.3 compatibility
composer run format           # PHPCBF – auto-fix style issues
composer run test:install     # One-time: install WordPress PHPUnit test suite
composer run test             # PHPUnit – full test suite
composer run ci:all           # lint (errors-only) + test:coverage (CI entry point)
```

### JavaScript / CSS
```bash
npm run build                 # CSS + base JS + Pro JS (production, minified)
npm run build:full            # build + Workflow Builder + TMA React builds
npm run lint:js               # ESLint on assets/js/**/*.js
npm run lint:js:fix           # Auto-fix JS lint issues
npm test                      # Jest unit tests
```

> **Before a PR:** `composer run lint:base && composer run test`
> **Full CI check:** `composer run ci:all && npm run build`

## Base vs Pro

| | Base plugin | Pro addon |
|---|---|---|
| **Entry point** | `mcp-ai-wpoos.php` | `addons/pro/mcp-ai-wpoos-pro.php` |
| **Tools** | ~303 core tools | +~1,249 Pro tools = **~1,552 total** |
| **Control constant** | `WP_MCP_AI_BASE_VERSION=true` | `WP_MCP_AI_BASE_VERSION=false` |
| **PHP minimum** | 7.4 | 8.1 |
| **PHP vendor** | `vendor/` (root) | `addons/pro/vendor/` (phpspreadsheet etc.) |
| **JS build config** | `esbuild.config.js` | `esbuild.config.pro.js` |
| **React builds** | none | Workflow Builder, TMA templates |
| **License** | GPLv3 | Proprietary |

Pro-exclusive feature areas: WooCommerce, JetEngine CCTs, social media channels (Slack/Discord/Teams/Telegram/WhatsApp/Instagram), Google services, GitHub, media processing (FFmpeg, DICOM), multi-agent orchestration, health & wellness (27 tools), finance/ERP, Telegram Mini Apps.

**How Pro auto-loads in a clone:** `addons/pro/mcp-ai-wpoos-pro.php` has no WordPress plugin header, so WordPress doesn't see it as a plugin. `wp_mcp_ai_maybe_load_pro_addon()` in `class-wp-mcp-ai-plugin.php` detects the file and requires it automatically — no manual activation needed.

## PHP Code Style

- **Standard:** WordPress Coding Standards (WPCS) — zero violations
- **Indentation:** Tabs (not spaces)
- **Braces:** Opening brace on same line for functions/methods, Allman style for classes
- **Line length:** 120 characters max
- **PHP tags:** Full `<?php` only, never short tags

## PHPDoc Requirements

Every class, method, and function **must** have a PHPDoc block:

```php
/**
 * Brief description of what this does.
 *
 * Longer description if needed.
 *
 * @since 1.x.x
 * @param string $param_name Description of parameter.
 * @param int    $another     Another parameter.
 * @return array|WP_Error Result or error.
 */
```

### Third-Party Attribution Header (when applicable)

If a file is **derived from**, **heavily inspired by**, or **wraps** an upstream open-source project, add `@link` and `@credit` tags to the file-level docblock so the source of the idea or code is preserved:

```php
/**
 * Class summary.
 *
 * @link    <upstream URL>
 * @credit  <upstream project name> by <author> (<license>)
 * @package WP_MCP_AI
 */
```

Use this pattern for:

- Wrappers around vendored libraries (e.g. Strudel, Cytoscape, Konva integration glue).
- Files that materially derive from an upstream project (e.g. the agent-memory subsystem citing MemPalace).
- Tool classes that adapt or implement an external protocol or specification.

Do **not** apply this header to trivial utility files that merely call a vendored library — one citation at the top of the wrapper file is enough. The full repo-wide attribution index lives in [`CREDITS.md`](../CREDITS.md) at the repository root.

## Security Requirements (Always Apply)

### Input Sanitization
```php
sanitize_text_field( $input )      // General strings
absint( $input )                    // Positive integers
intval( $input )                    // Any integers
sanitize_email( $input )            // Email addresses
esc_url_raw( $input )               // URLs for storage
wp_kses_post( $input )              // HTML content (post-safe)
wp_kses( $input, $allowed_html )    // HTML content (custom allowed)
sanitize_key( $input )              // Option keys, slugs
```

### Output Escaping
```php
esc_html( $value )                  // Plain text output
esc_attr( $value )                  // HTML attribute values
esc_url( $url )                     // URLs in href/src
esc_js( $value )                    // Inline JavaScript
wp_json_encode( $data )             // JSON output
wp_kses_post( $content )            // HTML content output
```

### Capability Checks
```php
if ( ! current_user_can( 'manage_options' ) ) {
    return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
}
```

### Nonce Verification
```php
// In form/AJAX handler:
check_ajax_referer( 'wp_mcp_ai_assistant_save', 'nonce' );
// Or:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wp_mcp_ai_action' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
}
```

### ABSPATH Guard (All Non-Root PHP Files)
```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

## WordPress APIs

### Data Retrieval (Always Use WP APIs)
```php
get_option( 'wp_mcp_ai_settings', array() )
get_post_meta( $post_id, '_meta_key', true )
get_posts( array( 'post_type' => 'mcp_ai_assistant', ... ) )
```

### Internationalization
```php
__( 'String', 'mcp-ai-wpoos' )
esc_html__( 'String', 'mcp-ai-wpoos' )
_e( 'String', 'mcp-ai-wpoos' )
sprintf( __( 'Hello %s', 'mcp-ai-wpoos' ), esc_html( $name ) )
```

### Database (Direct Queries via $wpdb)
```php
global $wpdb;
$results = $wpdb->get_results(
    $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
);
```

## Tool Implementation Pattern

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tool: example_tool — Brief description.
 *
 * @package MCP_AI_WPooS
 * @since   1.x.x
 */
class WP_MCP_AI_Tool_Example_Tool extends WP_MCP_AI_Tool_Base {

    /**
     * Returns the tool slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'example_tool';
    }

    /**
     * Returns the tool definition.
     *
     * @return array
     */
    public function get_definition() {
        return array(
            'name'                 => 'Example Tool',
            'description'          => 'Tool description for the LLM.',
            'required_capability'  => 'edit_posts',
            'parameters'           => array(
                'type'       => 'object',
                'properties' => array(
                    'action' => array(
                        'type'        => 'string',
                        'description' => 'Action to perform.',
                        'enum'        => array( 'create', 'list', 'delete' ),
                    ),
                ),
                'required' => array( 'action' ),
            ),
        );
    }

    /**
     * Executes the tool.
     *
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @return array|WP_Error
     */
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }
        // Implementation...
    }
}
```

## Tool Return Envelope (Canonical)

Every tool's `execute()` returns **exactly one of two shapes**. This is the canonical envelope landed by Phase P0 of the [Unix Theory Compliance Proposal](../docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#22-canonical-return-envelope).

```php
// SUCCESS — array with success/message/data:
return array(
    'success' => true,
    'message' => __( 'Done.', 'mcp-ai-wpoos' ),  // Translated, human-readable.
    'data'    => $payload,                        // Serialisable via wp_json_encode().
);

// FAILURE — ALWAYS WP_Error, never an array with 'success' => false:
return new WP_Error( 'error_code', __( 'Error message.', 'mcp-ai-wpoos' ), $extra_data );
```

Rules:

- ✅ Success arrays MUST include `success => true` and a translated `message`. `data` is the only pipeable field — keep it `wp_json_encode()`-safe.
- ✅ Failure MUST use `WP_Error`. The agentic loop normalises `WP_Error` correctly; observability hooks (`wp_mcp_ai_after_tool_execution`, OTel, audit log, token tracking) read `is_wp_error( $result )` to classify outcomes.
- ❌ DO NOT return `array( 'success' => false, 'message' => ... )` for errors. It is forbidden in new code; the `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff (landed in Phase P1) warns on this pattern at default severity 5 — visible under `composer run lint`, silent under `composer run lint:base`.
- 🛠️ For success shapes, compose `format_success_response( $message, $data )` from [`trait-wp-mcp-ai-tool-envelope.php`](../includes/tools/trait-wp-mcp-ai-tool-envelope.php) — `use WP_MCP_AI_Tool_Envelope;`. Tools that also need the broader chat-response behaviour (`format_chat_response`, `format_collection_response`, `format_empty_result_response`, `ensure_response_message`) should `use WP_MCP_AI_Tool_Chat_Response;` instead — it composes the envelope trait, so `format_success_response()` is identical from either path.

## Commit Message Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
refactor(scope): brief description
chore(scope): brief description
```

Examples:
- `feat(tools): add manage_redirects tool with create/list/delete actions`
- `fix(rest-api): sanitize assistant_id in chat endpoint`
- `test(tools): add PHPUnit tests for manage_redirects tool`

## Version Locations (Must All Match)

When bumping the version, update ALL of these:
1. `mcp-ai-wpoos.php` — plugin header `Version:`
2. `mcp-ai-wpoos-base.php` — plugin header `Version:` (if exists)
3. `composer.json` — `"version"` field
4. `package.json` — `"version"` field
5. Constant: `WP_MCP_AI_VERSION`
