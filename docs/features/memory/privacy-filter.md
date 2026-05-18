# Memory Privacy Filter

> Status: Phase 1 of the 2026 Memory Layer Enhancements — shipped in NV oOS 1.1.20.

## What this is

A redaction pass that runs on every memory write — at filter priority **5**,
*before* any user-supplied transform at the default priority 10 — and strips
the most common secret formats from the record before it is persisted.

This is the redactor referenced by the long-standing contract documented in
`includes/services/class-wp-mcp-ai-memory-capture-service.php:125`:

> Verbatim records run through here too — a redactor is the only sanctioned
> way to drop PHI / secrets BEFORE the verbatim discipline kicks in.

Verbatim discipline preserves *surviving* content; it does **not** bypass
redaction. A verbatim memory whose content contains an OpenAI key still has
that key replaced with `[REDACTED]` before storage. The flag, the wing/room
scope, the tags, and everything else survive.

## Default redaction patterns

| Pattern label | Matches |
|---|---|
| `openai_key` | `sk-...` and `sk-proj-...` |
| `anthropic_key` | `sk-ant-...` |
| `aws_access_key` | `AKIA` followed by 16 uppercase alphanumerics |
| `aws_secret_key` | `aws_secret_access_key = ...` lines |
| `github_pat` / `github_server_token` / `github_oauth_token` | `ghp_`, `ghs_`, `gho_` followed by 36 chars |
| `google_api_key` | `AIza` followed by 35 chars |
| `slack_token` | `xoxb-`, `xoxa-`, `xoxp-`, `xoxr-`, `xoxs-` tokens |
| `stripe_secret_key` | `sk_live_...` and `sk_test_...` |
| `bearer_token` | The token portion of `Authorization: Bearer <...>` strings |
| `private_block` | Anything wrapped in `<private>...</private>` |
| `pem_private_key` | The full PEM block from `-----BEGIN PRIVATE KEY-----` to `-----END PRIVATE KEY-----` |

The set is filterable. See **Customisation** below.

## Where it runs

```
store_agent_context / mine_agent_memory / WP_MCP_AI_Memory_Capture_Service::store()
                                  │
                                  ▼
    apply_filters( 'wp_mcp_ai_memory_pre_store_transform', $record, ... )
                                  │
                                  ├─ priority 5 ── WP_MCP_AI_Memory_Privacy_Filter ◀── this doc
                                  ├─ priority 10 ─ user transforms / summarizers
                                  └─ priority 20+ user redactors
                                  │
                                  ▼
              persisted into transients / CCT / Graphify edges
```

The filter is registered exactly once at plugin bootstrap (in
`includes/bootstrap/loader.php`, immediately after the memory capture service
and tier manager load).

## Customisation

### Master kill-switch

```php
add_filter( 'wp_mcp_ai_memory_privacy_filter_enabled', '__return_false' );
```

Strongly discouraged. Disabling this means the next time the agent has a
conversation about API keys, those keys end up in long-term memory and replay
on every session boot via `wake_up_context`.

### Adding custom patterns

```php
add_filter( 'wp_mcp_ai_memory_privacy_patterns', function ( $patterns ) {
    $patterns['internal_token'] = '/\bINT-[A-Z0-9]{12,}\b/';
    $patterns['employee_id']    = '/\bEMP-\d{6}\b/';
    return $patterns;
} );
```

Custom patterns are merged with the defaults. To *replace* the defaults,
return your own set. To disable the filter entirely without disabling the
service, return an empty array (the filter still runs but performs no work).

### Customising the replacement

```php
add_filter( 'wp_mcp_ai_memory_privacy_replacement', function () {
    return '***SECRET***';
} );
```

Default: `[REDACTED]`. Empty / non-string replacements fall back to the
default.

### Logging redactions to the audit trail

```php
add_filter( 'wp_mcp_ai_memory_privacy_log_redactions', '__return_true' );

add_action( 'wp_mcp_ai_memory_privacy_redacted', function ( $count, $record ) {
    // Record the redaction count without exposing the original secret.
    WP_MCP_AI_Logger::log_security_event(
        'memory.privacy.redacted',
        array(
            'count'        => $count,
            'context_id'   => $record['context_id'] ?? '',
            'agent_id'     => $record['agent_id'] ?? '',
            'redacted_at'  => time(),
        )
    );
}, 10, 2 );
```

By default the action does **not** fire — turn on logging only when you have
a sink ready to receive it (the action's payload never contains the original
secret, just the post-redaction record).

## Direct API

Headless callers and sibling services (e.g. the Phase 3 auto-capture service)
can redact a string directly without going through the filter chain:

```php
$clean = WP_MCP_AI_Memory_Privacy_Filter::redact( $raw );
```

## Resilience

- **Broken regex contributed via the filter** does not crash the request; the
  filter logs `null` returns from `preg_replace()` and continues with the
  remaining patterns.
- **Non-array input** is returned unchanged (e.g. when an upstream listener
  returned a string before our filter ran).
- **Non-string scalars** (`int`, `bool`, `null`) inside the record are passed
  through unchanged.

## Threat model

The filter is a *post-write redaction net*, not a substitute for:

- Sanitising chat input at the REST entry point (already done by
  `WP_MCP_AI_REST_Chat_Memory_Controller`).
- Capability checks on memory writes (`edit_posts` on the chat-memory bridge
  for write routes).
- Privacy export / erasure (covered by the JetEngine privacy exporters added
  in v1.1.19).

It exists because *agents themselves* sometimes generate secrets verbatim
(echoing a tool result, summarising a config file, repeating a user's
question) and those agent-generated strings bypass the REST entry-point
sanitisers.

## Tests

`tests/test-memory-privacy-filter.php` — 22 cases covering every default
pattern, recursive redaction, both filter signatures (2-arg + 6-arg),
idempotent bootstrap, kill-switch, custom patterns, custom replacement,
verbatim discipline, broken-regex resilience, and audit-trail action firing.
