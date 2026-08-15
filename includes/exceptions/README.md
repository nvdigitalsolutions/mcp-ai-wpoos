# exceptions

## Purpose

This folder houses the three domain exceptions that the security pipeline
(concurrency guard, cost tracker, destructive ops gate) throws to short-circuit
a tool execution — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | Autoloaded on demand (thrown by security subscribers, caught by REST handlers) |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Cost_Budget_Exceeded` | `class-wp-mcp-ai-cost-budget-exceeded.php` | `includes/security/class-wp-mcp-ai-cost-tracker-subscriber.php` (throws), `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` (catches) |
| `WP_MCP_AI_Concurrency_Limit_Reached` | `class-wp-mcp-ai-concurrency-limit-reached.php` | `includes/security/class-wp-mcp-ai-concurrency-guard-subscriber.php` (throws), REST handlers (catches) |
| `WP_MCP_AI_Destructive_Confirmation_Required` | `class-wp-mcp-ai-destructive-confirmation-required.php` | `includes/security/class-wp-mcp-ai-destructive-ops-gate.php` (throws), REST handlers (catches) |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — pure exception carriers with domain payloads
  (assistant ID, slot info, operation name).
- **Writes to:** nothing.
- **Upstream callers:** `includes/security/` subscribers and gates (throwing
  side); `includes/rest/` controllers and `includes/class-wp-mcp-ai-rest.php`
  (catching side, converting to HTTP 429 / 403 / confirmation envelopes).
- **Downstream collaborators:** none — these classes extend PHP's `Exception`
  only.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- Every exception extends the PHP SPL `Exception` directly — no custom base
  class in this folder.
- The HTTP status is baked into the exception (e.g. `429` for budget and
  concurrency violations) so REST handlers can map `getCode()` straight to a
  response status.
- Docblocks must state which subscriber throws the exception and which
  handler catches it, so the control flow stays traceable without reading
  the security pipeline.

## Tests

Security-pipeline tests cover throw/catch behavior end-to-end:

```bash
vendor/bin/phpunit tests/security/
```

Coverage is intentional: these classes carry no logic worth unit-testing in
isolation; the throwing and catching sides are the behavior under test.

## Also Load

When an AI agent (or human) is working in this folder, they should also load
the following canonical context files for the GSD 30% budget:

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — how REST handlers map exceptions to responses

## See Also

- Upstream parent: [`includes/`](../)
- Siblings worth knowing about:
  - [`includes/security/`](../security/) — throws these exceptions
  - [`includes/rest/`](../rest/) — catches these exceptions
- Related docs:
  - [`docs/operations/security/SECURITY_POSTURE.md`](../../docs/operations/security/SECURITY_POSTURE.md) — posture signals including cost/concurrency gating
  - [`docs/project/proposals/023-database-connection-pooling-stance.md`](../../docs/project/proposals/023-database-connection-pooling-stance.md) — concurrency slot tracking that feeds the concurrency exception
