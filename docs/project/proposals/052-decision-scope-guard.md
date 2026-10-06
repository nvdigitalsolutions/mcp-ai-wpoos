# Proposal 052 — Decision-Scope Guard: Bounding What Jev May Decide

**Status:** 📝 Proposed — Phase 1 implemented on `feat/decision-scope-guard`
**Date:** 2026-10-03
**Scope:** Base plugin (`includes/`) + Pro (`addons/pro/`) + PHPCS sniff (`phpcs/`)
**Related:** [`039-typesafe-jev-decision-provider.md`](./039-typesafe-jev-decision-provider.md), [`040-typesafe-jev-enhancements.md`](./040-typesafe-jev-enhancements.md), [`045-jev-decision-orchestration-phase-a.md`](./045-jev-decision-orchestration-phase-a.md)

## Summary

Every TypeSafe Jev integration shipped so far is **safe by convention**:
fail-open on error, opt-in flags, `manage_options`-gated tools, "only the
first user message reaches Jev", thresholds living in PHP. Those guarantees
are per-integration. Nothing in the code structurally limits **what may be
decided** (the decision *domain*) or **how much authority** a verdict carries.
That is the gap this proposal closes.

The I, Robot drowning scene is the precise formulation of the risk: the robot
was not wrong because its arithmetic failed — it was wrong because a
calculation happened at all in a domain where no calculation belongs. Bernard
Williams called the disposition to deliberate there "one thought too many."
The same failure mode applies to decision-model integrations: individually
each new Jev surface is "advisory, fail-open, off by default", but *the sum of
advisory verdicts quietly becomes authoritative*, and nothing in code prevents
the next integration from dispatching a values-laden question to the formula.

This proposal adds:

1. **`WP_MCP_AI_Decision_Scope_Guard`** — a base service in front of every
   Jev client dispatch. Each dispatch declares a **domain** (what kind of
   thing is being decided) and an **authority ceiling** (`inform` →
   `suggest` → `act`). Domains that are values-laden by nature (`life`,
   `people`, `ethics`, `identity`) fail closed to a caller-supplied
   deterministic fallback — the formula never runs. `act` authority is never
   granted by default and requires an explicit per-domain opt-in filter.
2. **`WPMCPAI.Decisions.ScopeDeclared`** — a custom PHPCS sniff (CI
   severity-5 error) requiring every `->decide()` / `->create_decision()`
   dispatch to sit inside a function whose docblock declares
   `@decision-domain` and `@decision-authority`. New integrations cannot land
   without declaring what they are asking the model to decide — creep becomes
   a build failure instead of a review comment.
3. **Phase-1 declarations at all six existing dispatch sites** — advisory
   (`typesafe_decide`), content (`typesafe_guardrail`, `typesafe_rerank`),
   operations (Pro Jev classifier, `typesafe_skill_select`), and verification
   (verification cascade). **Zero behavior change**: every existing call site
   passes the guard unchanged.

The domain is declared by the integration author, not inferred — the same
trust model as Rust's `unsafe`. The guard cannot know intent; it can only
make lying explicit and reviewable.

## Motivation / Gap Analysis

Current Jev dispatch surface (v1.1.92):

| Call site | File | What it decides | Safeguards today |
|---|---|---|---|
| `typesafe_decide` tool | `includes/tools/class-wp-mcp-ai-tool-typesafe-decide.php` | anything the assistant asks | `manage_options`, adversarial-state caveat |
| `typesafe_guardrail` tool | `includes/tools/class-wp-mcp-ai-tool-typesafe-guardrail.php` | hazard classification | `manage_options`, advisory verdicts |
| Verification cascade | `includes/services/class-wp-mcp-ai-verification-cascade.php` | claim/citation verification | fail-open, thresholds in code |
| Pro Jev classifier | `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-classifier.php` | routing, relevance, guardrails | fail-open, neutral signal |
| `typesafe_rerank` tool | `addons/pro/includes/tools/jev/...-rerank.php` | candidate relevance | `manage_options`, keep-min floor |
| `typesafe_skill_select` tool | `addons/pro/includes/tools/jev/...-skill-select.php` | skill ranking | `manage_options`, fail-open re-check |

