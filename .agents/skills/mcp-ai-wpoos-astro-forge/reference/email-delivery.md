# Form email delivery — bridge vs direct

2026-11-09. Researched against the current sender rules before writing any code;
deliverability is a **domain** problem, so the code only owns the transport.

## Two modes

Profile:

```json
"forms": {
  "delivery": "bridge",                       // default
  "mail": { "provider": "brevo", "from": "forms@mail.example.com", "to": "hello@example.com" }
}
```

| Mode | Path | Credentials | Requires |
| --- | --- | --- | --- |
| `bridge` (default) | form → `/api/forms/submit` → WordPress bridge → WP mailer | none on the site | the Forge Bridge, plus an SMTP plugin on WP |
| `direct` | form → `/api/forms/submit` → provider HTTP API | env only (`BREVO_API_KEY` / `MAILJET_*`) | `deploy.runtime: managed` |

`direct` on a static build **throws at scaffold time**: a static site has
nowhere to keep a key, and shipping one in client JS is a leak.

## Why API and not SMTP for `direct`

WordPress already has an SMTP setting, so SMTP belongs on the **bridge** path
(WP Mail SMTP / the provider's plugin). For a custom app the documented choice
is the provider's JSON API: TLS, scoped keys, per-request idempotency, and no
socket lifecycle in a serverless runtime. Implemented: Brevo
(`/v3/smtp/email`, `api-key` header) and Mailjet (`/v3.1/send`, HTTP Basic).

## The part that actually decides inbox placement

Google/Microsoft/Yahoo sender rules (2026): **SPF + DKIM + DMARC** for the
sending domain, aligned, with DMARC enforcement; bulk senders add one-click
unsubscribe (RFC 8058) and must keep complaints under 0.3 %. Form mail is
low-volume, so the enforcement thresholds do not bite — but the
authentication still decides inbox vs spam.

- `MAIL_FROM` must be an address on the **provider-authenticated** domain
  (`forms@mail.example.com`), not a site-only mailbox.
- Verify the domain at the provider (it publishes the DKIM keys).
- Publish SPF for that domain, and align DMARC.
- Check the emitted headers on a test send (`Authentication-Results`).

A perfect API call from an unauthenticated domain is still spam.

## Abuse layers (in the generated endpoint, in this order)

1. **Body-size cap** (64 KB) before parsing.
2. **Honeypot** — a filled field returns `{ ok: true }`. Never a rejection: a
   rejection teaches the bot what to change.
3. **Time trap** — a submit under 2 s after page load is not a human; silently
   accepted and dropped.
4. **Per-IP throttle** — 5 per 10 minutes, in-memory. One managed site runs one
   Node process, so this is the app layer; a CDN/WAF rate rule is the real edge.
5. **Optional Turnstile** — add only when abuse actually appears; every
   challenge costs conversions.

Plus **idempotency**: a SHA-256 of the normalised fields is sent as the
provider idempotency key, so a double-click or a retried request is one email.

## Secrets

Never in the profile, never in `nvoos.json` (a test asserts the manifest never
contains `API_KEY`). The endpoint reads `import.meta.env` at request time, so a
scaffold never needs the key.

## Gotcha — escapes in an emitter string

Writing generated code from a JS array is escape-sensitive: `"\trows.join( '\n' )"`
in a **double-quoted** emitter string becomes a real newline and breaks the
output (46 typecheck errors, all pointing at one line). Emit `NL` via
`String.fromCharCode( 10 )` instead of embedding `\n`, and always run
`astro check` on a scaffolded project — `astro build` alone will not catch it.

## Verify a run

```sh
node scripts/forge.mjs sites/<site>.json --target ../<site>-frontend
cd ../<site>-frontend && npm install && npm run check   # generated code typechecks
# then set MAIL_* + the provider key in the host env and POST one test message
```

## Both products (Proposal 138)

The same transport ships in both Astro products:

| | forge | velocity |
| --- | --- | --- |
| Transport | `examples/nvoos-astro-forge/lib/mail.ts`, vendored to `src/forge/mail.ts` | `examples/nvoos-astro-velocity/src/lib/mail.ts` |
| Endpoint | emitted `src/pages/api/forms/submit.ts` (`bridge` default, `direct` opt-in) | `src/pages/forms/submit.ts` (always direct) |
| Form | `templates/blog/src/components/design/Form.astro` | `src/components/ContactForm.astro` + `src/pages/contact.astro` |
| Credentials | env only | env only |

`mail.ts` is BYTE-IDENTICAL in both trees, and `bin/check-astro-app-parity.mjs`
fails when it diverges — edit one, copy it to the other. The endpoints around it
are NOT shared: the forge's is generated source (it must land in a generated
tree), velocity's is hand-written. Same guards, different frame — byte identity
would be wrong, but guard PRESENCE is gated, so dropping a guard from one
product fails `bin/check-astro-app-parity.mjs`.

