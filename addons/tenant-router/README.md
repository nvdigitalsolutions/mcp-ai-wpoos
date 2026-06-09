# Schedule Anything — Tenant Router (Cloudflare Worker)

Edge-level routing worker that maps subdomain requests to the correct WordPress Multisite tenant instance. Uses Cloudflare KV for tenant→origin lookups with REST API fallback.

## Architecture

```
User → *.scheduleanything.com → Tenant Router → WP Instance
                                       │
                                  ┌────┴────┐
                                  │ TENANT_KV│  (KV namespace)
                                  └────┬────┘
                                       │ (miss)
                                  ┌────┴────┐
                                  │ Platform │  (REST fallback)
                                  │ REST API │
                                  └─────────┘
```

## Development

```bash
cd addons/tenant-router
npm install
npm run dev        # wrangler dev
npm run deploy     # wrangler deploy
npm run typecheck  # tsc --noEmit
npm test           # vitest
```

## Configuration

1. Create KV namespace: `wrangler kv:namespace create "SA_TENANT_KV"`
2. Copy the `id` into `wrangler.toml` under `[[kv_namespaces]]`
3. Set `PLATFORM_ORIGIN` env var to the WordPress platform URL
4. Deploy: `npm run deploy`

## Environment Variables

| Variable | Purpose |
|---|---|
| `TENANT_KV` | KV namespace for tenant→origin mapping |
| `RATE_LIMITER` | Cloudflare rate limiter binding (optional) |
| `PLATFORM_ORIGIN` | WordPress platform origin for KV fallback |
| `SAAS_API_KEY` | Internal API key for platform REST calls |

## License

Proprietary — © 2026 NV Digital Solutions, all rights reserved.
