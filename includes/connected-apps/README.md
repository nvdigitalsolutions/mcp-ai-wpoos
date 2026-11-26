# Connected Apps

## Purpose

Discovers the four console keys that are served by **separate NV oOS
products** on their own origins — Content Graph, Docs Hub, Media Studio and
Document Editor — and reports the base URL the console should mount.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 8.1+ |
| **Loaded by** | `includes/bootstrap/loader.php` (after `includes/security/class-wp-mcp-ai-url-guard.php`) |
| **Optional dependencies** | none — a product whose plugin is absent is simply remote |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Connected_App_Registry` | `class-wp-mcp-ai-connected-app-registry.php` | `includes/rest/class-wp-mcp-ai-rest-features-controller.php` |

### `WP_MCP_AI_Connected_App_Registry`

```php
$registry = WP_MCP_AI_Connected_App_Registry::instance();

$urls = $registry->available();   // array< console key, product REST base >
$flags = WP_MCP_AI_Connected_App_Registry::apply_feature_flags( $flags, $urls );
```

`available()` probes once per TTL and caches the result **including failures**,
so a dead product cannot slow every console boot.

Configuration — option `wp_mcp_ai_connected_apps`, or the
`wp_mcp_ai_connected_apps` filter (which wins):

```php
array(
    'contentGraph' => array(
        'base'  => 'https://cg.example.com/wp-json/nvoos-content-graph/v1',
        'probe' => '/graph',  // optional path override
    ),
    'docsHub' => array( 'base' => 'https://docs.example.com/wp-json/nvoos-docs/v1' ),
)
```

### Bearer tokens live in the encrypted key store

A probe credential is a secret that leaves the site, so it is **not** a
descriptor field. Store it with `WP_MCP_AI_Api_Key_Store` and the registry picks
it up automatically:

```php
WP_MCP_AI_Api_Key_Store::set(
    WP_MCP_AI_Connected_App_Registry::token_suffix( 'docsHub' ),
    $token,
);

// CLI:
// wp option update wp_mcp_ai_connected_app_docs_hub_token '<token>' --autoload=no
```

Suffixes: `connected_app_content_graph_token`,
`connected_app_docs_hub_token`, `connected_app_media_studio_token`,
`connected_app_document_editor_token` — all four are in
`WP_MCP_AI_Api_Key_Store::MANAGED_KEYS`, so they appear in the admin key list and
are covered by the plaintext-migration sweep.

The store wins over a descriptor `token`, so rotating a credential takes effect
without editing the option. A descriptor `token` remains supported as a fallback
for programmatic callers (a filter that provisions config in code) and for tests.

Constants worth knowing:

| Constant | Value |
|---|---|
| `DEFAULTS` | Console key → default probe path (`/graph` for Content Graph, `/health` for the rest) |
| `FEATURE_FLAGS` | Console key → `WP_MCP_AI_Feature_Flags` key, so `/features` never contradicts itself |
| `TOKEN_SUFFIXES` | Console key → encrypted key-store suffix |
| `FILTER_TTL` | `wp_mcp_ai_connected_apps_ttl`, default 300 s |

## Inputs / Outputs / Neighbors

- **Reads from:** option `wp_mcp_ai_connected_apps`, the encrypted key store
  (`wp_mcp_ai_connected_app_*_token`), the transient
  `wp_mcp_ai_connected_apps_<hash>`.
- **Writes to:** that transient only. No options, no post meta.
- **Upstream callers:** the features controller.
- **Downstream collaborators:** [`../security/`](../security/)
  (`WP_MCP_AI_Url_Guard`), [`../class-wp-mcp-ai-endpoint-capabilities.php`](../class-wp-mcp-ai-endpoint-capabilities.php)
  via `apply_connected_apps()`, [`../class-wp-mcp-ai-feature-flags.php`](../class-wp-mcp-ai-feature-flags.php).
- **Events fired:** none.
- **Events listened to:** `http_api_curl` (pins the validated address for the
  duration of one probe, then unhooks).

## Conventions

- **Resolve once, pin that set.** `resolve_for_pin()` calls
  `WP_MCP_AI_Url_Guard::resolve()` a single time and range-checks those same
  addresses; `pin()` then pins exactly that list. Calling
  `Url_Guard::validate()` and re-resolving would leave a rebinding window.
- **A reached product may demand auth.** `2xx/3xx/401/403` all mean present;
  only the other 4xx and 5xx mean absent. A 401 is a product that exists.
- **Never follow a redirect** (`redirection => 0`) — that is the SSRF pivot
  from an allowed host to an internal one.
- **Tokens never leave the request.** The bearer credential is read from
  `WP_MCP_AI_Api_Key_Store` (or the descriptor fallback) and added to the probe
  headers only; it is not in `available()` or the transient.
- Unknown descriptor keys and malformed entries are dropped, not thrown.

## Tests

```bash
bash bin/run-tests-docker.sh tests/connected-apps/test-connected-app-registry.php
```

Covers descriptor normalisation, reachability mapping, caching across calls,
bearer-token handling, the HTTPS requirement, private-address refusal and the
manifest merge.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — the console endpoint contract

## See Also

- Upstream parent: [`includes/`](../)
- Sibling: [`../security/`](../security/)
- Design: [`docs/project/plans/090-p-console-p1-p2-plan.md`](../../docs/project/plans/090-p-console-p1-p2-plan.md)
