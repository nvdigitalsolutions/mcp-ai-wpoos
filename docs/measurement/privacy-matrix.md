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

PR 9 implements persistent raw-event storage with per-tier TTLs
enforced by a daily cron. Defaults (all filterable via
`wp_mcp_ai_measurement_retention`):

- `public`:    365 days
- `internal`:   90 days
- `sensitive`:  30 days
- `restricted`: **never persisted** (in-memory buffer only; the
  persister and the event store both refuse to write Restricted
  rows)

The values above are the current implementation. Earlier drafts
proposed shorter TTLs; the 30d/14d draft is superseded by PR 9.
See `persistent-store.md` for the detailed retention contract,
including the reconciliation with the rollout plan.

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
