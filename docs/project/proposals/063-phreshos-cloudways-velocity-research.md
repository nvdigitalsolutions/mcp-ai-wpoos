# Research: PhreshOS + Cloudways Velocity Integration

**Date:** 2026-10-09
**Status:** 🔬 RESEARCH (companion to [`063-phreshos-pro-toolkit-cloudways-velocity-addon-proposal.md`](./063-phreshos-pro-toolkit-cloudways-velocity-addon-proposal.md))
**Sources:** phreshos.com/docs (fetched 2026-10-09), Cloudways Help Center, Cloudways blog, DigitalOcean investor news, Reddit r/CloudwaysbyDO, HackerNoon, kloudbean.com comparison

---

## 1. PhreshOS — product deep-dive

### 1.1 What it is

PhreshOS is a **self-hosted system for software built with web technologies**.
It installs on a machine, runs as a per-user service on top of the host OS,
keeps "Programs" running, stores their data, connects them, and **decides what
each Program may do**. Users interact through a **Desktop** in the browser
(default `http://localhost:4300`) where every running Program is a Window.

Two parts matter:

- **System** — the background service; keeps Programs running, stores data,
  connects Programs, enforces permissions.
- **Desktop** — the browser UI; Programs keep running when the browser closes.

### 1.2 What Programs get for free

- A place to run: the Server keeps running with or without a browser.
- One sign-in: the owner signs in to the System; Programs never handle
  accounts/sessions.
- Permissions: the System decides what a Program may reach; Programs only ask.
- Any browser: same Desktop from any browser that can reach the machine.
- **Agents alongside you**: an agent works with the same Programs through the
  System's APIs while you use the Desktop — the direct hook for NV oOS.

### 1.3 Requirements & installation

- Node.js **24.15.0+**; macOS or Linux under a normal user. Windows: System
  installs and runs, but some Programs don't work.
- `npm install --global @phreshos/cli` → `phresh system install` (downloads,
  verifies, registers a user service: `launchd` on macOS, `systemd --user` on
  Linux when available else a background process, per-user scheduled task on
  Windows).
- Update = re-run `phresh system install` (data kept). Stop/start/disable/
  enable via `phresh system <stop|start|disable|enable>`. Uninstall with or
  without `--purge` (data).
- Data in `~/.phreshos` (override `PHRESHOS_HOME`). Desktop address:
  `PHRESHOS_HOST` + `PHRESHOS_PORT` (default localhost, first free port
  4300–4399). `PHRESHOS_HOST=0.0.0.0` for containers/reverse proxies — the
  documented cloud pattern.

### 1.4 Programming model

A Program has two sides:

- **Server** (`@phreshos/server`) — keeps running; answers questions
  (`context.answer("read", () => count)`), receives messages
  (`context.subscribe("increment", …)`), announces
  (`context.publish("changed", …)`).
- **Client** (`@phreshos/client`) — asks its Server
  (`context.server.ask<T>("read")`), subscribes to changes, publishes
  messages, controls its Window (`context.window.setTitle(…)`).

Data that lasts: `context.program()` → `program.store.set/get` (small values
shared by all runs). Anything beyond the Program (network etc.) needs
`context.permissions.request("network", ["https://api.example.com"])` — the
owner grants.

### 1.5 Core concepts (toolkit contract surface)

- **Handles** — everything reachable is a handle (`system`, `desktop`,
  `context`, a Program/Process/Endpoint/Window/file), instances of classes
  from `@phreshos/core`. Handles hold no copy; each operation reaches the
  System when called.
- **Reads are current** — nothing cached for you; read once, then follow
  changes. Tools must re-read per call and never assume staleness.
- **Events** — every event source is `Subscribable`:
  `subscribe(name, fn) → stop`, `wait(name, timeout)` (10 s default),
  `events(name, { capacity, signal })` async iterator (default capacity 64,
  falls behind → throws instead of losing messages).
- **Deadlines** — `ask()`/`wait()` default 10 s; `handle.timeout(ms)` returns
  a view with another deadline.
- **Failure** — operations reject with an `Error` explaining why (missing
  permission, ended Process, passed deadline). Toolkit surfaces these
  verbatim.

