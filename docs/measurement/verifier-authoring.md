# Verifier Authoring Guide

Verifiers inspect a generator's output and return a structured judgement.
They are the backbone of NV oOS's measurement stack and the source of truth
for reward signals — so they must be **independent, deterministic where
possible, and bounded in the pathologies they can introduce**.

## The contract

Every verifier implements `WP_MCP_AI_Verifier_Interface`:

```php
public function get_slug();
public function get_label();
public function get_kind();              // rule | schema | llm_judge | external_peer | human
public function get_independence_profile();
public function verify( array $subject, array $context = array() );
```

`verify()` returns either an array:

```php
[
  'passed'     => true|false,
  'score'      => 0.0..1.0,
  'confidence' => 0.0..1.0,
  'reasons'    => string[],
  'evidence'   => array,
]
```

…or a `WP_Error` if the verifier genuinely cannot make a judgement (not to be
used for routine verification failures — use `passed=false` for those).

`WP_MCP_AI_Verifier_Base` gives you `result_pass()` and `result_fail()`
helpers plus `clamp()` so you rarely need to build the array by hand.

## Verifier kinds

| Kind | Use | Independence expectation |
|------|-----|--------------------------|
| `rule` | Deterministic predicates (regex, enums, bounds) | Trivially independent |
| `schema` | Structural validation (JSON Schema subset) | Trivially independent |
| `llm_judge` | Ask an LLM to judge | **Must** disallow the generator's provider/model |
| `external_peer` | Federation peer verifies | Must share nothing beyond hashes + structured claims |
| `human` | Human-in-the-loop queue | Independent by construction |

## Reference verifiers

The base plugin ships three reference verifiers. Each is registered on
`wp_mcp_ai_register_verifiers` at priority 20 so site-specific verifiers
(default priority 10) can pre-empt them by slug.

### `rule_verifier`

Declarative rule chain. Each rule has a `type`, a dotted `path`, and
optional `value` / `callback` / `weight` / `message`. Supported types:

- `required` — path must exist and be non-empty.
- `pattern` — string must match a PCRE regex.
- `enum` — value must be in the provided list (strict equality).
- `min` / `max` — numeric bounds.
- `callback` — any callable returning bool.

Example:

```php
add_action( 'wp_mcp_ai_register_verifiers', function ( $registry ) {
    $registry->register( new WP_MCP_AI_Rule_Verifier(
        'citation_rules',
        [
            [ 'type' => 'required', 'path' => 'answer.citations' ],
            [ 'type' => 'pattern',  'path' => 'answer.citations.0.url', 'value' => '#^https://#' ],
        ]
    ) );
} );
```

Rules can also be filtered at runtime via `wp_mcp_ai_rule_verifier_rules`.

### `schema_verifier`

Lightweight JSON Schema subset. Supported keywords: `type`, `required`,
`properties`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`,
`pattern`, `items`. For richer validation (combinators, refs, draft-2020
keywords), register a custom verifier backed by a full JSON Schema library.

### `llm_judge`

Pluggable LLM-as-judge. Core does NOT bundle a vendor SDK; you supply a
callable via the constructor or the `wp_mcp_ai_llm_judge_callable` filter.
If no callable is configured, the verifier abstains (score `0.5`,
confidence `0.0`, `evidence.abstained = true`) rather than pretending to
verify — abstention is a first-class signal in the Goodhart dashboard.

```php
add_filter( 'wp_mcp_ai_llm_judge_callable', function ( $current ) {
    return function ( $subject, $context ) {
        // Call a DIFFERENT provider from the generator here.
        return [ 'passed' => true, 'score' => 0.9, 'confidence' => 0.8, 'reasons' => [ 'ok' ] ];
    };
} );
```

## Independence (verifier's law)

Populate `independence_profile` with every provider/model/tool the verifier
must NOT share with the generator:

```php
$this->independence_profile = [
    'disallowed_providers' => [ 'openai' ],
    'disallowed_models'    => [ 'gpt-5' ],
    'disallowed_tools'     => [],
    'allowed_domains'      => [],
];
```

When the verifier runs via `WP_MCP_AI_Verifier_Registry::run()`, the
registry checks the generator context and rejects the run with
`wp_mcp_ai_verifier_not_independent` if the verifier shares provenance.

## Tips

- Prefer rule/schema verifiers for narrow checks — they are cheap and
  cannot hallucinate.
- Use LLM judges sparingly; pair them with a deterministic verifier and
  a human-in-the-loop sample for calibration.
- Always return confidence. The calibration reward function uses it to
  compute Brier scores.
- Never include raw prompts or user content in `evidence` — hash first.
