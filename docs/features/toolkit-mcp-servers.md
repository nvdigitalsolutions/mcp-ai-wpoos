# Per-Toolkit MCP Servers

> Status: Phase 0 + Phase 1 + Phase 2 shipped — all 19 Tier-1 toolkits promoted.
> ADR: [`docs/ADR_002_toolkit_mcp_servers.md`](../ADR_002_toolkit_mcp_servers.md)

Each Pro toolkit can be promoted into a first-class MCP (Model Context Protocol) server with its own JSON-RPC endpoint, capability negotiation, discovery descriptor, and per-toolkit configuration page — without disturbing the existing monolithic `/mcp-ai/v1/mcp` endpoint.

## REST endpoints

All routes live under namespace `mcp-ai-pro/v1`:

| Method | Route                            | Purpose                                                                 |
|--------|----------------------------------|-------------------------------------------------------------------------|
| `GET`  | `/mcp-ai-pro/v1/mcp`             | Descriptor list of every registered toolkit server                      |
| `GET`  | `/mcp-ai-pro/v1/mcp/{slug}`      | Single-server descriptor                                                |
| `POST` | `/mcp-ai-pro/v1/mcp/{slug}`      | JSON-RPC 2.0 entry point                                                |

Supported JSON-RPC methods (Phase 1 / Phase 2):

- `initialize`
- `ping`
- `tools/list`
- `resources/list`
- `prompts/list`

`tools/call`, `resources/read`, `prompts/get` are intentionally not yet implemented at the per-toolkit endpoint; clients should fall back to the monolithic `/mcp-ai/v1/mcp` endpoint for execution while Phase 3 lands.

## Tier-1 servers

### Phase 1 pilot servers

| Slug                   | Class                                              | Native surfaces                                                                                            | Mounted surfaces                                                  |
|------------------------|----------------------------------------------------|------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| `crm`                  | `WP_MCP_AI_CRM_MCP_Server`                         | `company-research`, `post-research`, `page-research`, `place-research` (all R&A)                          | —                                                                 |
| `health`               | `WP_MCP_AI_Healthcare_MCP_Server`                  | `member-research` (R&A), `health-records-consolidate` (C&A) — both on `mcp_ai_member`                     | —                                                                 |
| `architectural-design` | `WP_MCP_AI_Architectural_Design_MCP_Server`        | `architectural-drawing-research`, `architectural-project-research`, `architectural-specification-research` | `health-records-consolidate` mounted read-only from `health` |

### Phase 2 promotions

| Slug                       | Class                                              | Native surfaces                                                                                              |
|----------------------------|----------------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| `ai-tool-builder`          | `WP_MCP_AI_AI_Tool_Builder_MCP_Server`             | — (tools-only)                                                                                                |
| `calendar-booking`         | `WP_MCP_AI_Calendar_Booking_MCP_Server`            | `research-appointment` (R&A)                                                                                  |
| `cre-debt`                 | `WP_MCP_AI_CRE_Debt_MCP_Server`                    | `research-cre-debt` (R&A)                                                                                     |
| `dj-management`            | `WP_MCP_AI_DJ_Management_MCP_Server`               | — (tools-only)                                                                                                |
| `document-generation`      | `WP_MCP_AI_Document_Generation_MCP_Server`         | `research-document-template` (R&A)                                                                            |
| `eca`                      | `WP_MCP_AI_ECA_Management_MCP_Server`              | `research-eca` (R&A)                                                                                          |
| `ecommerce`                | `WP_MCP_AI_Ecommerce_MCP_Server`                   | `research-product` (R&A), `product-consolidate` (C&A) — both on `product`                                     |
| `financial-planner`        | `WP_MCP_AI_Financial_Planner_MCP_Server`           | `research-financial-account` (R&A)                                                                            |
| `image-production`         | `WP_MCP_AI_Image_Production_MCP_Server`            | `research-image-template` (R&A)                                                                               |
| `law-firm`                 | `WP_MCP_AI_Law_Firm_MCP_Server`                    | `research-law-firm` (R&A)                                                                                     |
| `media`                    | `WP_MCP_AI_Media_Toolkit_MCP_Server`               | `design-media` (C&A) on `attachment`                                                                          |
| `multilingual`             | `WP_MCP_AI_Multilingual_MCP_Server`                | — (tools-only)                                                                                                |
| `project-management`       | `WP_MCP_AI_Project_Management_MCP_Server`          | `research-project`, `research-task`, `research-event` (R&A); `event-consolidate` (C&A on `mcp_ai_event`)      |
| `regulatory-registration`  | `WP_MCP_AI_Regulatory_Registration_MCP_Server`     | `wp-mcp-ai-reg-product-research`, `wp-mcp-ai-reg-document-research`, `wp-mcp-ai-registration-research` (R&A)  |
| `social-media`             | `WP_MCP_AI_Social_Media_MCP_Server`                | — (tools-only)                                                                                                |
| `video-production`         | `WP_MCP_AI_Video_Production_MCP_Server`            | — (tools-only)                                                                                                |

