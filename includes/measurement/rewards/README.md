# Rewards

## Purpose

Ships the three reference reward functions — Verified Success, Cost-Adjusted Success, and Calibration (Brier Score) — that autonomous evaluation loops can compose to score generator outputs, each with a documented anti-gaming safeguard and paired counter-metric.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `WP_MCP_AI_Reference_Rewards::register()` called from `wp_mcp_ai_register_reward_functions` hook |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Reference_Rewards` | `class-wp-mcp-ai-reference-rewards.php` | `WP_MCP_AI_Reward_Function_Registry` via `wp_mcp_ai_register_reward_functions` |

## Inputs / Outputs / Neighbors

- **Reads from:** reward function inputs (`verifier_passed`, `verifier_confidence`, `cost_usd`, `budget_usd`, `stated_confidence`) passed by the eval runner or autonomous loop.
- **Writes to:** nothing persistent — returns `float` in `[0.0, 1.0]`.
- **Upstream callers:** eval runner, Pro budget-guarded rewards system, autonomous agent loops.
- **Downstream collaborators:** `WP_MCP_AI_Reward_Function_Registry`.
- **Events fired:** none.
- **Events listened to:** `wp_mcp_ai_register_reward_functions` (registration hook).

## Conventions

- `WP_MCP_AI_Reference_Rewards` is a static holder class — all methods are `public static`.
- Three reward functions are registered by default:
  - `verified_success` — returns 1.0 only when verifier passed with confidence ≥ 0.5. Anti-gaming: paired with `cost_adjusted_success` so cheap-but-wrong answers earn no reward.
  - `cost_adjusted_success` — `verified_success` divided by `1 + cost_usd / budget_usd`. Anti-gaming: bounded to [0, 1]; expensive successes are penalized.
  - `calibration_brier` — inverted Brier score (1 − squared error). Anti-gaming: agents cannot inflate by claiming uniform high confidence.
- Each function declares: `inputs`, `output_min`, `output_max`, `anti_gaming` rationale, and a `counter_metric` that should move in the opposite direction.
- All outputs are clamped to `[0.0, 1.0]`.
- Sites can deregister functions via `WP_MCP_AI_Reward_Function_Registry::unregister()`.

## Tests

```bash
vendor/bin/phpunit tests/measurement/
```

Reward function coverage is part of the measurement test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — numeric bounds validation (always)
- Parent folder: [`includes/measurement/README.md`](../README.md) — full measurement layer overview

## See Also

- Upstream parent: [`includes/measurement/`](../) — measurement layer
- Registry: [`includes/measurement/class-wp-mcp-ai-reward-function-registry.php`](../class-wp-mcp-ai-reward-function-registry.php)
- Verifiers: [`includes/measurement/verifiers/`](../verifiers/) — verifiers produce the `verifier_passed`/`verifier_confidence` inputs
- Budgets: [`includes/measurement/budgets/`](../budgets/) — budget envelopes that reward functions respect
