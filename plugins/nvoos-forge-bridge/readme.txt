=== NV oOS Forge Bridge ===
Contributors: nvdigitalsolutions
Tags: headless, rebuild, webhook, astro, ci
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Fires a signed GitHub repository_dispatch when content changes so an Astro Forge frontend rebuilds automatically. Mints single-use preview tokens.

== Description ==

The NV oOS Forge Bridge is the WordPress half of the Astro Forge freshness loop (Proposal 096): WordPress stays the CMS, and this plugin tells the build pipeline when content moved.

* **Rebuild webhook** — every post, page, product, term, or front-page setting change schedules one debounced, HMAC-signed `repository_dispatch` POST (10-minute debounce, in-request dedupe). The CI pipeline verifies the `X-Forge-Signature: sha256=…` header and fails closed on spoofed triggers. Disabled until configured.
* **Inbound rebuild trigger** — `POST /wp-json/nvoos-forge/v1/rebuild` accepts only requests carrying a valid signature, so external systems can request a rebuild with proof of the shared secret.
* **Preview tokens** — `POST /wp-json/nvoos-forge/v1/preview-tokens` (editors+) mints a single-use, 15-minute bearer token; `GET …/preview-tokens/verify?token=…` consumes it. Tokens are stored hashed, never in plaintext.
* **Health** — `GET /wp-json/nvoos-forge/v1/health` returns a redacted status (versions, booleans, the webhook host) — never tokens, secrets, or full URLs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install from the WordPress plugin directory.
2. Activate **NV oOS Forge Bridge**.
3. Go to **Settings → Forge Bridge** and configure the GitHub dispatches URL, a repo-scoped token with dispatch rights, and a shared HMAC secret (the CI side verifies the signature with the same secret).

== Frequently Asked Questions ==

= Does it send anything before it is configured? =

No. Without an enabled flag, URL, token, and secret, every hook is a no-op.

= What triggers a rebuild? =

Post, page, product, and term saves/deletions, plus changes to the front-page, site-title, and tagline options — all debounced to one dispatch per 10 minutes.

= Are my credentials exposed? =

The token and secret are write-only in the settings UI (never echoed), excluded from the health endpoint, and never logged.

== Changelog ==

= 0.1.0 =
* Initial release: signed rebuild webhook, inbound rebuild trigger, preview tokens, redacted health endpoint.
