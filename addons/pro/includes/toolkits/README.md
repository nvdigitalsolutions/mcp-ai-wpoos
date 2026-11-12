# Pro Toolkit Engine

## Purpose

The manifest-driven Pro toolkit engine. A toolkit (commerce, documents, …) is
a JSON **manifest** declaring its settings, entities, columns, dashboard cards
and screens; these classes validate those manifests, own the shared records
table, and serve the generic REST contract the console's toolkit screens
render. Adding a family means adding a manifest, not PHP.

`Nvoos\Core\Domain\ValueObject\ToolkitManifest` (in `lib/core`) is the
validator and is shared with the Laravel runtime, so both backends accept and
reject exactly the same documents.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`class-wp-mcp-ai-pro-module-registry.php`](../class-wp-mcp-ai-pro-module-registry.php) → module `rest_toolkits` (Tier 5) |
| **Optional dependencies** | None. Requires the Base plugin's `oos-bridge` PSR-4 loader for `Nvoos\Core\` |

## Public Surface

| Symbol | File | Purpose |
|---|---|---|
| `WP_MCP_AI_Pro_Toolkit_Manifests` | `class-wp-mcp-ai-pro-toolkit-manifests.php` | Manifest discovery + validation, `{prefix}nvoos_toolkit_records` schema, per-toolkit settings |
| `WP_MCP_AI_Pro_Toolkit_Records` | `class-wp-mcp-ai-pro-toolkit-records.php` | Record query/CRUD, payload validation, dashboard aggregates, duplicate scan, bulk import |
| `WP_MCP_AI_Pro_Toolkit_REST_Controller` | [`../rest/class-wp-mcp-ai-pro-toolkit-rest-controller.php`](../rest/class-wp-mcp-ai-pro-toolkit-rest-controller.php) | `mcp-ai-pro/v1/toolkits…` routes |

Manifests ship in [`manifests/`](manifests/). Third parties can register more
with the `wp_mcp_ai_pro_toolkit_manifest_dirs` and
`wp_mcp_ai_pro_toolkit_manifests` filters.

## Inputs / Outputs / Neighbors

- **Reads from:** manifest JSON files; `wp_mcp_ai_pro_toolkit_settings` option;
  the `{prefix}nvoos_toolkit_records` table; `WP_REST_Request` params/body.
- **Writes to:** the records table; the settings option;
  `WP_REST_Response` envelopes.
- **Upstream callers:** the complete-velocity console (`#/toolkits`,
  `#/toolkits/:slug`) through the shared `ToolkitsClient`; the REST controller.
- **Downstream collaborators:** `lib/core` manifest validator; the Base
  settings UI (no direct calls); `lib/laravel-adapter` mirrors this contract
  for the Laravel runtime.

## Conventions

Folder-specific deltas (canonical rules in
[`.context/conventions.md`](../../../../.context/conventions.md) and
[`.context/rest-api.md`](../../../../.context/rest-api.md)):

- **A toolkit is data.** Nothing in a manifest is executable; unknown keys,
  unknown types, dangling `entity:` references and unknown screens are errors.
  An invalid manifest is skipped and reported through the catalogue's
  `invalid` map — never served.
- **One table, many families.** `{prefix}nvoos_toolkit_records` stores
  `(toolkit, entity, external_id, data)`. The `external_id` is the manifest's
  business key and is immutable after create.
- **JSON paths are unquoted.** WordPress is always MySQL/MariaDB, where
  `json_extract` returns a *quoted* JSON string. Always wrap it in
  `json_unquote()` (see `WP_MCP_AI_Pro_Toolkit_Records::json_path()`), or every
  comparison, group and aggregate silently matches nothing.
- **Column names come from the manifest.** They are interpolated into SQL only
  after the manifest has validated them against the key pattern; values always
  go through `$wpdb->prepare()`.
- **Envelope parity.** `{ success, data }` / `{ success, error: { code,
  message, fields? } }` is identical to the Laravel runtime, so the shared
  console screens cannot tell the backends apart.

## Tests

```bash
bin/run-tests-docker.sh addons/pro/tests/test-pro-toolkit-rest.php
```

## Also Load

- [`.context/rest-api.md`](../../../../.context/rest-api.md) — REST patterns + error envelope
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability / nonce / sanitisation rules
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — why this lives in Pro
- [`../../../docs/project/plans/090-b-laravel-admin-surfaces-implementation-plan.md`](../../../../docs/project/plans/090-b-laravel-admin-surfaces-implementation-plan.md) — the parity plan

## See Also

- Sibling REST surface: [`../rest/`](../rest/)
- Laravel counterpart: `docker/laravel/app-skeleton/app/Http/Controllers/Pro/ToolkitsController.php`
- Shared screens: [`../../../addons/pro/assets/spa-v2/src/features/toolkits/`](../../assets/spa-v2/src/features/toolkits/)