### Velocity specifics

- `/forms/submit`, never `/api/forms/submit`: `scripts/serve.mjs` proxies the
  whole `/api/*` prefix to the commerce contract, so an Astro route there is
  unreachable. `/internal/revalidate` sits outside it for the same reason.
- Native-first form: works with JavaScript off (303 back to `/contact/?sent=1`);
  the bundled script only upgrades it. Use a `hidden` honeypot, not
  `aria-hidden` — the latter around a focusable input trips the axe gate.
- The page is `no-store`: it embeds a render timestamp for the time trap, and a
  cached copy looks "too fast to be human" and gets dropped.
- No island needed. A component `<script>` is hoisted to an external chunk
  (`assetsInlineLimit: 0`), so it is covered by `'self'` and never joins the
  per-document CSP hash set.
- The contract gate (`tests/unit/contract-grep.test.ts`) scans COMMENTS too —
  do not name the backend path when describing the proxy.

## Runtime env, not build-time env

`import.meta.env` is a BUILD-time snapshot. A key injected into the running
process after the build (the managed tier's normal delivery mechanism) is
invisible to it, so the endpoint 500s in production while working in dev.

`readMailConfig( runtimeEnv() )` is the required call. `runtimeEnv()` reads
`process.env` first and falls back to the build-time object, so `astro dev` with
a `.env` file still works; runtime wins. It reads `import.meta` structurally
because the forge's tsconfig carries no Vite types. Parity asserts the velocity
endpoint keeps using it.

## Verifying an emitted endpoint

`astro build` does NOT catch a malformed emitted module. Scaffold and run
`astro check`:

```
node scripts/forge.mjs forge <profile.json> --target <dir> --preset blog --render ssr
cd <dir> && npm install && npx astro check && npx astro build
```

A `direct` profile needs `deploy.runtime: managed` AND a `forms.mail` block, or
the scaffold refuses. Secrets are never in the profile or the manifest.

## Verifying a **delivery**, not a response (2026-11-16)

The endpoint answers `{ ok: true }` for **both** a delivered enquiry and a
silent drop (honeypot, time trap, harvested payload, missing `page`), and
`Form.astro` prints "Thank you — your message was sent." for either. The form's
own status is therefore never evidence of delivery. Check the ESP.

Brevo: the list endpoint **requires** a filter — a bare `?limit=` answers
`400 missing_parameter` (`Please pass atleast one of the following filters:
email, messageId, templateId`). Filter on the recipient, then read the detail
for the body and the event trail:

```sh
GET /v3/smtp/emails?email=<to>&limit=20&sort=desc   # summary + uuid
GET /v3/smtp/emails/<uuid>                          # events (sent/delivered/open) + body
```

Two traps, both measured on virtuallyjamaica:

- **Index lag.** A just-sent message can be absent for tens of seconds; a query
  run immediately after the send reads as "dropped". The 12:04:56 UTC test
  delivery was missing from the first query and appeared a minute later — one
  query away from a false "the gate broke the form" conclusion. Re-query before
  believing a negative, or compare against the deploy time instead.
- **Mixed offsets.** The list returns the account timezone (`+05:30` here) and
  the detail returns `+01:00` for the same instant. Convert both to UTC before
  lining them up with a deployment timestamp.

## Provider IP allowlists (2026-11-15)

A key can be scoped to an IP allowlist, and then **the app's egress IP** must be
on it — not the operator's. Symptom: every submit returns 502 while a manual
`curl` with the same key succeeds from a laptop that was allowlisted by mistake.

- Brevo answers `401 unauthorized` — "We have detected you are using an
  unrecognised IP address <ip>" for any non-allowlisted caller. Add the
  **Cloudways server's public IP** (`server_get.public_ip`), not the client's.
- Diagnose without burning the throttle: a honeypot POST returns in ~100 ms and
  a real submit spends ~670 ms outbound before failing. That delta proves the
  credentials reached the process and the provider is refusing.
- The endpoint only reports `Delivery failed` (502); the provider detail goes to
  PM2's log, which the Cloudways API does not expose. Prefer the timing check.
- A valid key + a valid sender can still 502: verify the sender once with a
  direct provider call before blaming the code.

## Secret hygiene on the managed tier

`velocity_env_vars_upsert` defaults `is_private: false`, and `env_vars_list`
then **returns the value in plaintext**. Mark keys private and rotate anything
that was readable. The MCP token may not carry the write scope
(`403 insufficient_scope`) — plan for the console.

## The throttle is not per-IP by default

An endpoint that keys its throttle on `clientAddress` is throttling the
**proxy**, i.e. the whole site (5 submissions / 10 min for every visitor).
Behind one reverse proxy the rightmost `X-Forwarded-For` hop is the only value
a client cannot forge (a client-supplied header is prepended, never replaced).

## Turnstile on a DIRECT endpoint (2026-11-15)

`forms.turnstile` renders the widget; `renderDirectFormsEndpointTs()` emits the
server half. The widget is chrome — **`siteverify` is the boundary**, because a
script can POST the endpoint directly and never render a challenge.

```json
"forms": {
  "delivery": "direct",
  "turnstile": { "siteKey": "<public>", "action": "contact", "onProviderError": "allow" }
}
```

What the emitted verifier guarantees:

- `POST https://challenges.cloudflare.com/turnstile/v0/siteverify` with
  `secret`, `response`, and `remoteip` when the client address is a literal.
- Validate `success` **and** `hostname` **and** `action`. The hostname comes
  from the profile (`deploy.publicOrigin`), so it is deterministic; the request
  host is only the fallback, because behind nginx it can be `127.0.0.1:4321`
  and would reject every real submission.
- Tokens are **single-use and expire after 300 s**; a replay answers
  `timeout-or-duplicate`. That is a **rejection** (400), never a transport
  error — do not retry it as one.
- **Order: verify → throttle → send.** A token is free to mint, so letting a
  rejected one consume a real address's slots would let a single bot lock the
  form for everyone behind the same NAT.
- A **missing** token is rejected (400) by default. The widget renders for
  every real visitor, so a tokenless POST is a script hitting the route
  directly — the spam class that hit virtuallyjamaica on 2026-11-16, where a
  bot posted the old CF7 field list (`your-name`, `your-email`,
  `your-message`, `submit`, an empty `g-recaptcha-response`) and the
  accept-unverified branch delivered every one. `requireToken: false` restores
  the old behaviour for a site that must keep `Form.astro`'s no-JS POST floor;
  a broken widget is then a CSP/script fault rather than a bot.
- Harvested-payload noise (`submit`, `g-recaptcha-response`,
  `h-captcha-response`, `_wpcf7*`) never reaches the body — `NOISE_FIELDS` in
  `lib/forms.ts` drops it, so a bot's scraper field list cannot pad the email.
- `onProviderError: "allow"` keeps the enquiry when siteverify itself is
  unreachable (timeout / 5xx / DNS); `"block"` refuses it with a 503 so the
  visitor is not blamed for our outage.

CSP: the widget needs `https://challenges.cloudflare.com` in `script-src`,
`frame-src` **and** `connect-src`. `renderTrackingHeaders()` adds it whenever
`forms.turnstile.siteKey` is set, and only then emits `frame-src` at all — an
absent `frame-src` leaves frames unrestricted, and narrowing it for every site
would break embeds the forge cannot enumerate. The header is written even on a
site with no enabled tracker, because the first enforcing policy would
otherwise silently kill the form.

Secrets: `TURNSTILE_SECRET` lives in the host env, never in the profile or the
manifest. A missing secret skips verification loudly (a half-configured deploy
still takes enquiries). Cloudflare's documented **test** pair always passes:
site key `1x00000000000000000000AA`, secret
`1x0000000000000000000000000000000AA`.

For a **live** site the PUBLIC site key goes in
`forms.turnstile.siteKey` (committed — it is rendered into the page), and the
paired secret goes in the host's runtime environment as `TURNSTILE_SECRET`
(Velocity app env / server `.env` — never the profile, manifest or repo). The
Cloudflare widget's allowed hostnames must include `deploy.publicOrigin`. A
site key/secret mismatch is a `siteverify` rejection at submit time, not a
build failure — test a real submission after any rotation.

### A minted token is not a human (2026-11-16)

Requiring a token closes tokenless POSTs, and a site can still receive spam: a
solver service or a headless browser mints a **real** token for the site key,
then posts a flat harvested field list at the route. Measured on
virtuallyjamaica — 11:24 UTC, after the token requirement shipped:

```
your-name: kr
your-email: …@zapovedniki.com
```

`siteverify` can say "this challenge was solved for this site key". It cannot
say "a person filled this form". Two provenance gates therefore run after
verification and before the throttle, emitted only when `requireToken` is on:

- **`harvestedField( fields )`** — drops a payload carrying any `NOISE_FIELDS`
  name (`submit`, `g-recaptcha-response`, `h-captcha-response`, `_wpcf7*`).
  This form never emits them, so their presence means the field list was
  scraped from a different form definition.
- **`carriesPage( fields, siteHost )`** — requires `page` on the expected
  hostname. `page` is what the form's own script always sends
  (`page: window.location.href`); a replayed flat payload does not carry it.

Both answer `{ ok: true }` and send nothing — the same silent-drop policy as
the honeypot. They are not emitted for a profile with `requireToken: false`:
a no-JS POST floor has no `page` carrier to send.

Neither gate is proof of humanity, and a sender that forges `page` **and**
mints a token still passes. Treat them as the app-side floor; the durable
control for a distributed run is a rate rule at the edge
(`nvoos-forge-deploy` → Cloudflare rate limiting).

### The payload-envelope trap

`Form.astro` posts the **bridge** envelope — `{ form, fields: { name, email, …
}, honeypot, timestamp, page }` — in both delivery modes. A direct endpoint
that reads only top-level scalars silently delivers an email with no visitor
values (and no Turnstile token). `readSubmissionFields()` in `lib/forms.ts`
therefore flattens the nested `fields` bag first, then lets the top-level
carriers win; any new reserved field name has to be filtered **after** that
flatten.

That module is shared with the bridge fallback below, so the two delivery
paths cannot drift on the message: `submissionMessage()` owns the reserved
fields, Reply-To and the idempotency digest for both.

## Bridge outages: proxy failures and `forms.fallback` (2026-11-15)

A **bridged** site has three runtime dependencies on the CMS — forms, media and
the commerce API. All three go through the emitted `proxyToWordPress()`
(`lib/managed-runtime.ts`), so the gateway semantics live there:

| Upstream outcome | Emitted answer | Body |
|---|---|---|
| no `NVOOS_TARGET_SITE` | 503 | plain text |
| refused / DNS / TLS | 502 | `{ ok: false, code: "upstream_unreachable", … }` |
| no answer within the budget | 504 | `{ ok: false, code: "upstream_timeout", … }` |
| any upstream status | passed through unchanged | upstream body |

RFC 9110: 504 is no timely answer (§15.6.5), 502 is no valid answer at all
(§15.6.3). The budget is `UPSTREAM_TIMEOUT_MS`, 10 s by default, overridable
with `NVOOS_PROXY_TIMEOUT_MS` inside a 1 s – 60 s band. Without it a hung CMS
holds the worker until the platform kills it, and a refused connection surfaces
as an unhandled 500.

`forms.fallback: "direct"` (bridge mode only; requires `forms.mail` and the
managed runtime) re-delivers the SAME payload through the provider when the
bridge cannot take it:

| Bridge result | Fallback? |
|---|---|
| 2xx / 3xx | no — the bridge took it |
| 4xx (validation, throttle) | **no** — the answer must reach the visitor |
| 501 (bridge up, no handler) | **no** — a config fault worth surfacing |
| other 5xx / 502 / 504 / transport | **yes** |

Rules that keep it honest:

- **One email.** The provider key is the SHA-256 digest of the filtered payload
  (`submissionMessage()`), so a bridge→fallback handoff, a double-click and a
  retried request all collapse into one send. That is the `Idempotency-Key`
  contract (draft-ietf-httpapi-idempotency-key-header): same payload → same
  key, different payload → different key.
- **A breaker.** Three consecutive bridge failures open it for 60 s; a
  submission inside that window skips the bridge and goes straight to the
  provider, so an outage costs one timeout rather than one per visitor.
  Success closes it — including the bridge's own 4xx.
- **Never silent.** Every fallback logs `[forms] direct fallback (<reason>)`,
  and a failed fallback is a 502 the visitor sees, not a swallowed enquiry.
- The no-JS floor gets JSON instead of the bridge `?form=ok` redirect: that
  redirect is allowlist-validated on the WordPress side, and re-implementing it
  here would duplicate the open-redirect guard.

Media is a separate dial: `media.mode: "local"` mirrors uploads into
`dist/media`, so images do not depend on the CMS at request time.
