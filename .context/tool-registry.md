# NV oOS Tool Registry Context

> **GSD Context File** — Load this when working on tool implementations, toolkits, MCP servers, or OKF tools.
> Last reviewed: August 28, 2026 (v1.1.65).

---

## Tool Registry Overview

Tools are the core extensibility unit of NV oOS. Each tool:
- Extends `WP_MCP_AI_Tool_Base` (or `WP_MCP_AI_Tool_Interface`)
- Has a unique slug (snake_case)
- Declares a `required_capability`
- Implements `execute( $arguments, $context )`
- Is registered in `includes/tools-init.php` (base) or `addons/pro/mcp-ai-wpoos-pro.php` (pro)

**Total tools:** ~1,559 (~303 base + ~1,256 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

**New in v1.1.65:** No tools added or removed — four tool-adjacent fixes. (1) Graphify `sync_remote_source` returns the canonical `WP_Error` envelope (never `array( 'success' => false, ... )`) and the remote-source crypto treats provider-prefixed `*_key` fields as sensitive. (2) `paper_store_import`/`paper_store_export` guard a missing `collection` argument (`isset()` before `sanitize_key()`). (3) `remote_wp_connection` error messages instruct callers to run `list_connections` first when a connection ID is missing/invalid. (4) `WP_MCP_AI_Token_Budget_Manager` falls back to the bundled model catalog (longest-prefix match for date-versioned IDs) when the rate-limits CCT has no entry — TPM limits work without JetEngine. Also: `research_model` checks the provider `WP_Error` before reading the model.

**New in v1.1.64:** +7 Pro tools. (1) Six Google Calendar tools in `addons/pro/includes/tools/google-workspace/` (`list_google_calendars`, `list_google_calendar_events`, `update_google_calendar_event`, `delete_google_calendar_event`, `check_google_calendar_availability`, `quick_add_google_calendar_event`) plus a reworked `create_google_calendar_event` (moved from `includes/src/Tools/`) — all resolve credentials via the shared `includes/google/` foundation (optional `connection_id` → Pro Remote Sites, else site-level Google Calendar settings) with scope enforcement from `WP_MCP_AI_Google_Calendar_Scopes`. (2) `composio_manage_accounts` (7th Composio tool, `manage_options`, `risk_level: high`, `destructive`): validate/reconnect/delete/prune for connected-account lifecycle. Also: the **Non-Loggable Result Fields** interface (see below) landed in-commit and the logger's redactor now masks credential-bearing URL query params. No base tools added or removed.

**New in v1.1.63:** No new tools — two registry-adjacent fixes. (1) DeepSeek rejects tool schemas whose `properties` encode as a JSON array: schema normalization must keep object-valued property maps as objects and convert empty maps to `stdClass` so every payload boundary (REST `/tools` output in `WP_MCP_AI_REST`, `WP_MCP_AI_Tool_Service`, `ChatOrchestrator`) encodes `"properties": {}` — never `"properties": []`. (2) Legacy-format tool classes are wrapped **before** the first `register_tool()` attempt (both base and Pro registration paths), so the registry's fail-loud "missing interface" log only fires for classes that genuinely fail to register. Tool counts unchanged: ~303 base + ~1,249 Pro (~1,552 total).

**New in v1.1.62:** OKF tool surface grows to 10 tools — three new base tools (`okf_list_bundles`, `okf_validate_bundle`, `okf_import_bundle`, all in `includes/tools/okf/`) back the new `WP_MCP_AI_OKF_Bundle_Manager` lifecycle (list/create/rename/archive/delete, ZipSlip-safe import/export, health stats) plus the provenance schema extension on `okf_write_concept` (`resource`/`sources`/`usage_window`/`verified`). Two new Pro tools — `okf_enrich_site_content` (`manage_options`) and `route_knowledge_query` (`read`), registered on `wp_mcp_ai_bootstrapped` priority 36 via the `pro_okf_skill_bridge` + `pro_okf_enrichment` modules. Presets updated: `essentials_internal` gains `okf_list_bundles` + `okf_validate_bundle`; `files_documents` gains all three new base tools. Vector store tools (`create_vector_store`, `list_vector_stores`, `get_vector_store`, `manage_vector_store_files` in both the WP client and `lib/core`) migrated to the Responses API — no more `OpenAI-Beta: assistants=v2` header; `file_batches` ingestion with bounded polling + single-file fallback.

**New in v1.1.61:** No new tools — behavior change in the memory tools. `store_agent_context` now resolves virtual / non-numeric agent keys to the canonical assistant post ID via `WP_MCP_AI_Agent_Identity_Resolver` (alias map `wp_mcp_ai_agent_id_aliases`, bounded, never autoloaded) and echoes `original_agent_id` / `agent_id_resolved` in its envelope; the `wp_mcp_ai_memory_stored` action payload carries the same fields. `wake_up_context` degrades to the transient retrieval path when the Graphify memory bridge errors (advisory use only).

**New in v1.1.60:** Four JetEngine-gated conversation-import tools — `conversation_import_detect`, `conversation_import_run`, `conversation_import_status`, `conversation_import_delete` (`includes/tools/`, `manage_options`, unavailable without JetEngine) — import external conversation exports into the `ai_chat_transcripts` CCT. Tool argument schemas are now normalized before being embedded in provider payloads (DeepSeek client, REST `/tools` schema output, `WP_MCP_AI_Tool_Service`, and the OOS `ChatOrchestrator`), preventing provider streaming failures from non-compliant schemas; registrations skipped for a missing tool contract are logged.

**New in v1.1.59:** Two read-only base discovery tools — `list_terms` and `list_taxonomies` (`includes/tools/`, wordpress-core group) — companions to `create_term`/`update_term`. ~32 previously-orphaned base tool files are now registered (2FA setup, content freshness, batch tools, omni-video, SEO/image optimizers, Site Kit integrations…). Legacy-format tool classes (pre-interface) are transparently wrapped by `WP_MCP_AI_Legacy_Tool_Wrapper` + `WP_MCP_AI_Tool_Legacy_Definition` (`includes/tools/`), so old-style `get_definition()`/`execute()` classes register without refactoring. New **`wp_mcp_ai_tools_init`** action fires after default + third-party registration so side-loaders (e.g. `includes/orchestration-init.php`) can register late. The registry tracks availability-check failures in `unavailable_tool_slugs` (alongside the existing `unavailable_tool_messages`), letting callers distinguish "known but unavailable" from "never registered".

**New in August 2026 (v1.1.58):** Composio Connect — 6 beta tools under `addons/pro/includes/tools/composio/` (`composio_list_tools`, `composio_get_tool_schema`, `composio_list_connected_accounts`, `composio_create_connect_link`, `composio_execute_tool`, `composio_manage_triggers`) backed by the `addons/pro/includes/composio/` subsystem (OAuth auth handler, API client, trigger bridge, signed webhook controller). OOS engine scoping: `ToolScope` / `ToolRestriction` domain objects (`lib/core`) + Pro composition subsystem (`addons/pro/includes/composition/`) — see [`.context/oos-engine.md`](oos-engine.md) and [`.context/tool-registry.md`](tool-registry.md).

**New in August 2026:** `list_mcp_tools` discovery tool (234 lines) — enables AI agent self-discovery of all available MCP tools. Returns tool names, descriptions, and JSON Schema parameter definitions. Filterable by toolkit namespace and search term. Design System tool preset (`design-system`) — 72 tools across 13 categories.

---

## Per-Toolkit MCP Servers (v1.1.40 — 33 servers)

Each Pro toolkit can expose its tools as an independent MCP JSON-RPC endpoint. **33 servers** are registered (up from 29), including 4 new Phase 8 servers:

| Server | Slug | Tools | Backend |
|--------|------|-------|---------|
| Pro Scheduler | `pro-scheduler` | 14 | WP-Cron + Schedule Manager |
| FlowHub Sync | `flowhub` | 6 | Action Scheduler + CCT cache |
| Shopify Sync | `shopify-sync` | 5 | Action Scheduler + CCT cache |
| EZuite ERP Sync | `ezuite` | 6 | Action Scheduler + CCT cache |

Shared infrastructure:
- **`ScheduledToolkitServerTrait`** — sync-interval, sync-status, connection-health, limit annotations.
- **OAuth 2.0** — PKCE flow, hierarchical scopes (`mcp:read`/`mcp:write`), browser-based login, token management UI.
- **Per-tool scope annotations** — `compute_tool_scopes()` marks each tool as `read_only` or `read_write`.

Reference: `addons/pro/includes/mcp-servers/README.md`, `docs/project/proposals/pro-toolkit-mcp-servers-expansion-plan.md`.

---

## Tool Presets System (v1.1.40)

Tools can be grouped into presets organized in a layered hierarchy:

- **Base Layer** → **Essentials Layer** → **Extended Layer** → **Specialist Layer**. Additive; assigning essentials auto-includes base.
- **Deduplication** — within-layer, cross-layer, and assistant-level. No tool appears twice in the final list.
- **Auto-upgrade** — validated tool variants automatically replace non-validated versions when available.
- **Tool payload cap** — 100 tools per assistant (raised from 50).
- Tools without `tool_call_id` in DeepSeek streaming are now handled: always included in `extract_request_messages` fallback; stripped from conversation when missing.

Reference: `docs/features/tool-presets-system.md`.

---

## File Locations

| Type | Directory | Registration File |
|------|-----------|------------------|
| Base tools | `includes/tools/class-wp-mcp-ai-tool-{name}.php` | `includes/tools-init.php` |
| Base categorized | `includes/tools/{category}/class-wp-mcp-ai-tool-{name}.php` | `includes/{category}-init.php` or `includes/okf/okf-init.php` |
| Pro tools | `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-{name}.php` | `addons/pro/mcp-ai-wpoos-pro.php` |
| Pro categorized | `addons/pro/includes/tools/{category}/class-wp-mcp-ai-tool-{name}.php` | `addons/pro/mcp-ai-wpoos-pro.php` |
| MCP servers | `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-{name}-mcp-server.php` | `addons/pro/includes/mcp-servers/mcp-servers-init.php` |

---

## Minimal Tool Skeleton

```php
<?php
/**
 * Tool: {slug} — {Brief description}.
 *
 * @package MCP_AI_WPooS
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * {ClassName} — {Brief description}.
 *
 * @since 1.x.x
 */
class WP_MCP_AI_Tool_{Name} extends WP_MCP_AI_Tool_Base {

    /**
     * Returns the tool slug.
     *
     * @return string
     */
    public function get_slug() {
        return '{slug}';
    }

    /**
     * Returns the tool definition for the LLM.
     *
     * @return array
     */
    public function get_definition() {
        return array(
            'name'                => '{Display Name}',
            'description'         => '{Description for the LLM model}.',
            'required_capability' => '{wp_capability}',
            'parameters'          => array(
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
     * @param array $arguments Tool arguments from the LLM.
     * @param array $context   Execution context (user_id, assistant, etc.).
     * @return array|WP_Error Result array or WP_Error on failure.
     */
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }

        $action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

        switch ( $action ) {
            case 'create':
                return $this->handle_create( $arguments );
            case 'list':
                return $this->handle_list( $arguments );
            case 'delete':
                return $this->handle_delete( $arguments );
            default:
                return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos' ) );
        }
    }
}
```

---

## Registering a Base Tool

In `includes/tools-init.php`:

```php
// Inside the registration function:
require_once WP_MCP_AI_PLUGIN_DIR . 'includes/tools/class-wp-mcp-ai-tool-{name}.php';
$registry->register_tool( 'WP_MCP_AI_Tool_{Name}' );
```

## Registering a Pro Tool

In `addons/pro/mcp-ai-wpoos-pro.php`, in the class loader section and the tool group map:

```php
// Class loader (load_tool_classes method):
'WP_MCP_AI_Tool_{Name}' => 'includes/tools/class-wp-mcp-ai-tool-{name}.php',

// Tool group map:
'tool_slug' => array(
    'class' => 'WP_MCP_AI_Tool_{Name}',
    'group' => 'wordpress-core',  // or other group
),
```

---

## Base Version Guard

Pro-only tools must be wrapped:

```php
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
    // Register pro tool
}
```

Or check the toolkit enable flag:

```php
if ( $this->is_toolkit_enabled( 'enable_crm_toolkit' ) ) {
    // Register CRM tools
}
```

---

## Optional Data-Contract Metadata (Unix Theory P3)

Tools may implement `WP_MCP_AI_Tool_Data_Contract_Interface`
(`includes/interfaces/interface-wp-mcp-ai-tool.php`) to declare what shape
of payload they produce and which input shapes they accept:

```php
class WP_MCP_AI_Tool_Example
    extends WP_MCP_AI_Tool_Base
    implements WP_MCP_AI_Tool_Data_Contract_Interface {

    public function get_data_contract() {
        return array(
            'produces' => 'post_id',
            'consumes' => array( 'post_id', 'post_ref' ),
        );
    }
}
```

`WP_MCP_AI_Tool_Registry::get_tool_data_contract( $slug )` normalises the
values via `sanitize_key()` and dedupes them. The tool-service surface
appends the line `[Data contract: produces=X, consumes=A|B]` to the
tool's description **only** in the OpenAI function-calling payload (OpenAI
rejects unknown top-level schema keys, so we cannot use a side-channel
field). Filter `wp_mcp_ai_tool_data_contract_description_suffix` lets a
tool customise the suffix per-request.

