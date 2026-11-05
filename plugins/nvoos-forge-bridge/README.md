# NV oOS Forge Bridge

A standalone WordPress plugin that signs content changes into a CMS-to-CI
rebuild loop. WordPress stays the CMS; the plugin tells your build pipeline
when content moved — signed, debounced, and fail-closed. Any
`repository_dispatch` consumer works (GitHub Actions, Netlify, Vercel,
custom CI); the NV oOS **Astro Forge** (Proposal 096) is the reference
consumer, not a requirement.

## What it does

| Surface | Detail |
| --- | --- |
| Rebuild webhook | Content changes (posts, pages, products, terms — saves, trashes, and deletions; front-page/title/tagline options) schedule one debounced `repository_dispatch` POST with an `X-Forge-Signature: sha256=<hex>` HMAC header (10-minute debounce, in-request dedupe, sent on shutdown so saves never block). Autosaves, revisions, and auto-drafts are ignored. The payload carries a self-verifying signature over `time.nonce` as well — GitHub's dispatch delivery re-serializes the body, so the generated pipeline (`verify-dispatch.mjs`, Forge Phase 3.3) re-verifies that value and fails closed on stale or mismatched dispatches. |
| Inbound trigger | `POST /wp-json/nvoos-forge/v1/rebuild` — accepted only with a valid signature over the body. |
| Preview tokens | `POST /wp-json/nvoos-forge/v1/preview-tokens` (editors+) mints a single-use, 15-minute token; `POST /wp-json/nvoos-forge/v1/preview-tokens/verify` consumes it (POST-only — verification is a state change). Stored hashed only. |
| **Form submissions** | `POST /wp-json/nvoos-forge/v1/forms/submit` — the forged static site's rebuilt forms post here and the plugin dispatches them to the NATIVE handler (JetFormBuilder's form processor or Elementor Pro's form ajax handler), so validation, storage, mail, and post-submit actions run exactly as on WordPress. Gated by: a CORS origin allowlist, per-IP rate limits, a mandatory honeypot + timestamp spam floor (optional Cloudflare Turnstile with `action` + `hostname` pinned), and server-side sanitization of every field. Unknown form → 404; no handler → 501; a caught bot → silent 200. The no-JS fallback is an ordinary form-encoded POST that redirects back to the static page with `?form=ok|error`. |
| Health | `GET /wp-json/nvoos-forge/v1/health` — redacted (versions, booleans, webhook host, forms flags/counts). |

## Consumers

The wire contract is a signed GitHub `repository_dispatch` POST plus a
shared HMAC secret — nothing Astro-specific:

- **NV oOS Astro Forge** (`nvoos-astro-forge`) — the reference consumer;
  its generated pipeline re-verifies the payload proof.
- **GitHub Actions** — a `repository_dispatch` workflow that verifies the
  `X-Forge-Signature` header and rebuilds.
- **Netlify / Vercel / custom CI** — a small receiver that forwards
  verified dispatches to their build hooks works the same way.

Unconfigured, the plugin is a complete no-op: it only ever speaks to the
dispatches URL you set (default `https://api.github.com`).

## Configuration

**Settings → Forge Bridge**: dispatches URL (`https://api.github.com/repos/OWNER/REPO/dispatches`),
a repo-scoped GitHub token with dispatch rights, a shared HMAC secret, and
the event type. The token and secret are write-only — never echoed.

The CI side of the loop (the Forge deploy pipeline) verifies the signature
with the same shared secret and fails closed when it does not match.

**Forms** (the same settings page): enable submissions and allowlist the
static origin(s) that host the forged frontend (one full origin per line).
Optionally add Cloudflare Turnstile (site key + secret) — every submission
must then carry a fresh token whose `action` (`nvoos_forge_form`) and
`hostname` are pinned to your allowlisted origin. The forged site posts JSON
`{ form, post_id?, fields, honeypot, timestamp, page?, token? }` to
`/wp-json/nvoos-forge/v1/forms/submit`; the handler plugins (JetFormBuilder
or Elementor Pro) own validation, storage, mail, and post-submit actions —
the bridge only dispatches to them, never reimplements them. Submission
outcomes surface through the `nvoos_forge_bridge_form_submission` action
(`accepted` | `rejected`) — an audit seam you can wire to any logger.

## Development

```bash
composer install          # PHPUnit + WPCS
composer lint             # phpcs (own standard)
composer test             # phpunit (own config; WP tests via wp-phpunit)
```

Tests cover: configuration gating, debounce/dedupe, HMAC signing +
verification, the outbound POST contract (intercepted, zero network), REST
routes, capability gates, token single-use/expiry, health redaction, and the
forms bridge — origin/rate gates, the honeypot/timestamp spam ladder, the
JetFormBuilder + Elementor dispatch seams (zero HTTP, the checkout-proxy
pattern), captcha (intercepted `pre_http_request`), the typed 404/501
contracts, and the no-JS redirect floor.

## wp.org readiness

The `plugin-check` job in `.github/workflows/phpunit-forge-bridge.yml` runs
the WordPress.org Plugin Check gate against the ZIP-shaped plugin tree
(ERRORs block the PR); the shipping shape is defined by `.distignore` (keep
the two exclusion lists in sync). Listing assets (screenshots captured via
`bin/capture-nvoos-forge-bridge-screenshots.js`; icon/banner generated via
Gemini and processed by `bin/process-nvoos-forge-bridge-artwork.js`) and the
SVN upload runbook live in `.wordpress-org/`. The submission ZIP is built by
`bin/build-nvoos-forge-bridge.py`; the 18-point signoff lives in
`WPORG-REVIEW-18POINT-SIGNOFF.md` (excluded from the ZIP).

## License

GPLv3 or later — see `LICENSE`.
