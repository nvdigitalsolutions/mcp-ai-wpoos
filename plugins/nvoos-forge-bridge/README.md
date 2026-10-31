# NV oOS Forge Bridge

The WordPress half of the **Astro Forge** freshness loop (Proposal 096):
WordPress stays the CMS, and this plugin tells the build pipeline when
content moved — signed, debounced, and fail-closed.

## What it does

| Surface | Detail |
| --- | --- |
| Rebuild webhook | Content changes (posts, pages, products, terms, front-page/title/tagline options) schedule one debounced `repository_dispatch` POST with an `X-Forge-Signature: sha256=<hex>` HMAC header (10-minute debounce, in-request dedupe, sent on shutdown so saves never block). The payload carries a self-verifying signature over `time.nonce` as well — GitHub's dispatch delivery re-serializes the body, so the generated pipeline (`verify-dispatch.mjs`, Forge Phase 3.3) re-verifies that value and fails closed on stale or mismatched dispatches. |
| Inbound trigger | `POST /wp-json/nvoos-forge/v1/rebuild` — accepted only with a valid signature over the body. |
| Preview tokens | `POST /wp-json/nvoos-forge/v1/preview-tokens` (editors+) mints a single-use, 15-minute token; `GET /wp-json/nvoos-forge/v1/preview-tokens/verify?token=…` consumes it. Stored hashed only. |
| Health | `GET /wp-json/nvoos-forge/v1/health` — redacted (versions, booleans, webhook host). |

## Configuration

**Settings → Forge Bridge**: dispatches URL (`https://api.github.com/repos/OWNER/REPO/dispatches`),
a repo-scoped GitHub token with dispatch rights, a shared HMAC secret, and
the event type. The token and secret are write-only — never echoed.

The CI side of the loop (the Forge deploy pipeline) verifies the signature
with the same shared secret and fails closed when it does not match.

## Development

```bash
composer install          # PHPUnit + WPCS
composer lint             # phpcs (own standard)
composer test             # phpunit (own config; WP tests via wp-phpunit)
```

Tests cover: configuration gating, debounce/dedupe, HMAC signing +
verification, the outbound POST contract (intercepted, zero network), REST
routes, capability gates, token single-use/expiry, and health redaction.

## wp.org readiness

The `plugin-check` job in `.github/workflows/phpunit-forge-bridge.yml` runs
the WordPress.org Plugin Check gate against the ZIP-shaped plugin tree
(ERRORs block the PR); the shipping shape is defined by `.distignore` (keep
the two exclusion lists in sync). Listing assets (screenshots captured via
`bin/capture-nvoos-forge-bridge-screenshots.js`, icon/banner artwork TODO)
and the SVN upload runbook live in `.wordpress-org/`.

## License

GPLv3 or later — see `LICENSE`.
