=== NV oOS Forge Bridge ===
Contributors: vsamtani
Tags: headless, rebuild, webhook, forms, redirects
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Signed rebuild webhooks, single-use preview tokens, a static-site form bridge, and a read-only redirect export for WordPress.

== Description ==

NV oOS Forge Bridge is the WordPress half of a CMS-to-CI rebuild loop, and it works standalone. WordPress stays the CMS; the plugin tells your build pipeline when content moved. Any pipeline that can consume a GitHub `repository_dispatch` (or a signed webhook) is a valid counterpart — GitHub Actions, Netlify, Vercel, or a custom CI — including the NV oOS Astro Forge generator, which uses this plugin as its reference bridge.

* **Rebuild webhook** — every post, page, product, or term save/trash/delete, plus front-page setting changes, schedules one debounced, HMAC-signed `repository_dispatch` POST (10-minute debounce, in-request dedupe). Autosaves, revisions, and auto-drafts are ignored — a rebuild is never wasted on content that does not ship. The request carries the `X-Forge-Signature: sha256=…` header AND a self-verifying signature inside the payload (HMAC over `time.nonce`), so the CI pipeline fails closed on spoofed or stale triggers. Disabled until configured.
* **Inbound rebuild trigger** — `POST /wp-json/nvoos-forge/v1/rebuild` accepts only requests carrying a valid signature, so external systems can request a rebuild with proof of the shared secret.
* **Preview tokens** — `POST /wp-json/nvoos-forge/v1/preview-tokens` (editors and above) mints a single-use, 15-minute bearer token; `POST …/preview-tokens/verify` with the token consumes it. Tokens are stored hashed, never in plaintext.
* **Health** — `GET /wp-json/nvoos-forge/v1/health` returns a redacted status (versions, booleans, the webhook host) — never tokens, secrets, or full URLs.
* **Form submissions from a generated static site** — `POST /wp-json/nvoos-forge/v1/forms/submit` accepts the forged site's rebuilt forms and dispatches them to the NATIVE handler (JetFormBuilder's form processor or Elementor Pro's form ajax handler), so validation, storage, mail, and post-submit actions run exactly as on WordPress — the plugin never reimplements form handling. Submissions are gated by a CORS origin allowlist (your static origin, one per line), per-IP rate limits, a mandatory honeypot + timestamp spam floor (optional Cloudflare Turnstile with the `action` and `hostname` pinned to your allowlisted origin), and server-side sanitization of every field. Unknown form → 404; no form handler → 501; caught bots get a silent success. The no-JavaScript fallback is an ordinary form POST that redirects back to the static page with `?form=ok|error`.
* **Stored redirects for a generated static site** — `GET /wp-json/nvoos-forge/v1/redirects` normalizes the redirect rules already stored by **Redirection** (`redirection_items`) and **Rank Math** (`rank_math_redirections`) into `{ source, target, type }`, so a forged static site keeps legacy URLs alive on cutover day. Read-only and opt-in. Only enabled, exact URL matches are exported (max 500, `truncated` reported); regex, query-scoped, and non-URL match types cannot be expressed portably in a static `_redirects` file and are returned as skipped counts instead of being silently widened. An optional shared token gates the endpoint when you do not want your URL structure publicly readable.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install from the WordPress plugin directory.
2. Activate **NV oOS Forge Bridge**.
3. Go to **Settings → Forge Bridge** and configure the GitHub dispatches URL, a repo-scoped token with dispatch rights, and a shared HMAC secret (the CI side verifies the signature with the same secret).

== Frequently Asked Questions ==

= Does it send anything before it is configured? =

No. Without an enabled flag, URL, token, and secret, every hook is a no-op.

= Does it require the NV oOS Astro Forge? =

No. The contract is a signed GitHub `repository_dispatch` plus a shared HMAC secret — nothing Astro-specific. Any build pipeline that consumes it (GitHub Actions, Netlify, Vercel, custom CI) works with this plugin. The NV oOS Astro Forge is the reference consumer, not a requirement.

= What triggers a rebuild? =

Post, page, product, and term saves, trashes, and deletions, plus changes to the front-page, site-title, and tagline options — all debounced to one dispatch per 10 minutes. Autosaves, revisions, and auto-drafts never trigger.

= Are my credentials exposed? =

The token and secret are write-only in the settings UI (never echoed), excluded from the health endpoint, and never logged.

= Does this plugin call any external services? =

Only after you configure it. The rebuild webhook sends one signed POST to the GitHub API host you entered (by default `https://api.github.com`) per debounce window, from your server, containing the repository dispatch payload. Nothing is sent until the URL, token, and shared secret are saved, and no data is ever sent to the browser.

= Does the forms bridge call any external services? =

Only when you enable the optional captcha. With Cloudflare Turnstile selected, each form submission triggers one server-to-server verification call to Cloudflare (`https://challenges.cloudflare.com/turnstile/v0/siteverify`). Without the captcha, the forms bridge makes no external calls at all — it dispatches submissions to your own WordPress form plugins, in-process.

