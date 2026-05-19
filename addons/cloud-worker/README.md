# NV oOS Cloud — Cloudflare Worker (SaaS Backend)

> **Status:** Phase 3 reference implementation · Brand: **NV oOS Cloud** · Default host: `nvoos.cloud`

This addon is **not a WordPress plugin**. It is the SaaS-side counterpart to the
`addons/pro/` "NV oOS Cloud" plugin module. It is shipped in this monorepo for
review convenience; in production it is deployed independently as a
[Cloudflare Worker](https://developers.cloudflare.com/workers/) and never
runs inside WordPress.

```
WordPress (NV oOS Pro plugin)
        │  HTTPS, Authorization: Bearer <connect_token>
        ▼
  nvoos.cloud  ─── this addon (Cloudflare Worker)
        │  Cloudflare AI Gateway with revenue-share
        ▼
  openrouter.ai (master key, never exposed)
        │
        ▼
  OpenAI · Anthropic · Meta · Google · Mistral · …
```

## What this Worker does

| Concern | Implementation |
|---|---|
| **Inference proxy** | OpenAI-compatible `/v1/chat/completions`, `/v1/models`, `/v1/embeddings` — forwards to Cloudflare AI Gateway → OpenRouter. SSE streaming preserved with [TransformStream tee](https://developers.cloudflare.com/workers/runtime-apis/streams/). |
| **Wholesale cost capture** | Reads upstream cost from the AI Gateway's `cf-aig-cost-usd` response header (or `usage.cost` from OpenRouter when available). |
| **Markup** | `wholesale × 1.07` — the **same** 7% the plugin computes locally, so client and server agree to the cent. |
| **Ledger** | Atomic D1 transaction per request: debit wallet, append ledger row, return custom response headers `X-NV-Wholesale-Cost`, `X-NV-Service-Fee`, `X-NV-Total-Charged`. |
| **Stripe top-ups** | `/v1/account/topup` returns a Stripe Checkout URL; `/stripe/webhook` (signature-verified) credits the wallet on `checkout.session.completed`. |
| **Connect tokens** | SHA-256-hashed in D1; plaintext returned only on first issuance. Site URL is bound at issue time and verified on every request via `X-NV-Site-Url`. |
| **Token revocation** | `/v1/account/revoke` flips `connect_tokens.revoked_at`. |
| **Abuse control** | Cloudflare AI Gateway enforces per-token rate limits + cost ceilings configured via the dashboard. Worker also rejects requests on insufficient balance. |

## Layout

```
addons/cloud-worker/
├── README.md              # this file
├── package.json           # wrangler + hono + stripe (Workers-compatible)
├── tsconfig.json
├── wrangler.toml          # bindings: D1 (NVOOS_DB), KV (RATE_KV), secrets
├── schema.sql             # D1 schema — wallets, ledger, connect_tokens, topup_sessions
└── src/
    ├── index.ts           # Hono router + entry
    ├── types.ts           # shared types + Env binding
    ├── auth.ts            # connect-token middleware + site binding
    ├── inference.ts       # OpenAI-compatible passthrough + SSE
    ├── billing.ts         # markup math + wallet debit + ledger write
    ├── account.ts         # balance / topup / revoke endpoints
    ├── stripe.ts          # Checkout + webhook signature verification
    ├── connect.ts         # /connect — issues Connect Tokens after first top-up
    └── utils.ts           # JSON, error, SSE helpers
```

## Local development

```bash
cd addons/cloud-worker
npm install

# Initialise a local D1 database with the schema
npx wrangler d1 create nvoos-cloud-dev
npx wrangler d1 execute nvoos-cloud-dev --file=./schema.sql --local

# Set local secrets (use real values for staging)
echo "OPENROUTER_API_KEY=sk-or-..." > .dev.vars
echo "STRIPE_SECRET_KEY=sk_test_..." >> .dev.vars
echo "STRIPE_WEBHOOK_SECRET=whsec_..." >> .dev.vars
echo "CF_AI_GATEWAY_URL=https://gateway.ai.cloudflare.com/v1/<ACCOUNT_ID>/<GATEWAY_ID>/openrouter" >> .dev.vars

# Run the dev server (Miniflare)
npm run dev

# Run tests
npm test
npm run typecheck
```

## Production deploy

```bash
# 1. Create the production D1 database
npx wrangler d1 create nvoos-cloud-prod
# → copy the resulting database_id into wrangler.toml under [[d1_databases]]

# 2. Run the schema
npx wrangler d1 execute nvoos-cloud-prod --file=./schema.sql --remote

# 3. Set production secrets
npx wrangler secret put OPENROUTER_API_KEY
npx wrangler secret put STRIPE_SECRET_KEY
npx wrangler secret put STRIPE_WEBHOOK_SECRET
npx wrangler secret put CF_AI_GATEWAY_URL

# 4. Deploy
npm run deploy

# 5. Bind the custom domain `nvoos.cloud` in the Cloudflare dashboard
# 6. Configure the Stripe webhook endpoint to https://nvoos.cloud/stripe/webhook
```

## Plugin contract

The plugin (`addons/pro/includes/services/class-wp-mcp-ai-nv-cloud-service.php`)
expects the endpoints documented in [`docs/features/nv-cloud.md`](../../docs/features/nv-cloud.md).
Any breaking change to this Worker MUST be coordinated with a plugin release —
see the version skew check in `index.ts` (`X-NV-Worker-Version` response header).

## Security

- **Master key isolation.** The OpenRouter API key is a Wrangler secret; it
  never appears in client requests, in logs, or in D1.
- **Site binding.** `connect_tokens.site_url` is verified against the
  `X-NV-Site-Url` request header on every inference request. Mismatches return
  HTTP 403.
- **Stripe webhook signing.** The webhook handler verifies the `Stripe-Signature`
  header against `STRIPE_WEBHOOK_SECRET` using a constant-time HMAC-SHA-256
  comparison. Unsigned or malformed events are rejected.
- **Idempotency.** Webhook handlers use Stripe's `event.id` as a primary-key
  check against `topup_sessions.event_id` to prevent double-credit on retry.
- **No PII in ledger.** The ledger stores prompt/completion **token counts**,
  never message bodies.

## Pricing math (must match plugin)

```
wholesale_usd  = upstream_cost (from AI Gateway / OpenRouter)
service_fee    = round(wholesale_usd * 0.07, 6)        # MARKUP_RATE
total_charged  = wholesale_usd + service_fee
```

Stripe processor fees (`2.9% + $0.30`) are **only** applied at top-up, never
per request, and are surfaced as a transparent line item in the Checkout
session description.

## Credits

This Worker uses:

- [Hono](https://hono.dev/) (MIT) — HTTP router
- [Stripe Node SDK](https://github.com/stripe/stripe-node) (MIT) — payments
- [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/) — inference proxy
- [OpenRouter](https://openrouter.ai/) — multi-provider model router

See the root [`CREDITS.md`](../../CREDITS.md) for the canonical attribution
index. Any new dependency added here must also be added there and to
[`docs/THIRD_PARTY_ASSETS.md`](../../docs/THIRD_PARTY_ASSETS.md).

## License

Proprietary — © 2025-2026 NV Digital Solutions, all rights reserved. This
Cloud Worker is the SaaS-side backend for the NV oOS Pro addon and is **not
distributed to end users**; it is shipped in this monorepo for review and
reference only. Bundled third-party dependencies retain their upstream MIT /
Apache-2.0 licenses (see `## Credits` above and the repository-wide
[`CREDITS.md`](../../CREDITS.md)).
