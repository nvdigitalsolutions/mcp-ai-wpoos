# Console smoke — Laravel runtime (Proposal 090-B, task 1.7)

Playwright smoke for gate **G1**: *5 T0 pages + chat render against Laravel; the
network log shows zero WordPress requests.*

It runs the **built** console artifact through the same-origin proxy
(`scripts/serve.mjs`), so it exercises the deployment shape — not the Vite dev
server. The Laravel-profile bundle is built into `.e2e-dist/`; the committed
`dist/` stays a WordPress build.

## What it asserts

| Test | Gate |
|---|---|
| `boots against the Laravel contract…` | `/api/v1/features` answers `backend: laravel`, `contract: mcp-ai/v1`, **empty `endpoint_gaps`**, and `settings`/`diagnostics`/`operations`/`tools`/`chat` all `true` |
| `renders the five T0 pages` | Dashboard, Settings, Diagnostics, Operations, Tools each render their heading, and none falls back to the shell's fatal state |
| `renders the chat surface…` | `/#/chat` renders the conversation region and an enabled composer |
| `makes zero WordPress requests…` | No request to `/wp-json`, `/wp-admin`, `/wp-login.php` or `/wp-includes` across a full sweep — and `/api/v1/features` *was* requested, so an empty log cannot pass by accident |
| `shows the T0 nav items…` | Capability/feature gating does not hide the T0 items from an admin session |

Session setup is a Playwright setup project (`tests/auth.setup.ts`): it signs in
once through the branded login screen, which also proves the Sanctum
stateful-domain + CSRF-alias handshake end to end.

## Prerequisites

1. **Laravel stack up** (see `docker/laravel/README.md`):

   ```sh
   docker compose -f docker/docker-compose.laravel.yml up -d --build
   ```

2. **Console origin is a Sanctum stateful domain.** The proxy serves on
   `http://localhost:4318`, and Sanctum only starts a session for listed
   origins. `docker/docker-compose.laravel.yml` sets:

   ```yaml
   SANCTUM_STATEFUL_DOMAINS: localhost:8001,localhost:4318,127.0.0.1:4318,localhost,127.0.0.1
   ```

   If you change `E2E_CONSOLE_PORT`, add the matching `localhost:<port>` there
   and recreate the app container. Without it the login answers `419
   session_required`.

3. **Seeded account.** `DatabaseSeeder` creates `spike@example.com` /
   `password`. Override with `E2E_EMAIL` / `E2E_PASSWORD`.

## Run

```sh
cd examples/nvoos-complete-velocity

npm run test:e2e            # build .e2e-dist/ + serve + run
E2E_SKIP_BUILD=1 npm run test:e2e   # reuse an existing .e2e-dist/
npm run test:e2e:headed     # watch it
npm run report:e2e          # open the HTML report
```

Env knobs (all optional):

| Variable | Default | Purpose |
|---|---|---|
| `E2E_BACKEND_URL` | `http://127.0.0.1:8001` | Laravel origin the proxy targets |
| `E2E_CONSOLE_PORT` | `4318` | Proxy port (deliberately not `serve.mjs`'s 4181) |
| `E2E_EMAIL` / `E2E_PASSWORD` | `spike@example.com` / `password` | Session account |
| `E2E_SKIP_BUILD` | unset | `1` skips the Vite build |

## Notes

- `npm run typecheck` also runs `tsc -p e2e/tsconfig.json`. Playwright
  transpiles TypeScript without typechecking, so without this the suite rots
  silently the first time a client type changes.
- `reuseExistingServer` is **off**: a stray server on the port would serve a
  different artifact and fail the request gate for the wrong reason.
- Artifacts (`.e2e-dist/`, `e2e/.auth/`, reports) are gitignored.
- This directory does **not** ship to the standalone
  `nvoos-complete-velocity` mirror: `.github/workflows/sync-nvoos-complete-velocity.yml`
  prunes it (and `.env.laravel`, and the e2e scripts) because the mirror has no
  spa-v2 sources, Vite or Laravel stack.
- CI runs the Laravel app **PHPUnit** suite (`.github/workflows/laravel-app.yml`).
  This smoke needs the full stack (MySQL + Redis + Octane + a built SPA), so it
  is a local/pre-release gate — run it before a release tag that touches
  `docker/laravel/**`, `lib/laravel-adapter/**` or the console runtime.