= The site is behind a reverse proxy or CDN — will the per-IP rate limit work? =

The limit keys on the connection's real peer address (`REMOTE_ADDR`). If your host terminates traffic at a trusted proxy (a load balancer, or a CDN that connects from fixed ranges), add that proxy's IP or CIDR range through the `nvoos_forge_bridge_trusted_proxies` filter so the forwarded client address is used. A forwarding header is ignored by default because it is client-supplied and trivially spoofed.

== External Services ==

This plugin connects to the GitHub API (`https://api.github.com`, or the custom dispatches URL you configure) to trigger a rebuild of your generated frontend.

* **What and when:** one `repository_dispatch` POST per content change, debounced to once every 10 minutes, sent server-to-server on the WordPress shutdown hook.
* **What is sent:** the repository dispatch payload (event type, your site URL, and a timestamp/nonce/signature proof the build pipeline re-verifies). The request is also signed with the `X-Forge-Signature: sha256=…` header using the shared secret you configure.
* **No account data is collected or tracked**, and nothing is sent unless you have enabled the webhook and saved a dispatches URL, token, and secret.
* **GitHub Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* **GitHub Privacy Statement:** https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

Optionally, when you enable the Cloudflare Turnstile captcha for the forms bridge, each form submission triggers one server-to-server verification call to `https://challenges.cloudflare.com/turnstile/v0/siteverify` (your Turnstile secret plus the visitor's token and IP address). Without the captcha, the forms bridge makes no external calls.

* **Cloudflare Terms of Service:** https://www.cloudflare.com/website-terms/
* **Cloudflare Privacy Policy:** https://www.cloudflare.com/privacypolicy/

== Source Code ==

The uncompiled source of this plugin lives in the public repository at https://github.com/nvdigitalsolutions/mcp-ai-wpoos under `plugins/nvoos-forge-bridge/`. The plugin ships no compiled assets; everything in the package is the readable source.

== Screenshots ==

1. Settings page for the rebuild webhook before configuration — the warning notice explains that content changes do not trigger rebuilds yet.
2. Settings page after configuring the GitHub dispatches URL, token, and HMAC secret — credentials are write-only, shown masked.

== Changelog ==

= 0.3.1 =
* Security: the public form endpoint only dispatches to PUBLISHED, non-password-protected forms. A draft, pending, private, trashed, or password-protected JetFormBuilder / Elementor form is treated as unknown (404) instead of being submitted to.
* Security: a submitted field can no longer spoof JetFormBuilder's routing carriers (`_jet_engine_booking_form_id` / `_jet_engine_refer`) to re-target a dispatch to a different form — the resolved, visibility-checked identifier always wins.
* Security: the per-IP rate limit no longer trusts a client-supplied `X-Forwarded-For` header. `REMOTE_ADDR` is used by default; a forwarding chain is honored only when the direct peer is a configured trusted proxy (`nvoos_forge_bridge_trusted_proxies`), and then the rightmost untrusted hop is used.
* Security: the redirects export rejects control characters in stored sources/targets (a newline could inject an extra rule into a static `_redirects` file) and decodes provider columns without instantiating PHP objects.
* Fix: native `wp_send_json()` calls from JetFormBuilder / Elementor no longer terminate the REST request. The dispatch runs with `wp_doing_ajax()` true and the echoed payload is captured under an output buffer, so the bridge always returns its own envelope.
* Fix: uninstall now also removes the hashed per-IP rate-limit transients.

= 0.3.0 =
* New: stored redirect export — `GET /nvoos-forge/v1/redirects` normalizes Redirection (`redirection_items`) and Rank Math (`rank_math_redirections`) rules into `{ source, target, type }` for the forged static site's `_redirects` map. Enabled/group-status aware, exact-URL only, bounded to 500 rules with an explicit `truncated` flag, skipped-count reporting for regex/query/non-URL matches, optional shared-token gate.
* New: health reports `redirectsEnabled`, `redirectsAvailable`, and the detected provider list; Settings → Forge Bridge gains a stored-redirects section with provider detection.

= 0.2.0 =
* New: form submission bridge for forged static sites — `POST /nvoos-forge/v1/forms/submit` dispatches to the native JetFormBuilder / Elementor Pro handlers (origin allowlist, per-IP rate limit, honeypot + timestamp spam floor, optional Cloudflare Turnstile with action/hostname pinning, server-side field sanitization, typed 404/501 contracts, no-JS redirect fallback).
* Fix: a failed rebuild webhook request no longer raises a fatal error at shutdown — the send method no longer declares a return type the HTTP layer cannot honor, so a failed request returns an error object instead of crashing.

= 0.1.0 =
* Initial release: signed rebuild webhook (autosave/revision/auto-draft skip, trash + delete hooks), inbound rebuild trigger, single-use preview tokens (editors+, POST-only verify), redacted health endpoint.
