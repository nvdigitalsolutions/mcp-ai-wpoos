# Goodhart Authoring Checklist

Before shipping a new metric, verifier, or reward function, walk through
this list. Goodhart's law ("when a measure becomes a target, it ceases to
be a good measure") is the single most common failure mode of AI
measurement systems.

## For new metrics

- [ ] Declared a `counter_metric` — the signal that would reveal gaming of
      this metric — or written a one-sentence justification for being
      intentionally one-sided.
- [ ] Filled in `goodhart_note`: *what could go wrong if this metric is
      optimized blindly?*
- [ ] Set the correct `direction` (`higher_is_better`, `lower_is_better`,
      `neutral`).
- [ ] Assigned an appropriate `privacy_tier`. PHI/privilege/SOX data MUST
      use `restricted`.
- [ ] If the metric could be derived from a more private one, prefer the
      derived aggregate (data minimization).
- [ ] If this is a reward signal, added an entry in the reward function
      registry with an `anti_gaming` safeguard — not as an afterthought.

## For new verifiers

- [ ] Verifier kind is appropriate (`rule`, `schema`, `llm_judge`,
      `external_peer`, `human`).
- [ ] `independence_profile` lists every provider/model/tool that MUST NOT
      be shared with the generator.
- [ ] No prompt fragment is reused verbatim between generator and judge.
- [ ] Verifier returns both a `score` and a `confidence` — the dashboard
      uses confidence for calibration tracking.
- [ ] Verifier does not raise exceptions for routine verification failures
      (use the `passed=false` result shape).

## For new reward functions

- [ ] `anti_gaming` is a concrete sentence, not "N/A" or "TODO".
- [ ] Bounded output range — unbounded rewards are hard to clamp and easy
      to exploit.
- [ ] Paired with a `counter_metric` (cost, safety, latency) and documented
      the correlation expectation.
- [ ] If used inside an autonomous loop, has a clamp at the loop level too,
      not just at the reward.

## For every release

- [ ] Rotate at least one eval weight in the holdout suite.
- [ ] Review any metric whose correlation with its counter-metric broke
      down in the last quarter.
- [ ] Confirm the holdout set is still private (hash-verified).
- [ ] Confirm there is at least one verifier family that does NOT share a
      model family with the most-used generator.