### 1.6 SDK package matrix

| Code runs in | Package | You get |
|---|---|---|
| Program's Client | `@phreshos/client` | `context`, `desktop`, `system` |
| Program's Server | `@phreshos/server` | `context`, `system` |
| Script on the machine | `@phreshos/node` | `System.connect()`, `Project` |
| Terminal | `@phreshos/cli` | the `phresh` command |
| React components | `@phreshos/react` | providers + hooks |
| React interface | `@phreshos/react-ui` | PhreshOS components |

`@phreshos/core` holds the shared contracts (Program, Process, capabilities).

### 1.7 System API surface (what a toolkit can reach)

`system.about()` → `{ version, release: { name, program }, startedAt }`
(example observed: `0.1.108`, release **Sprout**); `system.icon(size)`.
Offerings: Programs/Processes/Services, Data + Logs (`system.logs.events("log")`),
Files + Uploads, Appearance, Network, Shell, Opening, Authentication.
`phresh` reaches the System as its owner: `phresh system about|status`.

### 1.8 Windows support

Documented as "not fully supported yet: the System installs and runs, but some
Programs do not work there." macOS/Linux are Tier-1; Windows best-effort.

---

## 2. Cloudways Velocity — product deep-dive

### 2.1 What it is

**Cloudways Velocity** is Cloudways' **managed Node.js hosting** product —
"build, deploy, and host fast and scalable Node.js web applications, APIs, and
backend services." Status: Public Preview → **GA on 2026-09-10**, with
multi-app Node.js hosting per plan and a published GA billing schedule.

- **Flat, predictable pricing from $20/mo** per plan; multi-app per plan.
- Persistent server resources → long-running APIs/backend services (not
  serverless cold starts). SSR and CSR both supported.
- Framework presets: **Next.js, Remix, TanStack, React, Astro, Nuxt, Svelte,
  Vite, Express, Fastify, Angular, n8n** (auto-detected from the repo).
- Node version selection (e.g. Node 22 LTS), root directory, package manager,
  entry file, start command detection.
- **Git-based deployment**: connect GitHub, pick repo + branch; auto-deploy on
  push; Deployment Management UI (deploy history, settings, env vars).
- Environment variables for build-time and runtime config (secrets kept out of
  the repo).
- Services: NGINX, **PM2**, Redis, Imunify360 (view/stop/restart).
- Domains, backups, monitoring managed through the platform.

### 2.2 Deployment model (GitOps)

Launch flow: connect GitHub → select repository + branch → Velocity detects
framework and fills build settings → confirm root directory / package manager /
entry file / Node version → add env vars → deploy. Auto-deployment on push is
the update model.

### 2.3 API surface

- **Cloudways API v1** (api.cloudways.com) — servers/apps/services for
  traditional hosting.
