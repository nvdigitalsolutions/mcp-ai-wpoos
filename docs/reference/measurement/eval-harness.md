# Eval Harness

The eval harness is the piece that turns registered verifiers and reward
functions into actionable feedback. It is deliberately small: a case value
object, a suite collection, a registry wired into
`wp_mcp_ai_register_eval_suites`, and a runner that walks a suite against a
generator callable.

This PR ships the harness in its minimum-useful form. Persistent result
storage, long-run scheduling, and cross-run regression alerting are
deferred to later PRs — but the public interfaces below are stable.

## Data model

### `WP_MCP_AI_Eval_Case`

A single test case. Required fields:

- `slug` — unique within the suite.
- `verifier_slug` — slug of a registered verifier.

Optional fields:

- `label`, `input`, `expected`, `verifier_args`, `metadata`, `target_confidence`.

Cases are immutable after construction and serialize cleanly via
`to_array()` for dashboard / report output.

### `WP_MCP_AI_Eval_Suite`

An ordered collection of cases plus shared metadata. The important shared
field is `generator_context`, which the runner hands to the verifier
registry so independence checks happen once per run rather than once per
case definition.

```php
$suite = new WP_MCP_AI_Eval_Suite( [
    'slug'              => 'citation_quality_v1',
    'label'             => 'Citation Quality v1',
    'description'       => 'Verifies citation URLs and content hashes.',
    'tags'              => [ 'quality', 'grounding' ],
    'generator_context' => [ 'provider' => 'openai', 'model' => 'gpt-4.1' ],
    'cases'             => [
        [ 'slug' => 'c1', 'verifier_slug' => 'schema_verifier', 'expected' => [...] ],
    ],
] );
```

### `WP_MCP_AI_Eval_Suite_Registry`

Singleton registry. Fires `wp_mcp_ai_register_eval_suites` once per
request. Accepts either suite instances or array definitions.

```php
add_action( 'wp_mcp_ai_register_eval_suites', function ( $registry ) {
    $registry->register( [
        'slug'  => 'smoke',
        'cases' => [ /* ... */ ],
    ] );
} );
```

## Running a suite

```php
$runner  = new WP_MCP_AI_Eval_Runner();
$report  = $runner->run(
    $suite,
    function ( WP_MCP_AI_Eval_Case $case, array $ctx ) {
        // Your generator: call the model, run the tool, etc.
        return [
            'output'            => '...',
            'stated_confidence' => 0.8,
            'cost_usd'          => 0.004,
            'budget_usd'        => 0.10,
        ];
    },
    [ 'rewards' => [ 'verified_success', 'cost_adjusted_success', 'calibration_brier' ] ]
);
```

### Generator contract

The generator callable receives `( WP_MCP_AI_Eval_Case, array $suite_context )`
and must return either a `WP_Error` (becomes an error-counted case) or an
array with at least:

- `output` — the subject fed to the verifier.
- _(optional)_ `stated_confidence`, `cost_usd`, `budget_usd`, `provider_context`.

The runner never raises — invalid returns are turned into error cases so
a buggy generator can't crash an otherwise-valid batch run.

### Report shape

```php
[
    'suite'       => [ /* suite summary */ ],
    'summary'     => [
        'total' => N, 'passed' => N, 'abstained' => N, 'errors' => N,
        'pass_rate' => 0..1, 'abstention_rate' => 0..1, 'error_rate' => 0..1,
        'mean_score' => 0..1, 'median_score' => 0..1, 'mean_confidence' => 0..1,
        'reward_means' => [ 'verified_success' => 0..1, /* ... */ ],
    ],
    'cases'       => [ [...per-case reports...] ],
    'duration_ms' => N,
    'started_at'  => unix_ts,
]
```

The `wp_mcp_ai_eval_suite_completed` action fires with `( $report, $suite )`
when the run finishes — attach exporters or dashboards here.

## Metrics emitted

For each case the runner emits (if those metrics are registered — unknown
metrics are silently dropped by the collector):

| Metric | Meaning |
|--------|---------|
| `eval.case.passed` | 1 if the verifier passed, else 0 |
| `eval.case.score` | Verifier score (0..1) |
| `eval.case.confidence` | Verifier confidence (0..1) |
| `eval.case.latency_ms` | Generator latency for this case |
| `eval.case.abstained` | 1 when the verifier abstained (LLM judge with no callable, etc.) |
| `eval.reward.{slug}` | Reward value per reward slug requested |

Site authors register these in their own `wp_mcp_ai_register_metrics`
listener — they are not forced on the core catalogue because not every
deployment needs eval telemetry.

## Anti-Goodhart notes

- **Abstention is not a pass.** The summary exposes `abstention_rate`
  next to `pass_rate` so you see when a verifier stopped voting.
- **Errors are not a pass.** Generator-or-verifier errors are counted in
  `error_rate`; they never contribute to `pass_rate`.
- **Independence is enforced.** When `generator_context` is set on the
  suite, the runner hands it to the verifier registry's independence
  check; a judge that shares provider/model/tool with the generator
  fails the case rather than producing a tainted pass.
- **Rewards are bounded.** The runner evaluates rewards through the
  registry, which clamps them to their declared `[output_min, output_max]`.
