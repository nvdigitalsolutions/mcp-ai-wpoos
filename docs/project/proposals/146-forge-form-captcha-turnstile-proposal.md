# Proposal 146 — form captcha (Turnstile) across the direct/Brevo path, the bridge path and both Astro products

> Status: **Proposed 2026-11-13**. Not implemented.
> Extends 138 (form email delivery — `bridge` vs `direct`), 114 §3.9 (the form
> bridge), 130 (tracking + CSP headers), 135 (managed tier).
> Touches 092 / mcp-ai-wpoos-astro-storefront (velocity shares `mail.ts` and the
> form endpoint contract).
>
> Origin: the virtuallyjamaica.com rebuild. It runs `forms.delivery: "direct"`
> (Brevo) with **two live forms** posting to `/api/forms/submit` and **no
> captcha of any kind** — the bridge's Turnstile gate is real, but this site
> never touches the bridge.
>
> Companion implementation plan: not yet written (P0 below is the deliverable).

---

## 1. Executive summary

The forge already has a *correct* captcha implementation — on the surface that
half the sites do not use. The gap is exactly one branch wide:

| Path | Captcha today |
| --- | --- |
| `forms.delivery: 'bridge'` → WordPress bridge | **Turnstile**, fully implemented (`verify_turnstile()`: action pinning + hostname pinning + fail-closed) |
| `forms.delivery: 'direct'` → Brevo/Mailjet from the managed runtime | **none** — 64 KB cap, honeypot, time trap, per-IP throttle, idempotency, and nothing else |
| Velocity storefront (`/forms/submit`, always direct) | **none** |
| The rebuilt form widget (`Form.astro`) | **no widget, no token field, no site key** |

So this is not a "should we add captcha" question. It is: **port the contract
that already exists in the bridge into the direct path, and give the template a
widget to drive it.** Two decisions decide the shape of the work:

1. **Turnstile only.** reCAPTCHA is explicitly out of scope for P0–P5 (a
   documented opt-in seam, not a deliverable) — §3 records why.
2. **Captcha is enabled per site from the profile**, and stays *off* by
   default. Every challenge costs conversions; the reference guidance
   (`reference/email-delivery.md`) already says "add only when abuse actually
   appears". virtuallyjamaica's forms are public and unthrottled at the edge,
   so it is the first site that gets it — not an argument for turning it on
   everywhere.

Estimated shape: ~4 TS emitters + 1 template component + 1 scaffold block + 1
CSP derivation + 1 velocity endpoint + 1 parity-gate row + tests. No schema
migration, no bridge change (it already works), no sanitizer version bump.

---

## 2. Current state — what actually protects a direct form

Read `lib/forms-direct.ts` (the emitted `src/pages/api/forms/submit.ts`):

| Guard | Present | Notes |
| --- | --- | --- |
| 64 KB body cap | yes | before parsing |
| Honeypot | yes | silent `{ ok: true }` |
| Time trap | yes | `< 2 s` after load |
| Per-IP throttle | yes | 5 / 10 min, **in-memory** |
| Idempotency key | yes | SHA-256 of the visible rows |
| Reply-to header sanitising | yes | `clean()` strips CR/LF in `mail.ts` |
| **Captcha** | **no** | — |
| Edge/CDN rate rule | no | documented prerequisite, not code |

Two consequences of the in-memory throttle deserve stating, because they are
what make a captcha the *actual* control: the emitted endpoint assumes one Node
process per managed site (its own comment), so a restart clears the buckets and
a scaled-out tier shares nothing; and the throttle is per-IP while the abuse
that motivated this is usually distributed. A captcha is the only guard in the
ladder that costs the attacker something per attempt.

**What already works and must not be re-built:**

- `plugins/nvoos-forge-bridge/includes/rest/class-nvoos-forge-bridge-forms.php`
  `verify_turnstile()` — `POST https://challenges.cloudflare.com/turnstile/v0/siteverify`
  with `secret` / `response` / `remoteip`, 10 s timeout, fail-closed on
  `WP_Error` or `success !== true`, `action` must equal `nvoos_forge_form`, and
  `hostname` must equal the allowlisted origin's host. This is the contract the
  direct path copies — including the endpoint filter (`nvoos_forge_bridge_forms_turnstile_endpoint`)
  that makes it testable without the network.