The contract is purely advisory metadata for the model and for downstream
hint planners; it does **not** validate inputs at runtime.

---

## Non-Loggable Result Fields (capability credentials)

Most secrets are caught automatically before a log entry is persisted: the
logger's key deny-list masks `api_key` / `*_token` / `*_secret`-style keys, and
its URL redactor masks credential-bearing **query parameters**. Neither can
help when the credential *is* an opaque URL path segment, or when it sits under
an innocuous key like `url`, `link`, or `data` — `/link/lk_9XgCEUuh9JIN` is
indistinguishable from `/uploads/2026/08/image.png` to any heuristic. **Only
the tool knows its own result field is secret.**

Tools that return such values implement
`WP_MCP_AI_Tool_Sensitive_Result_Interface`
(`includes/interfaces/interface-wp-mcp-ai-tool.php`):

```php
class WP_MCP_AI_Tool_Example
    implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Sensitive_Result_Interface {

    public function get_sensitive_result_fields() {
        return array(
            'url',                                // top-level key
            'data.url',                           // nested key
            'components.plugins.*.download_url',  // any list element
        );
    }
}
```

`WP_MCP_AI_Logger::log_tool_execution()` masks every declared path with
`[redacted]` **before** `limit_result_payload()` JSON-truncates the preview
(otherwise the paths would no longer be addressable). The same paths are masked
in `arguments` and on the `tool_error` path. Declaring a container key masks its
whole subtree, which is how tools returning an unbounded third-party payload
(`composio_execute_tool` → `result`) opt out wholesale.

