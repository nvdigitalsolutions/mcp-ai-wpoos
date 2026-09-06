# Approvals

## Purpose

Wave E3 port surface. `ApprovalQueue` is an aligned port of the base
plugin's `WP_MCP_AI_Approval_Queue`: byte-identical `mcp_ai_approval`
CPT, meta keys, error codes (`approval_missing_tool`,
`approval_not_found`, `approval_already_resolved`,
`approval_forbidden`), TTL floor (60 s, default 24 h), post-status →
approval-status mapping (`pending`/`publish`→approved/`private`→denied/
`trash`→expired), resolved-by audit meta, and the
`wp_mcp_ai_approval_queued|approved|denied` +
`wp_mcp_ai_approvals_cleanup_done` actions — the Human-in-the-Loop
pending-action store workflow steps pause on.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerApprovals()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | None (posts + postmeta only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Approvals\ApprovalQueue` | `ApprovalQueue.php` | `Plugin::registerApprovals()` — CPT + cleanup-cron wiring; consumed by the eventual workflow engine (E1), the necessity gate, and the approvals REST/admin ports |

## Inputs / Outputs / Neighbors

- **Reads from:** posts + postmeta under the `mcp_ai_approval` CPT, the
  current user (requester/actor resolution, `manage_options` gate)
- **Writes to:** approval posts (pending/publish/private/trash), the
  `_wp_mcp_ai_approval_*` meta keys, the weekly
  `wp_mcp_ai_approval_cleanup` cron event
- **Upstream callers:** workflow execution steps marking
  `requires_approval` (E1), the harness necessity gate
- **Downstream consumers:** `OutboundWebhook`'s
  `wp_mcp_ai_approval_requested` listener (already ported in E2), the
  approvals admin page + REST controller (E-UI-2), SSE approval frames
  in the chat UI

## Conventions

- Standalone-only registration — the base bootstrap loader owns the
  same `init` wiring (CPT priority 11, cron priority 1) in monolith
  installs.
- Byte-identical constants/hooks/error codes with the base; the
  literal meta keys/limits are promoted to named class constants
  (additive only — same values), documented in the class docblock.
- Singleton `get_instance()` surface matches the base so monolith-side
  callers (necessity gate, job sources) resolve unchanged.

## Tests

- `tests/test-approval-queue.php` — characterization suite covering
  CPT registration args, enqueue validation + meta roundtrip + TTL
  floor, approve/deny transitions + audit meta + actions, the
  permission model, record mapping + status mapping, pending filters,
  cron scheduling idempotence, and the expiry cleanup (real posts,
  both matrices).

## Also Load

- [`../Queues/README.md`](../Queues/README.md) — the E2 queue family
  (`OutboundWebhook` consumes approval events)
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E3 row status