- **Cloudways API v2** — announced as "Enhanced Automation & Access"
  (early-access waitlist): expanded endpoints for **applications, security,
  billing, and integrations**, with Velocity included ("Cloudways Velocity is
  here. Deploy Node.js apps on managed, predictable infrastructure").
  v2 is the target for the addon client; v1 remains the fallback for
  server-level ops where relevant.

### 2.4 PhreshOS-on-Velocity fit

The PhreshOS System is a long-running Node.js service — exactly Velocity's
supported workload class. Deployment recipe:

1. Boot repo with an Express-preset-compatible entry (`system.js`) that
   installs and boots the System + Desktop HTTP listener.
2. Env vars: `PHRESHOS_HOST=0.0.0.0`, `PHRESHOS_PORT`, `PHRESHOS_HOME` on the
   persistent path — Twelve-Factor, no secrets in-repo.
3. Desktop reachable via Velocity's domain/reverse proxy; management via the
   bridge sidecar (`System.connect()`), not via public Desktop exposure.

Open item: persistent-volume behaviour for `PHRESHOS_HOME` across redeploys
(Phase 5 pilot verifies).

---

## 3. Industry standards & best practices applied

| Standard | Source | Application in this design |
|---|---|---|
| Least privilege / capability-based security | OWASP; PhreshOS's own owner-granted permission model | default-deny write tier; `owner_approved` flag on permission grants; per-assistant allowlists |
| Twelve-Factor App (config via env) | 12factor.net | all Velocity deploys configured via `PHRESHOS_*` env vars; secrets never in repo templates |
| GitOps / continuous deployment | CNCF GitOps principles | deployable state = Git repo + branch; auto-deploy on push; addon orchestrates Git, not servers |
| Idempotent ops | distributed-systems practice | lifecycle tools safe to retry, return current state (PhreshOS "reads are current") |
| Observability / health checks | SRE book | every write tool pairs with a read; health-check + log tools; schedule presets for digests |
| Secrets management | OWASP Secrets Management Cheat Sheet | key store / encrypted options, redaction in audit logs, no logging of credentials, SSH keys not passwords |
| MCP tool design (granular, canonical envelopes) | repo Unix Theory P0–P6; MCP spec 2026-07-28 | success-array/`WP_Error` envelopes; two-gate sanitisation; array-safe parsing of provider output |
| wp.org plugin guidelines | WordPress Plugin Developer Handbook | External Services disclosure, plugin-check green, zero-HTTP tests, no billing surprises without disclosure |

---

## 4. Integration matrix (transport options)

| Option | Reach | Security | Effort | Verdict |
|---|---|---|---|---|
| SSH + `phresh` CLI | self-hosted Systems | SSH keys; allowlisted subcommands | low (wp-cli precedent) | **Tier-1 for self-hosted** |
| HTTP bridge sidecar (`@phreshos/node`) | LAN + Velocity-hosted | HTTPS + shared secret | medium (small Node repo) | **Tier-1 for cloud** |
| Local CLI | same-host dev boxes | OS user | trivial | dev/test only |
| Raw Desktop HTTP scraping | any | weak (undocumented, UI-coupled) | high, brittle | rejected |

---

## 5. Source list

PhreshOS (fetched 2026-10-09):

- https://phreshos.com/docs/ (quick start)
- https://phreshos.com/docs/what-is-phreshos
- https://phreshos.com/docs/concepts
- https://phreshos.com/docs/installation
- https://phreshos.com/docs/system
- https://phreshos.com/docs/sdks

Cloudways Velocity:

- https://www.cloudways.com/en/velocity.php (product page, pricing)
- https://www.cloudways.com/blog/cloudways-velocity-is-generally-available/ (GA 2026-09-10)
- https://support.cloudways.com/en/collections/19668298-cloudways-velocity (help center collection)
- https://support.cloudways.com/en/articles/15550860-cloudways-velocity-application-overview-guide
- https://support.cloudways.com/en/articles/15550368-how-to-launch-a-velocity-application-on-cloudways
- https://support.cloudways.com/en/articles/16187473-how-to-manage-deployments-for-your-cloudways-velocity-application
- https://support.cloudways.com/en/articles/16160257-how-to-manage-services-for-your-cloudways-velocity-application
- https://www.cloudways.com/blog/introducing-cloudways-api-v2/ (API v2, early access)
- https://investors.digitalocean.com/news/news-details/2026/Cloudways-Launches-Velocity-for-Managed-Node-js-Hosting-With-Flat-Predictable-Pricing-for-Developers-and-Agencies/default.aspx
- https://hackernoon.com/deploying-a-node-app-from-a-repo-on-cloudways-velocity
- https://www.kloudbean.com/blog/cloudways-velocity-alternative/ (framework preset list)
- https://www.cloudways.com/blog/deploy-express-js-app/ (Express deploy walkthrough)

---

## 6. Version pins & assumptions

- PhreshOS observed version: `0.1.108` (release **Sprout**) — pre-1.0; docs
  corpus must be version-tagged and re-crawled weekly.
- Velocity GA billing schedule exists as of 2026-09-10; API v2 early access —
  client built version-tolerant with console-guided fallbacks.
- **Runtime mismatch risk:** Cloudways' launch docs list Node 22 LTS as a
  selectable Velocity runtime, but PhreshOS requires Node ≥ 24.15. If
  Velocity's highest selectable runtime is below 24.15, the starter flow must
  pin the highest available version, fail the deploy with a clear message, or
  fall back to a Docker/pinned-runtime approach. Verified in the Phase 5
  pilot; tracked in the proposal's Open Questions.
