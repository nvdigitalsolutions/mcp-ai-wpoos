# Per-Toolkit MCP Servers

> Status: Phase 0 + Phase 1 shipped (CRM, Healthcare, Architectural Design)
> ADR: [`docs/ADR_002_toolkit_mcp_servers.md`](../ADR_002_toolkit_mcp_servers.md)

Each Pro toolkit can be promoted into a first-class MCP (Model Context Protocol) server with its own JSON-RPC endpoint, capability negotiation, discovery descriptor, and per-toolkit configuration page — without disturbing the existing monolithic `/mcp-ai/v1/mcp` endpoint.

## REST endpoints

All routes live under namespace `mcp-ai-pro/v1`:

| Method | Route                            | Purpose                                                                 |
|--------|----------------------------------|-------------------------------------------------------------------------|
| `GET`  | `/mcp-ai-pro/v1/mcp`             | Descriptor list of every registered toolkit server                      |
| `GET`  | `/mcp-ai-pro/v1/mcp/{slug}`      | Single-server descriptor                                                |
| `POST` | `/mcp-ai-pro/v1/mcp/{slug}`      | JSON-RPC 2.0 entry point                                                |

Supported JSON-RPC methods (Phase 1):

- `initialize`
- `ping`
- `tools/list`
- `resources/list`
- `prompts/list`

`tools/call`, `resources/read`, `prompts/get` are intentionally not yet implemented at the per-toolkit endpoint; clients should fall back to the monolithic `/mcp-ai/v1/mcp` endpoint for execution while Phase 3 lands.

## Phase 1 pilot servers

| Slug                   | Class                                              | Native surfaces                                                                                            | Mounted surfaces                                                  |
|------------------------|----------------------------------------------------|------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| `crm`                  | `WP_MCP_AI_CRM_MCP_Server`                         | `company-research`, `post-research`, `page-research`, `place-research` (all R&A)                          | —                                                                 |
| `health`               | `WP_MCP_AI_Healthcare_MCP_Server`                  | `member-research` (R&A), `health-records-consolidate` (C&A) — both on `mcp_ai_member`                     | —                                                                 |
| `architectural-design` | `WP_MCP_AI_Architectural_Design_MCP_Server`        | `architectural-drawing-research`, `architectural-project-research`, `architectural-specification-research` | `health-records-consolidate` mounted read-only from `health` |

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
