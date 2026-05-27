# Verifiers

## Purpose

Contains the three default verifier implementations — LLM Judge, Rule, and Schema — that evaluate AI-generated outputs against expected results, each extending `WP_MCP_AI_Verifier_Base` and supporting verifier-independence enforcement.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `WP_MCP_AI_Verifier_Registry` via `wp_mcp_ai_register_verifiers` hook |
| **Optional dependencies** | LLM Judge requires a callable (provider-agnostic; none bundled) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_LLM_Judge_Verifier` | `class-wp-mcp-ai-llm-judge-verifier.php` | Eval suites, Pro rubric verifier, tools that score outputs |
| `WP_MCP_AI_Rule_Verifier` | `class-wp-mcp-ai-rule-verifier.php` | same |
| `WP_MCP_AI_Schema_Verifier` | `class-wp-mcp-ai-schema-verifier.php` | same |

## Inputs / Outputs / Neighbors

- **Reads from:** verifier inputs (`subject` array with expected/output/value), rule definitions, JSON Schema-style schemas, judge callables (LLM Judge).
- **Writes to:** nothing persistent — returns standard result arrays with `passed`, `score` (0..1), `confidence` (0..1), `reasons`, `evidence`.
- **Upstream callers:** eval runner, Pro rubric verifier, tools that need output validation.
- **Downstream collaborators:** provider clients (LLM Judge, via pluggable callable), `WP_MCP_AI_Verifier_Base`.
- **Events fired:** none.
- **Events listened to:** `wp_mcp_ai_register_verifiers` (registration hook), plus per-verifier filters: `wp_mcp_ai_llm_judge_callable`, `wp_mcp_ai_rule_verifier_rules`.

## Conventions

- All verifiers extend `WP_MCP_AI_Verifier_Base` and call `result_pass()` / `result_fail()` for consistent output shapes.
- **LLM Judge Verifier:** Pluggable — no provider SDK bundled. Callers supply a judge callable via constructor or `wp_mcp_ai_llm_judge_callable` filter. Auto-abstains (score 0.5, confidence 0.0) when no judge is configured. Independence profile MUST list disallowed providers/models.
- **Rule Verifier:** Deterministic, no LLM calls. Supports six rule types: `required`, `pattern`, `enum`, `min`, `max`, `callback`. Rules are weighted; score = passed_weight / total_weight.
- **Schema Verifier:** Lightweight JSON Schema subset — `type`, `required`, `properties`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `items`. No full JSON Schema dependency.
- Verifier independence is enforced by the eval runner: a judge must not share provenance with the generator under test.

## Tests

```bash
vendor/bin/phpunit tests/verifiers/
vendor/bin/phpunit tests/measurement/
```

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — callable validation, output escaping (always)
- Parent folder: [`includes/measurement/README.md`](../README.md) — full measurement layer overview

## See Also

- Upstream parent: [`includes/measurement/`](../) — measurement layer
- Base class: [`includes/measurement/class-wp-mcp-ai-verifier-base.php`](../class-wp-mcp-ai-verifier-base.php)
- Eval framework: [`includes/measurement/eval/`](../eval/) — consumes verifiers for suite scoring
- Rewards: [`includes/measurement/rewards/`](../rewards/) — consume verifier results for reward calculation