- `semantic.ts` already drops hidden/submit/button inputs (`HIDDEN_INPUT_TYPES`),
  so a source form's `g-recaptcha-response` / `cf-turnstile-response` hidden
  field never reaches `FormInfo.fields`. **No `SANITIZE_VERSION` bump and no
  semantic change is needed** — the rebuilt form is template markup, not source
  HTML. (Verified: virtuallyjamaica's profile contains no captcha token at all.)

---

## 3. Decision — Turnstile first, reCAPTCHA deferred

| | Cloudflare Turnstile | Google reCAPTCHA v2 / v3 |
| --- | --- | --- |
| Bridge support today | **complete** | none — would need a second verify path in a plugin the forge does not own |
| Verification contract | `siteverify` → `success` + `action` + `hostname` | v2: `siteverify` `success`; v3: `score` (no action/hostname unless passed) |
| Cost | free | free tier, then quota |
| Third-party hosts in CSP | `challenges.cloudflare.com` | `www.google.com`, `www.gstatic.com` |
| Privacy / consent | functional security script | Google data processing; commonly treated as a consent concern |
| Failure mode | managed challenge (usually invisible) | v3 is invisible but score-only; v2 is an interaction |

**Recommendation: Turnstile is the only provider in the contract for now.**
reCAPTCHA is kept as a *seam* (`captcha.provider`), not a phase: the bridge would
need its own verify implementation first, and a score without action/hostname
pinning is exactly the "trust theater" the bridge comment warns about.

If a client contractually requires reCAPTCHA, that is a separate proposal
(bridge verify path + Google's terms + the consent question), not scope creep
inside this one.

---

## 4. Contract

### 4.1 Profile

```json
"forms": {
  "delivery": "direct",
  "mail": { "provider": "brevo", "from": "forms@mail.example.com", "to": "hello@example.com" },
  "captcha": {
    "provider": "turnstile",
    "siteKey": "0x4AAA…",
    "failOpen": false
  }
}
```

- `provider` — `'turnstile'` | `''`. Nothing else is accepted (reject at
  scaffold with a named error, the way `delivery: 'direct'` is validated today).
- `siteKey` — **public** by design (it is in the HTML Cloudflare serves). Safe
  to hold in the profile and to emit into `nvoos.json`, exactly like
  `forms.mail.from` / `to`.
- `failOpen` — default `false`. When `true`, a *verification transport* failure
  (Cloudflare unreachable/timed out) accepts the submission and logs it; a
  *verification rejection* (`success: false`, wrong action, wrong hostname)
  never does. The bridge fails closed unconditionally; this flag exists because
  a direct site has no WordPress fallback and a Cloudflare outage would silence
  the contact form entirely. Operators must opt in explicitly.
- The **secret never enters the profile or the manifest** — env
  `TURNSTILE_SECRET`, read through `runtimeEnv()` at request time, same rule as
  `BREVO_API_KEY`. A scaffold-time validator refuses `captcha.secret` in a
  profile (name it in the error) so nobody half-does it.

### 4.2 Emitted surfaces

| File | Change |
| --- | --- |
| `nvoos.json` (`manifest.forms.captcha`) | `{ provider, siteKey }` — no secret, asserted in `tests/scaffold.test.ts` the way `API_KEY` is |
| `.env.example` | `TURNSTILE_SECRET=` (+ `TURNSTILE_VERIFY_URL=` for tests/staging) |
| `src/pages/api/forms/submit.ts` (`lib/forms-direct.ts`) | verify step between the throttle and the send |
| `src/components/design/Form.astro` | widget + token in the JSON payload + `<noscript>` |
| `public/_headers` | `script-src` / `frame-src` / `connect-src` += `https://challenges.cloudflare.com` |

### 4.3 Server verify (direct endpoint)

Order, matching the bridge so both surfaces share one mental model:

```
size cap → parse → honeypot (silent ok) → time trap (silent ok) → throttle (429)
  → captcha (403) → compose → send (502)
```

- Token field: `token`, read from the JSON payload (and from
  `cf-turnstile-response` on the no-JS form-encoded floor). Reject a token
  longer than 2048 chars before the verify call (Turnstile's own bound — a
  cheap guard against a body used as a free outbound request amplifier).
- Verify `POST TURNSTILE_VERIFY_URL` (`process.env.TURNSTILE_VERIFY_URL ||
  'https://challenges.cloudflare.com/turnstile/v0/siteverify'`) with
  `secret`, `response`, `remoteip`, 10 s timeout; fail closed unless
  `captcha.failOpen`.
- Pin `action === 'nvoos_forge_form'` and `hostname === new URL(request.url).host`
  (or `forms.origin` when set) — the same two checks the bridge makes.
- Token is **single-use and ~300 s**; never cache it, never retry it.
- `token` joins the endpoint's `INTERNAL` set: never echoed into the email,
  never part of the idempotency digest (a retry cannot reuse a token anyway, and
  including it would make every retry look unique).

### 4.4 Client

- Render the widget only when `manifest.forms.captcha.siteKey` is non-empty.
- Load `https://challenges.cloudflare.com/turnstile/v0/api.js` (`async defer`)
  — the implicit-render form, one `<div class="cf-turnstile"
  data-sitekey="…" data-action="nvoos_forge_form">` inside the form.
- The existing delegated submit handler reads `cf-turnstile-response` into
  `payload.token`; on a failed verify the widget resets
  (`turnstile.reset(container)`) so the visitor can retry without a reload.
- `<noscript>` inside the form: Turnstile is JS-only, so a JS-off submission is
  rejected by the endpoint. Say so plainly ("enable JavaScript, or email us at
  …") instead of surfacing a silent 403.

### 4.5 CSP

`renderTrackingHeaders()` currently returns `undefined` when no tracker is
enabled — which is true for virtuallyjamaica (all trackers `enabled: false`),
so that site emits **no `_headers` at all**. Two changes:

1. Emit the header when **either** a tracker **or** a captcha is configured.
2. Add a `frame-src` directive. The policy deliberately has no `default-src`,
   so a frame is allowed by omission today — that is luck, not a rule. A
   captcha widget is an iframe and deserves an explicit allow.

Turnstile hosts:
`script-src https://challenges.cloudflare.com`,
`frame-src https://challenges.cloudflare.com`,
`connect-src https://challenges.cloudflare.com`.

**Turnstile is functional security, not tracking** — it must *not* be gated
behind the consent banner. The 130 gate matches scripts by the
`TRACKING_VENDORS` fingerprint table, so it will not flag
`challenges.cloudflare.com` today — but the obvious shortcut (add Turnstile as a
vendor row so `cspDirectivesFor` emits its hosts for free) would silently opt it
into consent ordering and the `tracker-in-static-html` purity rule. The hosts
must therefore come from a separate captcha parameter, not from the vendor
table.

---

## 5. Phases

| Phase | Scope | Files | Exit gate |
| --- | --- | --- | --- |
| **P0** | Contract + validation | `lib/types.ts` (`ForgeForms.captcha`, manifest shape), `lib/scaffold.ts` (reject secret / unknown provider / captcha-on-static) | unit: profile with `captcha.secret` throws; unknown provider throws |
| **P1** | Direct endpoint verify | `lib/forms-direct.ts` | unit with `TURNSTILE_VERIFY_URL` → an in-process fake: missing token 403, `success:false` 403, action mismatch 403, hostname mismatch 403, success 200, transport error closed/open by `failOpen`, token absent from the composed body + digest |
| **P2** | Template widget | `templates/blog/src/components/design/Form.astro`, scaffold manifest + `.env.example` | `astro check` + e2e: widget rendered only when configured; token in the payload; `<noscript>` present |
| **P3** | CSP | `lib/tracking.ts` (`cspDirectivesFor` takes captcha hosts), `lib/scaffold.ts` (`renderTrackingHeaders` emission condition + `frame-src`) | unit: no tracker + captcha → header emitted with 3 hosts; no tracker + no captcha → still `undefined` |
| **P4** | Velocity parity | `examples/nvoos-astro-velocity/src/pages/forms/submit.ts`, `src/lib/forms.ts`, `src/components/ContactForm.astro`, `bin/check-astro-app-parity.mjs` (`GUARD_MARKERS`) | parity script green; velocity endpoint carries the same verify |
| **P5** | Docs + rollout | `reference/email-delivery.md`, skill gotchas, `sites/virtuallyjamaica.json` (`captcha` block), operator runbook | one real submission with a token; one rejected without |

P1 lands behind a flag (`captcha` absent → byte-identical emitted endpoint) so
existing sites are untouched until P5 flips virtuallyjamaica.

---

## 6. Security invariants (do not regress)

1. **The secret is env-only**, read via `runtimeEnv()`; never in the profile,
   the manifest, or the client bundle. A test asserts `nvoos.json` contains no
   `TURNSTILE_SECRET`.
2. **`action` and `hostname` are pinned** — a success without them is not a
   pass. (Copy the bridge's comment; it is the reason the rule exists.)
3. **Fail closed** on transport error unless `failOpen: true` is explicitly set
   in the profile; a *rejection* is never fail-open.
4. **Honeypot and time trap stay silent** (`{ ok: true }`). A 403 on the
   honeypot teaches the bot which field to change.
5. **The token is never emailed, never digested, never logged.**
6. **Captcha on, JS off = rejected.** Stated in the `<noscript>`, documented in
   the reference. (The no-JS floor was never a captcha bypass, and pretending
   otherwise by accepting a form-encoded POST without a token is the bug.)
7. **Rate limiting still runs before the verify call**, so a flood cannot burn
   Cloudflare quota.
8. **The widget renders only from `manifest.forms.captcha`** — never from a
   hardcoded site key — so a site without captcha ships no third-party script.

---

## 7. Risks and trade-offs

| Risk | Mitigation |
| --- | --- |
| **Every challenge can cost conversions** — a managed challenge on a low-intent visitor is a lost enquiry | off by default; per-site opt-in; use Turnstile's invisible/"managed" widget size; measure submission rate before/after |
| **No-JS floor is lost** when captcha is on | `<noscript>` with a direct email address; documented as an explicit trade |
| **Cloudflare outage silences the form** | `failOpen` for transport errors only, operator opt-in; a Cloudflare status check in the runbook |
| **Third-party script vs the CSP/consent story** | functional-not-tracking carve-out in the 130 gate; explicit `frame-src`; Report-Only stays the default |
| **Multi-instance managed tier** | the in-memory throttle stays best-effort; the captcha is the real control; an edge/WAF rate rule is still the recommended infra step |
| **Token replay / stale token** | single-use, ~300 s TTL, reset the widget after a rejection |
| **Two verify implementations drift** | the parity gate pins the guard; the reference documents the contract once, in §4.3 |

---

## 8. Non-goals

- reCAPTCHA (or hCaptcha) verification in the bridge — separate proposal.
- Consent-gating the challenge script (it is security, not analytics).
- Replacing the in-memory throttle with a shared store — that belongs to the
  managed tier / edge, not this proposal.
- Bumping `SANITIZE_VERSION` — no new source-HTML surface is introduced.
- Captcha on the **search** form (`Form.astro` `kind: 'search'`) — a GET to the
  site's own `/search/`, no mail, no abuse surface worth a challenge.

---

## 9. Verification checklist

```sh
cd examples/nvoos-astro-forge
npm test                      # unit incl. the env-injected verify fake
npm run typecheck
npm run test:e2e              # fixture build with captcha config
node ../../bin/check-astro-app-parity.mjs   # forge ↔ velocity guard parity

# scaffolded project
node scripts/forge.mjs sites/virtuallyjamaica.json --target ../vj-captcha --preset blog --render ssr
cd ../vj-captcha && npm install && npx astro check && npx astro build
# expect: widget markup + 3 CSP hosts + TURNSTILE_SECRET in .env.example
# ONE live submission with TURNSTILE_SECRET set, ONE without (403), ONE with a
# stale token (403), ONE with the wrong action (403).
```