This affects **logging only** — the value returned to the caller and to the
model is never altered. Tools that declare nothing are completely unaffected.
Legacy-format tools reach the mechanism by exposing the same method; the
`WP_MCP_AI_Legacy_Tool_Wrapper` forwards it.

Filter `wp_mcp_ai_tool_sensitive_result_fields` ( `$declared`, `$tool_slug` ) is
additive-only, so it can shield a third-party tool that does not declare its
own fields but can never weaken a tool's own declaration.

Current declarers: `composio_create_connect_link`, `composio_manage_accounts`,
`composio_execute_tool`, `composio_manage_triggers`, `generate_booking_link`,
`vault_access`, `yahoo_ff_auth`, `get_update_status`.

---

## Deprecated Aliases (Unix Theory P5 — back-compat for decompositions)

When a multi-action mega-tool is decomposed into single-purpose tools,
register the old slug as a deprecated alias so existing transcripts and
saved playbooks keep working:

```php
$registry->register_deprecated_alias(
    'old_mega_tool_slug',          // legacy slug — kept callable
    'new_specific_tool_slug',      // canonical replacement
    array(
        'since'   => '1.3.0',      // version when alias landed
        'removal' => '1.4.0',      // version it will disappear
    )
);
```

Aliases are stored in a separate `$deprecated_aliases` map that is
**invisible to `build_tools_payload()`** — the LLM only sees the new
slug. `get_tool()` and `is_tool_registered()` thread through
`resolve_deprecated_alias()` so existing call sites just work. The action
`wp_mcp_ai_tool_deprecated_alias_invoked( $old, $new, $entry )` fires
**once per request per slug** (state reset in tests via
`reset_deprecated_alias_invocations()`), making it easy for observability
exporters to count residual usage and decide when removal is safe.

