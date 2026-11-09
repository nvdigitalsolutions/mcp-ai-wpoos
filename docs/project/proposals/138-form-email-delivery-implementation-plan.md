# Proposal 138 — Implementation plan (form email delivery)

Companion to `138-form-email-delivery-proposal.md`.
Status: **complete** (2026-11-09), all phases green.

---

## 1. Scope

The provider transport and the bridge/direct switch (proposal §3–§6), the
Velocity port (§7), the runtime-env correction (§8) and the drift gate (§9).

In: `examples/nvoos-astro-forge/**`, `examples/nvoos-astro-velocity/**`,
`bin/check-astro-app-parity.mjs`, the astro-forge skill reference.
Out: the bridge plugin (it keeps its own SMTP), Turnstile wiring, any retry
queue.

### 1.1 Ordering

```
P1 mail.ts ──▶ P2 forge direct endpoint ──▶ P4 parity gate
                     │                          │
                     └────────┬─────────────────┘
                              ▼
                        P3 velocity port ──▶ P5 docs/skills
```

P3 depends on P1 (byte-identical copy) and on P2's guard sequence.

## 2. Phases

### P1 — provider transport (`lib/mail.ts`) — **done**
Brevo (`POST /v3/smtp/email`, `api-key`) and Mailjet (`POST /v3.1/send`,
HTTP Basic). Pure module: builds a request, never holds a client. `sendMail`
returns a status and never throws, so the endpoint can log and map it.
`runtimeEnv()` added in the §8 correction.

### P2 — forge direct endpoint (`lib/forms-direct.ts`) — **done**
The emitter renders `src/pages/api/forms/submit.ts` into the generated tree and
vendors `mail.ts`. Scaffold validation throws when
`forms.delivery: 'direct'` is set without `deploy.runtime: 'managed'` or
`forms.mail`. Manifest records `delivery` + `mail`; a test asserts no `API_KEY`
value ever reaches it.

### P3 — velocity port — **done**
- `src/lib/mail.ts` — the P1 module, byte-identical.
- `src/lib/forms.ts` — the pure guards (read, bot, throttle, rows, key, text).
- `src/pages/forms/submit.ts` — the route, outside `/api/*`.
- `src/components/ContactForm.astro` — native form + bundled enhancement.
- `src/pages/contact.astro` — the page, `no-store`.
- `tests/unit/forms.test.ts` — 20 tests.

### P4 — drift gate — **done**
`bin/check-astro-app-parity.mjs` gains §3 (byte identity for `mail.ts`, plus an
assertion that the velocity endpoint still calls `runtimeEnv()`) and §4
(guard-presence parity across the two endpoints).

### P5 — docs and skills — **done**
This pair, the velocity README section, `.env.example`, and
`.agents/skills/mcp-ai-wpoos-astro-forge/reference/email-delivery.md`.

## 3. Validation matrix

| Gate | Command | Result |
| --- | --- | --- |
| forge types | `npm run typecheck` (forge) | clean |
| forge unit | `npm test` (forge) | 772 / 52 files |
| velocity lint | `npm run lint` | clean |
| velocity unit | `npm test` | 443 / 47 files |
| velocity types | `npm run check` | 235 files, 0 errors |
| cross-app parity | `node bin/check-astro-app-parity.mjs` | clean |
| emitted endpoint | scaffold → `npm install` → `astro check` | 0 errors, 0 warnings |
| emitted build | `astro build` | complete |

`astro check` on the scaffolded project is mandatory: `astro build` does not
catch a malformed emitted module (the `\n`-escape incident, where 46 typecheck
errors hid behind a successful build).

## 4. Traps hit, and what they teach

1. **`import.meta.env` is build-time.** Runtime secrets need `process.env`.
   `runtimeEnv()` merges both, runtime wins.
2. **`/api/*` is proxied.** Any velocity route that must reach Astro lives
   outside it. `/internal/revalidate` already documents this.
3. **The contract gate scans comments.** `wp-json` in a comment fails
   `tests/unit/contract-grep.test.ts`; describe the proxy without naming the
   backend path.
4. **`.astro` component `<script>` is external.** Keep it that way
   (`assetsInlineLimit: 0`); an inline script would join the per-document CSP
   hash set and could break the page-invariant rule.
5. **`hidden` beats `aria-hidden` for a honeypot.** `aria-hidden` around a
   focusable input trips the axe gate; `hidden` removes it from both the tab
   order and the accessibility tree.
6. **A cached contact page breaks the time trap.** `no-store`, because the
   page embeds a render timestamp.

## 5. Follow-ups

- #7549 — rebuild the virtuallyjamaica contact form with `direct`.
- #7550 — `show_in_rest` on the `portfolio` CPT at the source.
- If a third consumer appears: extract `forms-guard.ts` and vendor it.
- Turnstile: wire only after real abuse.
