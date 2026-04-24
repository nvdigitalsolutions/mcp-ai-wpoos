# Privacy Matrix

The measurement subsystem uses four privacy tiers. Each tier dictates
redaction, storage, and export policy. Enforcement is progressive across
the pipeline so a single misclassification does not leak data.

| Tier | Example data | Redaction | Storage | Export policy |
|------|--------------|-----------|---------|---------------|
| `public` | Aggregated counts, request rates | None | Plain | Exportable without gating |
| `internal` | Latency, token counts, error codes | None | Plain | Exportable to site-owner APMs |
| `sensitive` | User names, emails, IP classes | Regex PII scrubbing; pluggable NER scrubber | Plain + optional field-level encryption (libsodium) | Require explicit allow-list per exporter |
| `restricted` | PHI, attorney-client material, SOX data | Full redaction; tokenization | Encrypted at rest (libsodium) | Never exported by default; requires BAA or equivalent |

## Pluggable PII scrubber

The base plugin ships a regex-based scrubber covering common PII shapes
(email, phone, SSN). Customers can plug in a more capable scrubber — for
example a PHI-aware NER model — by implementing
`WP_MCP_AI_PII_Scrubber_Interface` (PR 2) and attaching to the
`wp_mcp_ai_measurement_export` filter or the sensitive-write path.

## Retention

Defaults (PR 4 implements):

- `public` / `internal`: 30 days raw, 365 days aggregate.
- `sensitive`: 14 days raw, 365 days aggregate.
- `restricted`: raw never persisted (in-memory + immediate aggregate).

All retention values are filterable via `wp_mcp_ai_measurement_retention`
and enforced by a nightly cron job.

## GDPR / CCPA erasure

The subsystem honors WordPress's personal-data eraser via
`wp_mcp_ai_measurements_on_user_erase`. Calling the eraser removes raw
measurements tied to the user from the custom table and broadcasts an
erasure event to any registered exporter that declares erasure support.

## Consent-aware logging

- Authenticated users: logging respects the site's default consent flags.
- Guest users: `sensitive` and `restricted` tiers are never persisted for
  guest traffic unless the site has declared a lawful basis via
  `wp_mcp_ai_measurement_guest_consent`.
- Eval runs: follow whatever consent the eval fixture declares; holdout
  sets are assumed synthetic.
