# Proposal 138 — Form email delivery across both Astro products

> Status: **Shipped 2026-11-09** on `feat/forge-email-delivery`. P1–P5 below
> are implemented and green: forge **772 unit tests**, velocity **443 unit
> tests**, `npm run typecheck` (forge), `npm run check` (velocity: 235 files,
> 0 errors), `bin/check-astro-app-parity.mjs` clean (including the new
> byte-identity gate), and a scaffolded managed-runtime project passing
> `astro check` (0 errors) + `astro build`.
> Extends Proposal 096 (Astro Forge), 104 (security headers / CSP), 135
> (managed deploy tier), 136 (agent surfaces).
> Touches 092 (velocity storefront), 107 (storefront content surface).
>
> Companion: `138-form-email-delivery-implementation-plan.md`.
>
> Origin: issue **#7549** — the virtuallyjamaica.com rebuild dropped its
> Contact Form 7 block, because a forged site had no way to send a submission
> without a Forge Bridge. Investigating that gap surfaced two larger ones: the
> storefront had no form surface at all, and both apps read secrets through
> `import.meta.env`, which is a **build-time** snapshot.

---

## 1. Executive summary

A form rebuild is only a rebuild if the form still delivers. Until now the
forge could emit the markup and POST it at the Forge Bridge, and nothing else:
a site without a reachable bridge silently lost every enquiry. The storefront
had no public form at all.

This proposal closes both, and does it without inventing a new integration
surface:

1. **`bridge` stays the default.** WordPress already owns the handler *and*
   its mailer; SMTP configuration lives in WP Mail SMTP, and no credential
   ever reaches the generated site.
2. **`direct` is the bridge-less path.** A provider HTTP API (Brevo, Mailjet)
   called from the app's own runtime, with credentials read from the
   environment at request time.
3. **Velocity gets the same transport.** `/forms/submit` plus a native-first
   contact form, sharing `mail.ts` byte-identically with the forge.
4. **A drift gate.** `bin/check-astro-app-parity.mjs` fails if the two
   copies of `mail.ts` diverge, if the velocity endpoint stops reading the
   runtime environment, or if either endpoint loses a guard the other keeps.

The provider API is chosen over raw SMTP deliberately; §4 records the
reasoning. Deliverability is treated as a domain problem (§5), not something
code can paper over.

## 2. Current state

| Surface | Before | After |
| --- | --- | --- |
| Forge, bridge mode | form → `/api/forms/submit` → WP bridge → WP mailer | unchanged (default) |
| Forge, no bridge | **submission lost** | `forms.delivery: 'direct'` → provider API |
| Velocity | no form surface at all | `/contact/` + `/forms/submit` → provider API |
| Shared transport | — | `mail.ts`, byte-identical in both apps |
| Drift detection | llms.txt format + section kinds only | + `mail.ts` byte identity, endpoint env check, and guard-presence parity |

## 3. The two modes

Profile:

```json
"forms": {
  "delivery": "bridge",
  "mail": { "provider": "brevo", "from": "forms@mail.example.com", "to": "hello@example.com" }
}
```

| | `bridge` (default) | `direct` |
| --- | --- | --- |
| Handler | Forge Bridge on the source WP | the app's own route |
| Mailer | WP Mail SMTP / provider plugin | Brevo or Mailjet HTTP API |
| Credentials on the site | none | env only (`BREVO_API_KEY` / `MAILJET_*`) |
| Requires | the bridge, reachable | `deploy.runtime: managed`, plus `forms.mail` |
| Best for | any site still on WordPress with a working mailer | bridge-less or WP-free deployments |

`direct` **throws at scaffold time** unless both conditions hold. A static
build has nowhere to keep a key, and shipping one in client JS is a leak, so
the failure has to be loud and early rather than a site that silently drops
submissions.

## 4. Why the provider HTTP API and not raw SMTP

Raw SMTP means holding a socket for the length of the send, negotiating TLS,
reconnecting on failure, and storing a mailbox password. For a custom app the
documented 2026 guidance is the provider's JSON API: scoped keys, per-request
idempotency, and a synchronous response we can log without a socket lifecycle
in a serverless/edge runtime.

SMTP is the right choice when the application **already has an SMTP setting** —
which is exactly the WordPress case, and exactly why the bridge path keeps it
there. The two modes are not competing implementations of the same thing; each
uses the transport its host already owns.

## 5. Deliverability is a domain concern

No API call can rescue an unauthenticated sending domain. The requirement is
SPF + DKIM + DMARC, aligned, with DMARC moving to enforcement once alignment
is verified; bulk senders additionally need RFC 8058 one-click unsubscribe and
a complaint rate under 0.3 % (form mail is low-volume, so the bulk thresholds
do not bite — the authentication still decides inbox vs spam).

Two rules follow for `MAIL_FROM`:

- it must be an address on the **provider-authenticated** domain
  (`forms@mail.example.com`), never a mailbox that only exists on the site's
  own MX;
- the domain must be verified at the provider, which is what publishes its
  DKIM keys.

Verification is a test send plus a look at `Authentication-Results`. This is
documentation, not code — see `reference/email-delivery.md`.

## 6. Abuse layers

Applied in this order, cheapest first, in both apps:

1. **Body cap** — 64 KB, refused before parsing. The `content-length` header is
   checked first and the body is then measured, because the header is advisory
   and absent on a chunked body.