All of these are individually defensible. The structural problems:

- **No authority ceiling.** Nothing distinguishes a verdict that informs from
  one that acts. "Advisory quietly becomes authoritative" is a social fact,
  not a code fact.
- **No domain boundary.** The `typesafe_decide` tool will happily evaluate
  `{ "age": 6 }` against a survival-probability question. The robot's
  category error is reproducible today with one tool call.
- **Creep is invisible.** Adding a seventh, eighth, ninth integration is a
  review comment's worth of friction. There is no registry, no Site Health
  surface, no mechanical check.

## Design

### Domain taxonomy

| Constant | Domain | Meaning |
|---|---|---|
| `DOMAIN_ADVISORY` | `advisory` | Assistant-facing verdicts; reported, never executed by the plugin |
| `DOMAIN_CONTENT` | `content` | Classification, moderation, relevance, re-ranking of content |
| `DOMAIN_OPERATIONS` | `operations` | Internal routing/selection of resources (models, skills, tiers) |
| `DOMAIN_VERIFICATION` | `verification` | Claim/citation verification against source text |
| `BANNED_DOMAINS` | `life`, `people`, `ethics`, `identity` | Domains where the thing decided is a human being or a moral value. Gate fails closed to the fallback — the formula never runs. |

### Authority ladder

| Level | Meaning | Default |
|---|---|---|
| `inform` | Verdict is reported; no plugin action derives from it | allowed everywhere (except banned domains) |
| `suggest` | Verdict may shape output the caller still controls | allowed everywhere (except banned domains) |
| `act` | Verdict may drive automatic action | **denied for every domain** unless listed in the `wp_mcp_ai_decision_act_domains` filter |

Banned domains win over grants: a domain listed in the act-grant filter is
still rejected when it is in `BANNED_DOMAINS`.

### Gate semantics

```php
WP_MCP_AI_Decision_Scope_Guard::gate( $domain, $authority, $fallback )
```

- Returns `true` when the dispatch is allowed (all six current sites).
- Otherwise returns the fallback's result: a callable is invoked; a
  non-callable value is returned as-is; `null` resolves to a
  `wp_mcp_ai_decision_scope_gated` `WP_Error`.
- "Fail closed to the fallback" is per-caller: tools return a `WP_Error`,
  the cascade returns its neutral fail-open result with an error message.
  The guard never silently proceeds and never invents a fallback.

### Sniff: `WPMCPAI.Decisions.ScopeDeclared`

- Triggers on `->decide(` and `->create_decision(` object-operator calls.
- The enclosing named function/method docblock must carry
  `@decision-domain <value>` and `@decision-authority <value>` with values
  from the guard's constant lists (kept in sync; the sniff embeds a copy and
  a keep-in-sync comment).
- Errors at severity 5 → CI `lint:errors-only` fails. This is the
  enforcement layer: new dispatches without declarations are build failures.
- Exemptions: files under `tests/`; methods whose enclosing class implements
  `Interface_WP_MCP_AI_Decision_Client` (pure transport adapters — the
  anonymous OpenRouter adapter in the cascade); `phpcs:ignore` with a
  justification comment remains the documented escape hatch.
- Wrapper methods that forward to a declared funnel (e.g.
  `$this->decide()` in `typesafe_skill_select`) also declare — the sniff
  makes no distinction between local and client dispatch, which is
  intentional: every decision path is visible.

## Related Standards & Industry Precedent

This proposal is a WordPress-native encoding of positions the industry has
already taken. The table maps each mechanism to its closest analog; the
Status column distinguishes binding law, published guidance, protocol
specification, and practitioner tooling.

