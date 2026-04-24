# Pro Measurement Toolkit

Pro-only extensions to the base measurement infrastructure described in
[`docs/measurement/`](../../../docs/measurement/). Base installs are
completely untouched — every Pro artifact below registers on the same
public hooks (`wp_mcp_ai_register_verifiers`,
`wp_mcp_ai_register_budgets`, `wp_mcp_ai_register_reward_functions`)
that Base opens up.

## What ships

| Artifact | Class | Kind |
|---|---|---|
| `pro_content_rubric` | `WP_MCP_AI_Pro_Rubric_Verifier` | Multi-criterion weighted rubric (composes rule / schema / llm_judge by default) |
| `pro_request_cost_usd` | Envelope definition registered by bootstrap | Per-request USD cost cap, default **$0.25** |
| `verified_success_budget_guarded` | Wrapper around the Base `verified_success` reward | Reward clamped to `0` when the cost envelope is exceeded |

## Rubric Verifier

`WP_MCP_AI_Pro_Rubric_Verifier` is a composite verifier whose score is
the **weight-normalized sum** of its criterion scores. Each criterion
specifies either:

* `verifier`: slug of another registered verifier (chains through the
  registry), **or**
* `callback`: a PHP callable returning a float (clamped to `[0, 1]`), a
  bool, or a verifier-shaped result array.

```php
add_action( 'wp_mcp_ai_register_verifiers', function ( $registry ) {
    $rubric = new WP_MCP_AI_Pro_Rubric_Verifier(
        'my_rubric',
        array(
            array( 'slug' => 'schema',    'weight' => 2, 'verifier' => 'schema' ),
            array( 'slug' => 'policy',    'weight' => 1, 'verifier' => 'rule' ),
            array( 'slug' => 'sentiment', 'weight' => 1, 'callback' => 'my_sentiment_check' ),
        ),
        'My Rubric',
        0.8 // pass threshold.
    );
    $registry->register( $rubric );
} );
```

Weights do not need to sum to 1.0 — the rubric normalizes them.
Zero-weight criteria are skipped. Criteria that neither declare a
`verifier` nor a `callback` are silently dropped at construction time.

### Cycle safety

Rubrics can reference other rubrics, but a rubric re-entering itself is
explicitly detected (`wp_mcp_ai_rubric_cycle` `WP_Error`) instead of
looping. Self-reference is rejected at criterion-evaluation time as a
clearer error.

### Filtering the stock rubric

The bootstrap-registered `pro_content_rubric` verifier has three
default criteria (schema, rule, llm_judge). Deployments that want to
change the default mix should filter the criteria rather than
re-registering the verifier under a new slug — the slug is a stable
contract for eval suites, reports, and dashboards.

```php
add_filter( 'wp_mcp_ai_pro_rubric_default_criteria', function ( $criteria ) {
    // Drop the LLM-judge criterion for sites that cannot afford its latency.
    return array_values( array_filter( $criteria, static function ( $c ) {
        return empty( $c['verifier'] ) || 'llm_judge' !== $c['verifier'];
    } ) );
} );
```

## Budget-Guarded Reward

`WP_MCP_AI_Pro_Budget_Guarded_Reward` **is not a reward itself.** It is
a factory that produces callables suitable for the `callback` field of
a reward function definition.

The anti-Goodhart argument: a reward monotone in "successful tool
calls" will pay an agent that spams cheap-ish calls until spend
explodes. Pairing that reward with a guarded wrapper means the
reward's gradient **reverses** the moment the spend cap is breached —
each new call now pays zero. Operators keep their original reward; the
wrapper is the policy.

```php
add_action( 'wp_mcp_ai_register_reward_functions', function ( $registry ) {
    WP_MCP_AI_Pro_Budget_Guarded_Reward::register_wrapper(
        $registry,
        array(
            'inner'               => 'verified_success',
            'budget'              => 'pro_request_cost_usd',
            'slug'                => 'verified_success_budget_guarded',
            'warn_multiplier'     => 1.0, // full reward through the warn band.
            'exceeded_multiplier' => 0.0, // zero once the cap is breached.
        )
    );
}, 30 );
```

### State-mapping semantics

| Envelope state | Reward output |
|---|---|
| `ok`       | inner reward value (unchanged) |
| `warn`     | inner × `warn_multiplier` (default 1.0) |
| `exceeded` | inner × `exceeded_multiplier` (default 0.0) |

Both multipliers are clamped to `[0, 1]`. If the named budget does
not exist at invocation time, the wrapper **degrades to
passthrough** — we prefer to under-guard silently over zeroing every
reward forever because one slug was typo'd.

The wrapper does **not** touch the budget registry's own signals —
`wp_mcp_ai_budget_warned` / `wp_mcp_ai_budget_exceeded` still fire on
the real crossing. The wrapper only shapes reward output.

## Gated loading

The Pro measurement bootstrap (`WP_MCP_AI_Pro_Measurement_Bootstrap`)
runs during Pro initialization, after toolkit integration, and guards
itself behind class-existence checks for:

* `WP_MCP_AI_Verifier_Registry`
* `WP_MCP_AI_Reward_Function_Registry`
* `WP_MCP_AI_Budget_Registry`

If any of those is missing (for instance, because the Base measurement
bootstrap failed to load), wiring is skipped — no fatals, no partial
registration.

## Hook priorities

| Hook | Priority | Why |
|---|---|---|
| `wp_mcp_ai_register_verifiers` | 20 | After base reference verifiers (10) so sub-verifier chaining can resolve. |
| `wp_mcp_ai_register_budgets` | 20 | Before the guarded reward wiring resolves its budget handle. |
| `wp_mcp_ai_register_reward_functions` | 30 | After base reference rewards (10) so `verified_success` exists to wrap. |

Sites composing their own artifacts should pick priorities that
preserve this ordering: any Pro-adjacent reward wrapper should run at
or after 30; any budget it relies on must register at or before then.

## Overriding defaults

| Filter | Purpose | Default |
|---|---|---|
| `wp_mcp_ai_pro_rubric_default_criteria` | Criteria list for the stock `pro_content_rubric` | schema ×2, rule ×1, llm_judge ×1 |
| `wp_mcp_ai_pro_request_cost_budget_limit` | USD limit for the stock request-cost envelope | `0.25` |

Returning `0` (or a negative number) from the budget-limit filter
**skips registration** of the stock envelope — use this if your site
has a different envelope shape for the same concept and you don't
want both registered.
