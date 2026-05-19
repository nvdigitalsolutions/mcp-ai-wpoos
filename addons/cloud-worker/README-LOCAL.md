# NV oOS Cloud Worker — Local Development Quick Start

This guide covers running the Cloud Worker locally with Miniflare and wiring it
to a local WordPress instance.

## Architecture (local)

```
http://localhost:8000 (WordPress + NV oOS Pro plugin)
        │  POST /mcp-ai-pro/v1/cloud/connect { token: "nvc_..." }
        │  GET  /mcp-ai-pro/v1/cloud/refresh-balance
        ▼
http://localhost:8787 (Cloud Worker — Miniflare)
        │  D1 (local SQLite)
        │  KV (local)
        ▼
  nvoos.cloud (production only — skipped in local dev)
```

---

## 1. Start the Cloud Worker

```bash
cd addons/cloud-worker

# Install dependencies (one-time)
npm install

# Apply the D1 schema (one-time)
npx wrangler d1 execute nvoos-cloud-prod --file=./schema.sql --local

# Set up placeholder secrets (one-time)
cp .dev.vars.example .dev.vars  # or create manually:
cat > .dev.vars << 'EOF'
OPENROUTER_API_KEY=sk-or-local-dev-placeholder
STRIPE_SECRET_KEY=sk_test_local_placeholder
STRIPE_WEBHOOK_SECRET=whsec_local_placeholder
CF_AI_GATEWAY_URL=https://gateway.ai.cloudflare.com/v1/test-acct/test-gw/openrouter
EOF

# Seed a test wallet + connect token (one-time)
node scripts/seed-local.mjs

# Start the dev server
npm run dev
```

The worker is now running at **http://localhost:8787**.

---

## 2. Wire WordPress to the Local Worker

Add to your `wp-config.php`:

```php
define( 'WP_MCP_AI_NV_CLOUD_BASE_URL', 'http://localhost:8787/v1' );
```

Or drop `addons/cloud-worker/wp-config-local.php` into `wp-content/mu-plugins/`.

---

## 3. Connect WordPress

1. Go to **WP-Admin → NV oOS → NV oOS Cloud**
2. Paste your Connect Token into the "Or paste a Connect Token manually" field
3. Click **Save token**
4. The page reloads — you should see "● Connected" with your balance

---

## 4. Verify the Connection

```bash
# Test health
curl http://localhost:8787/v1/health

# Test with token (replace with your actual token)
curl http://localhost:8787/v1/account/balance \
  -H "Authorization: Bearer nvc_YOUR_TOKEN" \
  -H "X-NV-Site-Url: http://localhost:8000"

# Test models (will 401 without real API keys — expected)
curl http://localhost:8787/v1/models \
  -H "Authorization: Bearer nvc_YOUR_TOKEN" \
  -H "X-NV-Site-Url: http://localhost:8000"
```

---

## 5. Run Tests

```bash
npm test             # 16 tests, all passing
npm run typecheck    # TypeScript check
npm run test:watch   # Watch mode
```

---

## Current Token (if you ran the seeding above)

- **Token:** `nvc_WgkuQv8pW7b6FKh-EUOymaKvu74NWM2mMK2A8DTrUAA`
- **Site URL:** `http://localhost:8000`
- **Balance:** $100.00 USD
- **Status:** Active

> **Important:** Change your WordPress site URL to `http://localhost:8000` for
> the token site-binding to match. Or modify the `site_url` in the connect_tokens
> table via `npx wrangler d1 execute`.

---

## Reseeding / Creating a New Token

```bash
# Generate a new token + insert into local D1
node scripts/seed-local.mjs
```

---

## Notes

- **Inference won't work** without real `OPENROUTER_API_KEY` and
  `CF_AI_GATEWAY_URL` in `.dev.vars`. The balance/ledger/token endpoints work
  fully locally.
- **Stripe webhooks** can be tested locally with `stripe listen --forward-to
  localhost:8787/stripe/webhook`.
- The D1 database persists at `.wrangler/state/v3/d1/` — delete this directory
  to reset.
- The KV namespace uses a placeholder ID — no KV features are used yet.
