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
