# 066 — MCP Client-Quirk Hardening: Implementation Plan

**Status:** In progress
**Date:** 2026-10-11
**Scope:** Base plugin MCP surface, Pro MCP App / JetEngine clients, Content Graph AI standalone mirror

## Motivation

The SineFrame M3 "Harness Quirks Matrix" (9–10 Oct 2026, Claude Code 2.1.287 vs
Codex 0.162.0) measured which agent clients break on which MCP server features.
Cross-referenced against NV oOS's own MCP surfaces, five gaps map onto this
plugin:

1. **One tool without a root `type` drops the whole server** on Claude Code —
   `tools/list` passes raw tool schemas through with only an `is_array` check.
2. **Codex compacts large schemas to a per-server budget (~20 KB) and drops
   required nested fields** — no per-schema byte budget exists.
3. **Codex drops tools past 2,048 per server** — the plugin exposes ~1,692
   tools on one unscoped `tools/list`; a slug-length and schema-size budget
   keeps the catalog parseable as it grows.
4. **`outputSchema` declared but text-only result becomes an error on Codex** —
   `nvoos_get_profile` advertises `outputSchema` but `tools/call` returns
   text-only content.
5. **Clients keep stale tool catalogs after `tools/list_changed`** — the
   plugin advertises `listChanged` but never signals it, and the Pro MCP
   clients cache discovery without honoring server `ttlMs` or server-pushed
   change notifications.

## Work Items

| # | Phase | File(s) | Change |
|---|---|---|---|
| 1 | Server `tools/list` hardening | `includes/class-wp-mcp-ai-rest-mcp-methods.php` | Normalize missing root `type: object`; skip+log bracket property names, root combinators without `type`, schemas over a byte budget (default 20 KB), slugs over 64 chars — all filterable |
| 2 | `outputSchema` consistency | same | Attach `structuredContent` to the profile tool result when `outputSchema` is advertised |
| 3 | Registry change signal | `includes/class-wp-mcp-ai-tool-registry.php` | Fire `wp_mcp_ai_mcp_tools_list_changed` (once per request) on register/unregister/clear — the hook that backs the advertised `listChanged` capability |
| 4 | Schema auditor | new `includes/class-wp-mcp-ai-tool-schema-auditor.php` | Static, dependency-free audit: root-type missing, root combinator+properties, bracket names, `prefixItems`, enum-in-`anyOf` on >5 KB schemas, unsafe integer ranges, over-budget schemas, long slugs |
| 5 | Site Health | `includes/class-wp-mcp-ai-site-health.php` | Direct test + debug field surfacing auditor findings (transient-cached 15 min) |
| 6 | Client cache freshness | `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php`, `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php`, `class-wp-mcp-ai-mcp-app-registry.php`, `mcp-apps-init.php` | Honor server `ttlMs` when positive (cap the settings TTL); detect `notifications/tools/list_changed` on SSE responses and invalidate the matching discovery cache |
| 7 | Standalone mirror | `plugins/nvoos-content-graph-ai/src/Rest/ToolSchemaAuditor.php` (new), `src/Rest/ToolsController.php` | Byte-equivalent port of the auditor + `tools/list` hardening |
| 8 | Tests | `tests/rest/test-mcp-schema-hardening.php`, `tests/test-tool-schema-auditor.php`, `tests/mcp-apps/test-mcp-app-cache-freshness.php`, `addons/pro/tests/test-jetengine-mcp-ttl.php` | Quirk-suite regression tests |
| 9 | Validation | — | PHPCS (both standards) + Docker PHPUnit |

## Deliberately out of scope (documented decisions)

- **Server-pushed SSE notifications**: the REST JSON-RPC surface has no
  long-lived notification channel (chat SSE is a separate transport). The
  server keeps advertising `listChanged` (true) and relies on `ttlMs` +
  client re-polling; the new `wp_mcp_ai_mcp_tools_list_changed` action is the
  single integration point a future SSE broadcaster can consume.
- **`ttlMs: 0` on client caches**: when a remote server declares no TTL, the
  Pro clients keep the settings-driven cache TTL rather than re-discovering
  every chat turn (2+ extra round trips). A positive `ttlMs` always caps the
  settings TTL. Documented in the client docblocks.
- **Tool-count cap**: no hard cap on `tools/list` yet (~1,692 tools is below
  the 2,048 client ceiling). Slug/schema budgets keep the payload well-formed;
  assistant-scoped listing remains the recommended path for external agents.
- **nvoos-core registry (standalone mode)**: registry change hooks apply to
  the base registry (monolith installs). The nvoos-core registry seam is out
  of scope and tracked as a follow-up.