| This proposal | Industry analog | Status |
|---|---|---|
| Banned domains (`life`, `people`, `ethics`, `identity`) fail closed | **EU AI Act** — Article 5 prohibited practices (a banned-category list) and Article 14 human oversight for high-risk systems; **Google AI Principles** — "applications of AI in consequential decision-making … will be subject to appropriate human direction and control" | Law (EU), corporate policy |
| Domain declaration (what may be decided) | **OWASP LLM Top 10 — LLM06:2025/2026 "Excessive Agency"** — first root cause: *excessive functionality* ("agents can reach tools beyond their task scope"); ranked #3 in the 2026 edition specifically because of agentic deployments | Published guidance |
| Authority ceiling (`inform` → `suggest` → `act`) | LLM06's other two root causes — *excessive permissions* and *excessive autonomy* ("high-impact actions proceed without a human in the loop"); **CISA/Five Eyes** "Deploying AI Systems Securely" — human approval for irreversible actions; **SAE J3016** and **Sheridan & Verplank's levels of automation** — the human-factors ancestors of the ladder | Published guidance / engineering tradition |
| Gate before dispatch; `act` needs an explicit per-domain grant | **OWASP Agent Control Standard (2026)** — "a runtime authorization layer evaluates the exact operation at the point where the action becomes real … before execution"; **OWASP Top 10 for Agentic Applications (ASI01–ASI10, Dec 2025)** — Just-in-Time Agency and permitted-action configuration with change management | Emerging standard |
| Declared scope on every dispatch, mechanically enforced | **MCP tool annotations** (`readOnlyHint`, `destructiveHint`, `idempotentHint`) — "the security levers: they tell the Host what consent UI to display before execution"; **MCP Elicitation** — user-consent tool calls; the **MCP Registry** Trust & Safety Safe/Unsafe badge; **policy-as-code** (OPA) in adjacent domains; **DO-178C DAL / ISO 26262 ASIL / IEC 61508 SIL** — declared criticality classes enforced by tooling/process in safety-critical engineering | Spec + practitioner tooling |
| Fail-closed to a human-first fallback | Fail-safe engineering practice; EU AI Act Article 14 human oversight | Standard / law |
| Runtime allowlists and permission modes | **Claude Code permission modes** (`default`, `acceptEdits`, `plan`, `bypassPermissions`) plus `allowedTools`/`disallowedTools`; OpenAI "safe tools" confirmation prompts; LangChain tool permission callbacks | Practitioner tooling |

Two observations from the mapping:

- **The standards name the failure classes; this proposal encodes the
  boundary.** LLM06 asks reviewers to prevent "excessive functionality,
  permissions, and autonomy" without prescribing the mechanism. The guard's
  *domain* is the functionality boundary, its *authority ladder* is the
  permissions/autonomy boundary, and the sniff is the mechanical
  enforcement. The mapping is one-to-one.
- **The values-laden domain ban has only one regulatory ancestor** — the EU
  AI Act's prohibited-practices tier. "One thought too many" is a design
  principle, not a compliance category; Article 5 is the closest thing to it
  in law.

References:

