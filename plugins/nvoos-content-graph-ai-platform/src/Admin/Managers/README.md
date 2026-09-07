# Managers

## Purpose

Wave E-UI-2 port surface. Holds the base operator **manager pages** as
they land — approvals UI, token manager, cron manager, DAG builder,
DLQ manager, media-library columns, asset inventory — each an aligned
port of the matching `WP_MCP_AI_Admin_*` class in the base plugin's
`includes/admin/`. Sub-cluster 1 (`ApprovalsManager`) is the aligned
port of `WP_MCP_AI_Admin_Approvals`: byte-identical page slug
(`mcp-ai-approvals`) with the pending-count awaiting-mod badge menu
title, the two AJAX actions (`wp_mcp_ai_list_approvals`,
`wp_mcp_ai_resolve_approval`), the `wpMcpAiApprovals` localized
config envelope (nine-string i18n block), the toolbar + assistant
filter + seven-column table render surface, the pending-count probe,
and the list/resolve AJAX flows (requester display-name + date
enrichment, approve/deny transitions with notes).

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerManagers()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | None (approval posts + postmeta) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Admin\Managers\ApprovalsManager` | `ApprovalsManager.php` | `Plugin::registerManagers()` — standalone menu/enqueue/AJAX wiring |

## Inputs / Outputs / Neighbors

- **Reads from:** the per-mode approval queue
  (`Approvals\ApprovalQueue` — the E3 port), assistant posts for the
  filter dropdown, the current user (requester display names)
- **Writes to:** approval transitions (approve/deny via the resolved
  queue), AJAX JSON envelopes (list/resolve)
- **Upstream callers:** `Plugin::registerManagers()` (standalone menu
  mounting under `PlatformDashboard::PAGE_SLUG`), admin `wp_ajax_*`
  requests
- **Downstream consumers:** the base admin loader owns the same page
  monolith (the ported class stays unwired there)

## Conventions

- Per-mode discriminator is always `defined( 'WP_MCP_AI_PATH' )` —
  never bare `class_exists()` for base-owned classes. Collaborators
  resolve through `protected static` seams (the approval queue is the
  first: base `WP_MCP_AI_Approval_Queue` monolith / platform
  `Approvals\ApprovalQueue` standalone).
- These are the operational **manager** pages (approvals, token,
  cron, DAG, DLQ…), distinct from the E-UI-1 **dashboards** — same
  submenu-mounting discipline, one ported page per sub-cluster.
- Own assets live in the platform `assets/` tree (byte-identical
  copies of the base files).

## Tests

- `tests/test-approvals-manager.php` — characterization suite
  covering the byte-identical slug/nonce/action names, per-mode menu
  registration (incl. the pending-count badge), register idempotence,
  the per-mode approval-queue seam, the pending-count probe (real
  enqueued posts), the render output + capability gate, the AJAX
  nonce/capability gates, the list payload enrichment, the
  approve/deny/invalid envelopes, and the per-mode asset enqueues.
  Runs in both matrices.

## Also Load

- [`../Dashboards/README.md`](../Dashboards/README.md) — the E-UI-1
  dashboard family (same submenu-mounting discipline)
- [`../../Approvals/README.md`](../Approvals/README.md) — the
  approval queue these pages manage
- [`../../Plugin.php`](../Plugin.php) — `registerManagers()` wiring
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- Base originals: `includes/admin/class-wp-mcp-ai-admin-approvals.php` (sub-cluster 1), `class-wp-mcp-ai-admin-token-manager.php`, `class-wp-mcp-ai-admin-cron-manager.php`, `class-wp-mcp-ai-admin-dag-builder.php`, `class-wp-mcp-ai-admin-dlq-manager.php`, `class-wp-mcp-ai-admin-media-library-columns.php`, `class-wp-mcp-ai-asset-inventory-admin.php`
- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E-UI-2 row status