## Registering a server

Implementations extend `WP_MCP_AI_Toolkit_Server_Base` and register through the `wp_mcp_ai_register_toolkit_servers` action:

```php
add_action(
    'wp_mcp_ai_register_toolkit_servers',
    static function ( $registry ) {
        $registry->register( new My_Toolkit_MCP_Server() );
    }
);
```

Each server must implement:

- `get_slug()` — kebab-case identifier used in REST routes.
- `get_name()` / `get_description()` / `get_version()`.
- `ingestion_surfaces()` — array of `{type, page_slug, entity_type, class_ref, label, bound_assistant_id}`.
- `mounted_surfaces()` — empty by default; override to mount foreign surfaces read-only.
- `candidate_tool_slugs()` — explicit set of tool slugs this server may surface.

## Settings page

Every toolkit settings page that extends `WP_MCP_AI_Toolkit_Settings_Base` automatically gets an **MCP Server** tab when a server is registered for that toolkit. The tab has four sections:

1. **Server** — master enable/disable switch and JSON-RPC endpoint URL.
2. **Tools** — checkbox matrix of candidate tool slugs. Empty allowlist exposes every candidate.
3. **Ingestion Surfaces — Native** — disable individual R&A or C&A pages from `tools/list` / `prompts/list` / `resources/list`.
4. **Ingestion Surfaces — Mounted** — list of foreign surfaces this toolkit consumes; admin can revoke each independently of the source.

Settings persist in option `wp_mcp_ai_toolkit_mcp_server_{slug}`.

## Cross-toolkit mounts

Architectural Design's three research pages explicitly link to Healthcare's `health-records-consolidate`. Rather than duplicate or shadow that surface, Architectural Design's MCP server **mounts** it read-only:

```php
public function mounted_surfaces() {
    return array(
        array(
            'type'                => 'consolidate_add',
            'page_slug'           => 'health-records-consolidate',
            'entity_type'         => 'mcp_ai_member',
            'class_ref'           => 'WP_MCP_AI_Health_Records_Consolidate_Page',
            'source_toolkit_slug' => 'health',
            'read_only'           => true,
        ),
    );
}
```

Effective visibility rules:

- **Consumer admin disables the mount** → suppressed on consumer; source unaffected.
- **Source admin disables its server** → suppressed on every consumer.
- **Source admin disables the underlying native surface** → suppressed on every consumer that mounts that page.
- **Assistant binding** stays with the source toolkit.

Mounted prompts appear under a `_mounted/` namespace; mounted resources use URIs of the form `nvoos://{consumer}/_mounted/{source}/{entity}`.

## Tests

- `addons/pro/tests/test-toolkit-server-contract.php` — generic contract assertions.
- `addons/pro/tests/test-ingestion-surface-parity.php` — R&A-only, C&A-only, dual-surface, multi-page shapes.
- `addons/pro/tests/test-cross-toolkit-mounts.php` — mount visibility, source-disable propagation, consumer-side suppression, binding ownership.

Run them with:

```bash
vendor/bin/phpunit --group toolkit-mcp-servers
```
