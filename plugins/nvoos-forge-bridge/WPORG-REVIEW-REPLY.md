# NV oOS Forge Bridge — wp.org Review Reply Draft (0.3.1)

Review ID: `R nvoos-forge-bridge/vsamtani/5Nov26/T2 10Nov26/4.4RC1 (P0TDX399590HGN)`

Excluded from the shipping ZIP via `.distignore` (`/WPORG-REVIEW-*.md`).
Reply to the review thread; keep it short and factual per the team's request.

---

Hi,

Thanks for the review. All three findings are addressed in 0.3.1.

**1. Permission callback / non-public forms**

`POST /forms/submit` is public by design — anonymous visitors submit forms from
the generated static site, so no WordPress capability applies. `__return_true`
remains, with the authorization model documented inline. The substantive issue
is fixed: the route no longer dispatches to non-public forms.

- JetFormBuilder resolution now requires `post_status = publish` and an empty
  post password. Draft, pending, private, trashed, and password-protected forms
  resolve to "not found".
- The Elementor lookup now joins `wp_posts` and applies the same two conditions,
  for both the `post_id`-scoped and global lookups.
- Non-public forms return the same typed 404 as a missing form, so drafts are
  not enumerable.

**2. X-Forwarded-For**

The rate-limit key no longer trusts a client-supplied forwarding header.
`REMOTE_ADDR` is authoritative by default. A forwarding chain is honored only
when the direct peer is a configured trusted proxy (new
`nvoos_forge_bridge_trusted_proxies` filter accepting exact IPs or CIDR ranges,
empty by default). When trusted, the chain is walked right-to-left and the
first untrusted hop is used, so a client-supplied prefix cannot spoof the
value. The same resolved IP is passed to Turnstile.

**3. `wp_send_json()` ending the REST request**

Correct — in a REST request `wp_send_json()` echoes and then calls `die`
directly, so the `wp_die_*_handler` filters never ran. The dispatch now:

- runs with `wp_doing_ajax()` true — the ajax contract the native handlers are
  written for — so `wp_send_json()` routes through `wp_die()`;
- swaps the die callback for one that throws a capture sentinel instead of
  exiting; and
- buffers the echoed JSON with `ob_start()` and decodes it.

So the bridge always returns its own envelope and a native handler can no
longer terminate the REST request. Filters and buffers are restored in
`finally`, including on error.

**Related-issue sweep**

Every remaining public route is intentionally public and documented as such:
`/health` (versions, booleans, and the webhook host only), `/preview-tokens/verify`
(the single-use bearer token in the body is the proof). `/rebuild` stays
HMAC-gated, `/preview-tokens` stays capability-gated (`edit_pages`), and
`/redirects` keeps its optional shared-token gate.

The same classes were audited across the plugin, which found and fixed three
more items in 0.3.1:

- A submitted field could spoof JetFormBuilder's routing carriers
  (`_jet_engine_booking_form_id` / `_jet_engine_refer`) and re-target the
  dispatch to a different form. Carriers are now stripped from submitted
  fields and re-asserted after them.
- The redirects export rejects control characters in stored sources/targets
  (newline injection into a static `_redirects` file) and decodes provider
  columns without instantiating PHP objects.
- Uninstall now removes the hashed per-IP rate-limit transients.

To keep this from regressing, CI now runs a small gate
(`.github/scripts/check-forge-bridge-public-routes.php`) that fails when a
public CREATABLE / EDITABLE / DELETABLE route is not on a reviewed allowlist
(with justifications) — and when an allowlisted route goes stale.

**Validation**

- PHPUnit on WP 6.9 / PHP 8.2: 71 tests, 204 assertions, passing. New coverage
  for non-public JetFormBuilder and Elementor forms, spoofed `X-Forwarded-For`
  with and without a trusted proxy, the response capture (including a native
  `wp_send_json_success()` call run in an isolated process), the routing-carrier
  override, and redirect control-character / serialized-object rejection.
- `phpcs` (WPCS + PHPCompatibilityWP, PHP 7.4+) clean; `php -l` clean.

Keeping the permalink `nvoos-forge-bridge`.

Thanks,
V.