2. **Honeypot** — a filled field returns success. Never a rejection: a
   rejection teaches the bot which field to change.
3. **Time trap** — a submit under 2 s after render is silently dropped. A
   *missing* timestamp is **not** treated as a bot; some clients drop hidden
   fields, and a false positive discards a real enquiry.
4. **Per-IP throttle** — 5 per 10 minutes, in-memory. One managed site runs one
   Node process, so this is the app layer; a CDN/WAF rate rule is the real edge.
5. **Optional Turnstile** — only when abuse actually appears; every challenge
   costs conversions.

Accepted submissions carry a SHA-256 idempotency key over the normalised
fields, forwarded as `headers.idempotencyKey` on Brevo, so a double-click or a
retried request cannot deliver twice.

## 7. The Velocity port

Three constraints shaped it:

**The endpoint cannot live under `/api/*`.** `scripts/serve.mjs` proxies that
entire prefix to the commerce contract, so an Astro route there is never
reached. `/forms/submit` it is — the same reason `/internal/revalidate` sits
outside the prefix.

**Native first.** The form is a real `<form method="post">`, so it works with
JavaScript disabled; the bundled `<script>` only upgrades the experience
(inline status, no navigation). A no-JS post gets a `303` back to
`/contact/?sent=1`; a JSON caller gets `{ ok: true }`. The no-JS path depends on
Astro's `security.checkOrigin` (on by default for server output) rejecting a
cross-site post.

**CSP-safe by construction.** `scripts/lib/security-headers.mjs` hashes the
inline scripts of each document and keeps that set page-invariant (the tabs
outage of 2026-11-09). A bundled component script is an external chunk, covered
by `'self'`, so the new page adds no inline hash and cannot break the invariant
guarded by `tests/unit/csp-inline-invariance.test.ts`. The page is also
`no-store`: it carries a per-render timestamp for the time trap, and a cached
copy would look "too fast to be human" and be dropped.

## 8. Finding: `import.meta.env` is a build-time snapshot

Both apps originally read provider secrets through `import.meta.env`. Vite
inlines what it can see at build time, so a key injected into the running
process **after** the build — the managed tier's normal delivery mechanism —
is invisible to it. The endpoint would have returned 500 "BREVO_API_KEY must be
set" in production while working in dev.

`mail.ts` now exposes `runtimeEnv()`: `process.env` first, with the build-time
object as a fallback so `astro dev` with a `.env` file still works; runtime wins
on conflict. Both the forge-emitted endpoint and the velocity endpoint call it,
and parity asserts the velocity endpoint keeps doing so.

`runtimeEnv()` reads `import.meta` **structurally** rather than via the Vite
types, because the forge's own `tsconfig` does not include them.

## 9. Drift control

`mail.ts` is a **copy**, not a shared package: a generated site must build
standalone, and a published storefront must not depend on the forge tree. Copies
drift, so `bin/check-astro-app-parity.mjs` now asserts byte identity and fails
with "copy one over the other".

What is **not** gated, and is declared instead: the endpoint around the module.
The forge's is emitted as source (`lib/forms-direct.ts`, because it has to land
in a generated tree); velocity's is hand-written (`src/pages/forms/submit.ts`).
Same guards, different frame, so byte identity would be wrong — but guard
presence IS gated: section 4 of the parity script asserts each marker
(honeypot, time trap, body cap, throttle, idempotency, reply-to) exists on
**both** sides. Dropping a guard from one product therefore fails CI, which is
the "we fixed the spam problem" drift that motivated the gate.

## 10. Decisions

| Decision | Why |
| --- | --- |
| `bridge` remains the default | no new credential surface for sites that already deliver mail |
| `direct` fails at scaffold time | a static build cannot hold a key; silent loss is worse than a build error |
| Provider API, not SMTP, for `direct` | no socket lifecycle; scoped keys; idempotency |
| Secrets are env-only | never in the profile, the manifest, or client JS; a test asserts the manifest never contains `API_KEY` |
| `/forms/submit`, not `/api/forms/submit`, in velocity | `/api/*` is the commerce proxy |
| Native form, not an island | works without JS; no inline script; no conversion loss |
| `runtimeEnv()` in `mail.ts` | one correct implementation rather than two buggy ones |

## 11. Risks

- **Provider outage or key rotation** → 502 plus a `console.error`; nothing is
  queued. Acceptable for an enquiry form; a retry queue is a future item.
- **In-memory throttle** is per-process and resets on deploy. It is a speed
  bump, not a control plane; the WAF is the control plane.
- **Two endpoints to maintain.** Mitigated by sharing `mail.ts` and by the
  parity gate, but the guard *sequence* is duplicated and could drift. If a
  third consumer appears, extract `forms-guard.ts` and vendor it the way
  `mail.ts` is vendored.
- **DMARC misconfiguration** produces perfect-looking code that lands in spam.
  Mitigated only by documentation and a test send.

## 12. Open items

- **#7549** — the virtuallyjamaica contact form: install the bridge at the
  source, or set `delivery: 'direct'`, then add a `forms` block and rebuild.
- **#7550** — `/blog/portfolio/` still needs `show_in_rest` on the `portfolio`
  CPT at the source.
- **Velocity `/contact/` is a route, not a content block.** If marketing wants
  the form embeddable in Portable Text, that is a `contact` section kind on
  both sides — a parity-visible decision (§9 of Proposal 136).
- **Optional Turnstile wiring** is documented, not implemented.