- [OWASP Top 10 for LLM Applications (2025)](https://genai.owasp.org/resource/owasp-top-10-for-llm-applications-2025/)
- [OWASP Top 10 for Agentic Applications (2026)](https://genai.owasp.org/resource/owasp-top-10-for-agentic-applications-for-2026/)
- [OWASP Agent Control Standard — CSA research note](https://labs.cloudsecurityalliance.org/research/csa-research-note-owasp-genai-top10-2026-agent-control-stand/)
- [OWASP MCP Top 10](https://owasp.org/www-project-mcp-top-10/)
- [MCP blog — Tool Annotations as Risk Vocabulary (2026-03)](https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/)
- [MCP tool annotations reference](https://mcpblog.dev/blog/2026-03-13-mcp-tool-annotations)
- [EU AI Act — Regulation (EU) 2024/1689](https://eur-lex.europa.eu/eli/reg/2024/1689/oj)
- [CISA — Deploying AI Systems Securely (Five Eyes joint guidance)](https://www.cisa.gov/resources-tools/resources/deploying-ai-systems-securely)
- [Google AI Principles](https://ai.google/responsibility/principles/)
- [Claude Code — Permissions](https://code.claude.com/docs/en/permissions)

## Phase 1 — File table

| File | Change |
|---|---|
| `includes/services/class-wp-mcp-ai-decision-scope-guard.php` | **new** — the guard service |
| `includes/bootstrap/loader.php` | +1 require entry (next to the typesafe client) |
| `phpcs/WPMCPAI/Sniffs/Decisions/ScopeDeclaredSniff.php` | **new** — the sniff |
| `phpcs.xml.dist` | +1 rule registration (severity 5) |
| `includes/tools/class-wp-mcp-ai-tool-typesafe-decide.php` | declarations + gate (`advisory` / `inform`) |
| `includes/tools/class-wp-mcp-ai-tool-typesafe-guardrail.php` | declarations + gate (`content` / `inform`) |
| `includes/services/class-wp-mcp-ai-verification-cascade.php` | declarations + gate (`verification` / `suggest`) |
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-classifier.php` | declarations + gate (`operations` / `suggest`) |
| `addons/pro/includes/tools/jev/class-wp-mcp-ai-pro-tool-typesafe-rerank.php` | declarations + gate (`content` / `suggest`) |
| `addons/pro/includes/tools/jev/class-wp-mcp-ai-pro-tool-typesafe-skill-select.php` | declarations + gate (`operations` / `suggest`) |
| `includes/services/README.md` | +1 public-surface row |
| `tests/test-decision-scope-guard.php` | **new** — guard unit tests |

## Validation

- `vendor/bin/phpcs` on every changed PHP file (the new sniff must be clean
  on all six declarations, and no other file may trip it).
- `vendor/bin/phpunit tests/test-decision-scope-guard.php` plus the existing
  typesafe suites (`test-typesafe-client`, `test-typesafe-decide-tool`,
  `test-typesafe-guardrail-tool`, `test-verification-cascade`, Pro Jev suites)
  in the Docker environment per the `mcp-ai-wpoos-test-suite` skill.

## Risks

- **Sniff false positives** on local wrapper methods — mitigated by declaring
  wrappers too (visible decision paths are a feature).
- **Drift between the sniff's embedded domain lists and the guard constants**
  — keep-in-sync comments on both sides; a guard unit test pins the
  constant values so a rename breaks tests first.
- **Stale-build fatals** — call sites check
  `class_exists( 'WP_MCP_AI_Decision_Scope_Guard' )` and return their
  documented error (tools) or fail-open result (cascade) when missing.
- **No behavior change** — all six sites declare non-banned domains at
  `inform`/`suggest`, so the gate returns `true` today. The regression risk
  is lint-level, not runtime-level.

## NOT changed

- No changes to `Interface_WP_MCP_AI_Decision_Client`, either transport
  client, tool schemas, settings, thresholds, or tool counts.
- No feature flags: the guard is always-on hardening, not an opt-in.
- The guest-chat guardrail, tier routing, and citation cascades keep their
  existing opt-in flags and fail-open contracts unchanged.

## Deferred (Phase 2+, separate proposals/PRs)

1. **Identity-field scrub** — reject `people`-domain state carrying
   age/demographic fields, encoding "deny the numbers that make the formula
   possible" at the guard.
2. **Authority budget in the cascade** — `below_threshold` answers cannot
   aggregate upward; per-request cap on act-level verdicts (default zero).
3. **Fallback-bias tests** — assert every fail-open fallback defaults to the
   cautious, human-protective branch.
4. **Integration registry + Site Health panel** — every Jev consumer
   registered with domain/authority/flag, surfaced in admin so creep is
   visible to operators.
5. **`.context/security-checklist.md` entry** — in the release-window PR that
   lands this, per that file's maintenance convention.
