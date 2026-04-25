# Reward Function Authoring Guide

Reward functions turn verifier outcomes (and other signals) into a scalar
score that autonomous loops can optimize. Because they are the direct
target of optimization, they are the most Goodhart-exposed component of
the system — the registry refuses to register a reward function without
a non-empty `anti_gaming` safeguard.

## The contract

Registration takes an array:

```php
$registry->register( [
    'slug'           => 'verified_success',
    'label'          => 'Verified Success',
    'description'    => 'Optional long description.',
    'callback'       => 'my_verified_success',        // callable
    'inputs'         => [ 'verifier_passed', 'verifier_confidence' ],
    'output_min'     => 0.0,
    'output_max'     => 1.0,
    'anti_gaming'    => 'Paired with cost.per_success; confidence-gated.',
    'counter_metric' => 'cost.per_verified_success', // optional but strongly recommended
] );
```

The callback receives `( array $inputs, array $context )` and returns a
numeric value. The registry clamps the output into `[output_min, output_max]`
so a buggy callback cannot explode the loss landscape.

Missing inputs produce a `WP_Error` at evaluate time, not a silent
substitution of zero.

## Reference reward functions

All three are registered by default; sites can disable them via
`add_filter( 'wp_mcp_ai_enable_reference_rewards', '__return_false' )`.

### `verified_success`

Returns `1.0` only when both:

- the verifier passed, AND
- the verifier's stated `confidence` is `>= 0.5`.

Otherwise `0.0`. This is a conservative primary signal intended to be
combined with a cost-aware reward, not used alone.

Paired counter-metric: `cost.per_verified_success`.

### `cost_adjusted_success`

`verified_success / (1 + cost_usd / budget_usd)`.

At cost = budget the reward is halved; at cost = 10 × budget it is ~9% of
`verified_success`. The output stays in `[0, 1]` because both factors do.

Paired counter-metric: `agent.abstention.rate` — if the agent learns to
abstain to save cost, abstention rate rises and the counter-signal fires.

### `calibration_brier`

`1.0 - (stated_confidence - outcome)^2`, with the outcome in `{0, 1}` and
`stated_confidence` clamped to `[0, 1]`. Perfect calibration yields `1.0`;
claiming high confidence and being wrong yields `0.0`. This rewards
honest uncertainty estimates and is the single best defence against
reward hacks that hinge on overconfidence.

Paired counter-metric: `agent.unjustified_confidence`.

## Anti-gaming checklist

Before registering a new reward function, answer in writing:

1. **What is the simplest way an agent could score higher than intended?**
   Put the answer in `anti_gaming`.
2. **What counter-metric would catch that exploit?** Put it in
   `counter_metric`; the dashboard will alert when the correlation
   between the two breaks.
3. **Is the output bounded?** Unbounded rewards are hard to clamp and
   easy to exploit.
4. **Does it require a reliable verifier signal?** If yes, your reward's
   trustworthiness is capped by the verifier. Pair it with a second
   orthogonal reward rather than trusting a single verifier.
5. **Is there a test that fuzzes inputs and asserts bounds?** See
   `tests/measurement/test-reference-rewards.php` for the pattern.

## Tips

- Compose rewards at the loop level with weighted sums, not by
  concatenating callbacks. This keeps each reward independently
  observable in the dashboard.
- Rotate reward weights between eval runs. Goodhart's law collapses
  fastest when the same weighting is held constant for months.
- Log both the raw reward and the input tuple. Without inputs you
  cannot diagnose reward drift.
