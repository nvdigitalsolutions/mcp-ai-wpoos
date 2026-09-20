# Tool Description Engineering — Phase 2 Cluster Board

Sweep plan for applying `WP_MCP_AI_Tool_Usage_Guidance_Interface` to the
remaining tool classes. Tracks Phase 1 (PR #6686): the interface, registry
assembly, sniff, exemplar sweep, and adaptive cap.

**Status:** planning complete; execution starts after PR #6686 merges into
`alpha-working` (clusters reference the interface, so they must branch from
the merged base).

## Inventory

| Scope | Total tool files | Swept (Phase 1) | Remaining |
|---|---|---|---|
| Base (`includes/tools/`) | 276 | 10 | ~266 |
| Pro (`addons/pro/includes/tools/`) | 1,172 | 2 | ~1,170 |
| **Total** | **1,448** | **12** | **~1,436** |

Out of scope (never flagged by the sniff):

- `lib/core/src/Tool/` (194 framework-agnostic OOS tools — different contract,
  not `WP_MCP_AI_Tool_Interface`).
- `plugins/nvoos-content-graph*` ecosystem ports (owned by the ecosystem-port
  loop; guidance additions belong to future ports, not retroactive edits).
- Legacy-format tool classes wrapped by `WP_MCP_AI_Legacy_Tool_Wrapper`
  (the wrapper is sniff-exempt; migrate them opportunistically).

## Cluster definitions

One PR per cluster, branched from the merged `alpha-working` as
`feat/tool-guidance/<cluster>`. Sizes target 30–60 files for reviewability.
Exact file lists are generated at execution time with:

```bash
find <dir> -name "class-wp-mcp-ai-tool-*.php" ! -exec grep -q "get_usage_guidance" {} \; -print
```

### Base clusters

| # | Cluster | Scope | Est. files |
|---|---|---|---|
| B1 | `base-content` | Post/page/term/taxonomy/comment/user CRUD + media families in `includes/tools/` root | ~60 |
| B2 | `base-search-knowledge` | search/query/semantic/knowledge/embedding families + `paper-store/` (6) | ~40 |
| B3 | `base-generative` | generate/image/vision/transcribe/speech families | ~50 |
| B4 | `base-integrations` | huggingface (11), flowhub (7), sitekit (4), client (6), newsletter (6), erlang (3) families | ~45 |
| B5 | `base-ops` | cron/purge/probe/check/validate/trigger families + `orchestration/` (9) + `harness/` (7) + remaining tail | ~60 |
| B6 | `base-okf` | `okf/` (10) + OKF-related tail in root | ~15 |

### Pro clusters

| # | Cluster | Scope | Est. files |
|---|---|---|---|
| P1 | `pro-cloudways` | `cloudways/` | 61 |
| P2 | `pro-regulatory` | `regulatory-registration/` | 59 |
| P3 | `pro-chat-channels` | `chat-channels/` | 50 |
| P4 | `pro-ecommerce` | `ecommerce/` | 43 |
| P5 | `pro-financial` | `financial-planning/` | 42 |
| P6 | `pro-orchestration` | `orchestration/` | 36 |
| P7 | `pro-eca` | `eca-management/` | 36 |
| P8 | `pro-calendar` | `calendar-booking/` | 34 |
| P9 | `pro-documents` | `document-generation/` | 33 |
| P10 | `pro-social` | `social-media/` | 32 |
| P11 | `pro-site-creator` | `site-creator-toolkit/` | 32 |
| P12 | `pro-dietpi` | `dietpi/` | 25 |
| P13 | `pro-image` | `image-production/` (incl. `harmonization/`) | 37 |
| P14 | `pro-dj` | `dj-management/` | 22 |
| P15 | `pro-video` | `video-production/` | 21 |
| P16 | `pro-pm` | `project-management/` | 19 |
| P17 | `pro-google` | `google-workspace/` | 16 |
| P18 | `pro-crm` | `crm/` incl. `compliance/` and subdirs | ~40 |
| P19 | `pro-cre-debt` | `cre-debt/` family (underwriting, asset-management, originations, debt-fund) | ~45 |
| P20 | `pro-health-legal` | healthcare, law-firm, places, quiz families | ~40 |
| P21 | `pro-tail-a` | remaining long tail (analytics, comic, multilingual, misc) | ~40 |
| P22 | `pro-tail-b` | remaining long tail overflow | ~40 |

Counts are estimates; the tail clusters (P21/P22) are sized at execution
time from the actual remainder so no file is missed or double-assigned.

## Per-cluster execution loop

1. Branch from the **merged** `alpha-working` (never stack clusters).
2. Generate the exact file list (script above), paste into the PR body.
3. Apply guidance per `docs/features/tool-description-guidelines.md`:
   real tool-specific content (read `execute()` + schema first), slugs
   verified to exist, strings via `__( …, 'mcp-ai-wpoos' )` (Pro files use
   `mcp-ai-wpoos-pro`), ASCII-only.
4. Validate per file: `php -l`, WPCS
   (`--standard=phpcs.xml.dist`, 0 errors), guidance sniff
   (`--standard=phpcs/WPMCPAI/ruleset.xml --severity=5`, 0 warnings).
5. Run the affected tool-registry smoke suites
   (`tests/test-tool-registry*.php`) once per cluster.
6. PR against `alpha-working`, base-branch title
   `feat(tool-guidance): <cluster> (N tools)`. User merges manually.

Parallelism: up to two clusters in flight in separate worktrees
(`F:/GITHUB/worktrees/mcp-ai-wpoos/<cluster>/`); never two Docker PHPUnit
runs against the shared test DB at once.

## Completion gates

- [ ] All ~1,436 remaining tool files either implement the interface or
      embed inline guidance.
- [ ] `vendor/bin/phpcs --standard=phpcs/WPMCPAI/ruleset.xml --severity=5 includes/tools addons/pro/includes/tools` reports zero warnings.
- [ ] Sniff severity raised 0 → 5 in `phpcs.xml.dist` (final cluster).
- [ ] `.context/tool-registry.md` and this board updated with the live count.
