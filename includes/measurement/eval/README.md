# Eval

## Purpose

Provides the complete evaluation framework — case definitions, suite collection, runner orchestration, counterfactual analysis, run persistence, and regression detection — for automated quality assessment of AI-generated outputs against verifiable expectations.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | measurement bootstrap; eval suites registered via `wp_mcp_ai_register_eval_suites` |
| **Optional dependencies** | evaluator callables (LLM judge, etc.) gated at runtime; none required |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Eval_Case` | `class-wp-mcp-ai-eval-case.php` | Eval suites, eval runner |
| `WP_MCP_AI_Eval_Suite` | `class-wp-mcp-ai-eval-suite.php` | Suite registry, eval runner |
| `WP_MCP_AI_Eval_Suite_Registry` | `class-wp-mcp-ai-eval-suite-registry.php` | Measurement bootstrap, CLI, Pro eval scheduler |
| `WP_MCP_AI_Eval_Runner` | `class-wp-mcp-ai-eval-runner.php` | CLI eval harness, Pro eval scheduler |
| `WP_MCP_AI_Eval_Run_Store` | `class-wp-mcp-ai-eval-run-store.php` | Runner, admin dashboard |
| `WP_MCP_AI_Counterfactual_Runner` | `class-wp-mcp-ai-counterfactual-runner.php` | Eval runner (A/B variant testing) |
| `WP_MCP_AI_Eval_Regression_Detector` | `class-wp-mcp-ai-eval-regression-detector.php` | Admin dashboard, CI hooks |

## Inputs / Outputs / Neighbors

- **Reads from:** eval suite definitions (registered via `wp_mcp_ai_register_eval_suites`), verifier results, previous run data from the run store.
- **Writes to:** eval run results (persisted via `WP_MCP_AI_Eval_Run_Store`), `wp_mcp_ai_eval_suite_completed` action.
- **Upstream callers:** CLI eval commands, Pro eval scheduler, admin dashboard.
- **Downstream collaborators:** `verifiers/` (LLM judge, rule, schema), `rewards/` (reference rewards), metric collector.
- **Events fired:** `wp_mcp_ai_eval_suite_completed`.
- **Events listened to:** `wp_mcp_ai_register_eval_suites` (registration hook).

## Conventions

- `WP_MCP_AI_Eval_Case` is an immutable value object: slug, label, input, expected, verifier_slug, verifier_args, metadata, target_confidence.
- `WP_MCP_AI_Eval_Suite` holds an ordered collection of cases with shared generator_context (provider/model) and tags.
- Verifier independence is enforced: the suite's `generator_context` is used by the runner to reject judges that share provenance with the generator.
- All registries follow the singleton + `boot()` + `register()` pattern consistent with the measurement layer.
- `WP_MCP_AI_Eval_Regression_Detector` compares current run scores against historical baselines from the run store.

## Tests

```bash
vendor/bin/phpunit tests/measurement/
```

Eval-specific coverage is part of the measurement test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — output escaping for eval reports (always)
- Parent folder: [`includes/measurement/README.md`](../README.md) — full measurement layer overview

## See Also

- Upstream parent: [`includes/measurement/`](../) — measurement layer
- Verifiers: [`includes/measurement/verifiers/`](../verifiers/) — LLM judge, rule, schema verifiers consumed by eval
- Rewards: [`includes/measurement/rewards/`](../rewards/) — reward functions used in eval scoring
- Budgets: [`includes/measurement/budgets/`](../budgets/) — cost envelopes for eval guardrails
