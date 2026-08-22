# okf/ (Pro)

## Purpose

The Pro OKF feature set, layered on the Base OKF engine:

- **OKF → Skill Bridge** — resolves `load_skill` names shaped
  `bundle:concept_id` into OKF concepts and returns them skill-shaped,
  gated by per-assistant grants, lifecycle status, and an optional minimum
  trust tier.
- **Auto-Enrichment Agent** — crawls published WordPress content (posts,
  pages, other public post types, and optionally taxonomy terms) and
  generates OKF concepts with cross-links into a bundle. Deterministic and
  idempotent; descriptions upgradeable to AI summaries via a filter.
- **Hybrid Knowledge Router** — classifies knowledge queries and produces an
  ordered routing plan across OKF (curated markdown), the vector store
  (embeddings), and Paper Store (structured records). Deterministic
  keyword/pattern signals, fully overridable via filters.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `class-wp-mcp-ai-pro-module-registry.php` → module `pro_okf_skill_bridge` → `okf-pro-init.php` |
| **Optional dependencies** | None (Base OKF engine + Base `load_skill` seam are always present) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OKF_Skill_Bridge` | `class-wp-mcp-ai-okf-skill-bridge.php` | Hooks `wp_mcp_ai_load_skill_external` (Base `load_skill` tool) |
| `WP_MCP_AI_OKF_Enrichment_Agent` | `class-wp-mcp-ai-okf-enrichment-agent.php` | Pro tool `okf_enrich_site_content`, admin AJAX |
| `WP_MCP_AI_Hybrid_Knowledge_Router` | `class-wp-mcp-ai-hybrid-knowledge-router.php` | Pro tool `route_knowledge_query` |
| `WP_MCP_AI_OKF_Concepts_Metabox` | `../admin/class-wp-mcp-ai-okf-concepts-metabox.php` | Assistant editor — grants `_wp_mcp_ai_okf_concepts` |
| `WP_MCP_AI_OKF_Enrichment_Admin` | `../admin/class-wp-mcp-ai-okf-enrichment-admin.php` | AJAX handler for the Base admin page's gated enrichment form |
| `WP_MCP_AI_Tool_OKF_Enrich_Site_Content` | `../tools/okf/class-wp-mcp-ai-tool-okf-enrich-site-content.php` | MCP tool `okf_enrich_site_content` (`manage_options`) |
| `WP_MCP_AI_Tool_Route_Knowledge_Query` | `../tools/okf/class-wp-mcp-ai-tool-route-knowledge-query.php` | MCP tool `route_knowledge_query` (`read`) |

## Inputs / Outputs / Neighbors

- **Reads from:** Base OKF bundles via `WP_MCP_AI_OKF_Bundle_Manager` +
  `WP_MCP_AI_OKF_Reader`; assistant grants from post meta
  `_wp_mcp_ai_okf_concepts` (`WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS`);
  published content via `WP_Query` and public taxonomies via `get_terms`.
- **Writes to:** Assistant post meta (grants); OKF bundles (enrichment only —
  never `skill-knowledge`, which is protected by the Base manager);
  bundle `log.md` entries via the manager.
- **Upstream callers:** Base `WP_MCP_AI_Tool_Load_Skill` (via filter);
  MCP tool registry (both Pro tools, registered on `wp_mcp_ai_bootstrapped`
  priority 36); the Base Bundle Manager admin page (gated enrichment form).
- **Downstream collaborators:** `WP_MCP_AI_OKF_Bundle_Manager`,
  `WP_MCP_AI_OKF_Reader`, `WP_MCP_AI_OKF_Writer`.
- **Filters emitted:**
  - `wp_mcp_ai_okf_skill_bridge_min_trust` — minimum trust tier for skill loads.
  - `wp_mcp_ai_okf_enrichment_description` — upgrade deterministic excerpt to an AI summary.
  - `wp_mcp_ai_hybrid_router_signals` — extend the router's pattern table.
  - `wp_mcp_ai_hybrid_router_decision` — replace the whole routing plan (custom/LLM classifiers).
- **Filters consumed:** `wp_mcp_ai_load_skill_external`.

## Conventions

- One file = one class. Engine logic stays here; UI lives in `../admin/`;
  MCP tools live in `../tools/okf/`.
- Fail-closed allow-listing: an OKF concept loads only when the assistant
  was granted it; drafts are always rejected; trust gating is opt-in.
- Enrichment and routing are deterministic by default (no bundled LLM cost);
  both expose filters as the upgrade seam for AI-powered behavior.
- Enrichment respects the Base protected-bundle gate: `skill-knowledge`
  (and any `okf_protected_bundle`) is always refused.

## Tests

```bash
vendor/bin/phpunit tests/test-okf-skill-bridge.php
vendor/bin/phpunit tests/test-okf-enrichment.php
vendor/bin/phpunit tests/test-hybrid-knowledge-router.php
vendor/bin/phpunit tests/test-okf-phase-7-8-tools.php
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only placement
- [`docs/features/okf-integration.md`](../../../../docs/features/okf-integration.md) — OKF engine + roadmap