Master plan: [`docs/project/proposals/audits/P5-action-split-part-2-plan-2026-05.md`](../docs/project/proposals/audits/P5-action-split-part-2-plan-2026-05.md).

---

## Tool Return Format

Tools return **exactly one of two shapes** — the canonical envelope (see [`CLAUDE.md`](../CLAUDE.md#tool-return-format--canonical-envelope) and [`.context/conventions.md`](conventions.md#tool-return-envelope-canonical)):

```php
// Success:
return array(
    'success' => true,
    'message' => __( 'Done.', 'mcp-ai-wpoos' ),
    'data'    => $results,
);

// Error (use WP_Error — never `success => false`):
return new WP_Error( 'not_found', __( 'Resource not found.', 'mcp-ai-wpoos' ) );
```

For success responses, compose `format_success_response( $message, $data )` from [`trait-wp-mcp-ai-tool-envelope.php`](../includes/tools/trait-wp-mcp-ai-tool-envelope.php) — `use WP_MCP_AI_Tool_Envelope;` in the tool class. Tools that also need the broader chat-response helpers (collections, empty-result messages, etc.) should `use WP_MCP_AI_Tool_Chat_Response;` instead — it composes the envelope trait so `format_success_response()` is identical from either trait.

Returning `array( 'success' => false, ... )` for errors is forbidden in new code; observability subscribers (`wp_mcp_ai_after_tool_execution`, OTel, audit log, token tracking) rely on `is_wp_error( $result )` to classify outcomes. The `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff (Phase P1) warns on this pattern.

---

## Parameter Types Reference

```php
// String parameter:
array(
    'type'        => 'string',
    'description' => 'Description.',
)

// Integer parameter:
array(
    'type'        => 'integer',
    'description' => 'Numeric ID.',
    'minimum'     => 1,
)

// Boolean parameter:
array(
    'type'        => 'boolean',
    'description' => 'Whether to include X.',
    'default'     => false,
)

// Enum parameter:
array(
    'type'        => 'string',
    'description' => 'Action to perform.',
    'enum'        => array( 'create', 'list', 'delete' ),
)

// Array parameter:
array(
    'type'        => 'array',
    'description' => 'List of IDs.',
    'items'       => array( 'type' => 'integer' ),
)
```

---

## Capability Reference for Tools

| Tool Type | Recommended Capability |
|-----------|----------------------|
| Read-only public | `read` |
| Create/edit content | `edit_posts` |
| Delete content | `delete_posts` |
| Manage settings | `manage_options` |
| User management | `manage_options` |
| Medical/healthcare | Custom cap via plugin |

---

## Common Sanitization in Tool execute()

```php
$name     = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
$content  = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
$url      = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
```
