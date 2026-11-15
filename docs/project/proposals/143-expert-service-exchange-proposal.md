# Proposal 143 — NV oOS Service Exchange: The Platform Where Experts Deploy Themselves as a SaaS (MCP)

**Status:** 🔮 PENDING — concept proposal, owner review requested
**Date:** 2026-11-12
**Relates to:** Proposal 055 + `addons/mcp-gateway` (fleet gateway, `mcp.nvoos.pro`) · Proposal 054 + `packages/nvoos-mcp-bridge` (client relay) · Proposal 083 (`examples/nvoos-complete-velocity`, the Complete Console Velocity pattern) · Proposal 092 (`examples/nvoos-astro-velocity`, headless storefront) · Proposal 096 (Astro Forge) · `addons/checkout-api` (vendor commerce) · Assistant Portability (`includes/assistants/class-wp-mcp-ai-assistant-portability.php`, v1.1.80+) · `nv-digital-cloudways-wpcli` skill (fleet map + install cycles) · `mcp-ai-wpoos-gateway-ops` skill (gateway ops, OAuth, registry publishing)
**Author:** NV oOS coding agent (Zed)

---

## 1. Problem Statement

**The thesis (owner's framing).** SaaS — "software as a service" — is
collapsing under its own meaning. AI is industrialising software production:
the same way electrification made power a commodity, AI makes *software* a
commodity. When software is a commodity, the scarce thing is not the code —
it is the **expertise** the software encodes and the **distribution** that
puts it in front of buyers. An expert's service *is* the SaaS: the person
deploys themselves. And if services must consume that commodity to produce
value, then **the platform that deploys, shares, and sells the commodity
becomes more valuable than any single service on it.** There is an Amazon
for physical commodities; there is no Amazon for software-as-commodity.

**The product gap.** NV oOS already owns every ingredient of that platform —
it is just operated manually, one client at a time:

1. **The commodity exists.** The Complete bundle turns any WordPress site
   into a full MCP server (`/wp-json/mcp-ai/v1/mcp`, ~1,745 tools) and ships
   a library of domain toolkits (CRM, PM, F&B, Woo, security ops, finance…). Assistant
   Portability exports an entire trained assistant as a versioned, credential-
   redacted bundle. That bundle **is** the unit of software-as-commodity.
2. **The deployment rails exist but are operator-only.** Today an "expert
   deploy" is a manual loop: Cloudways app create → SSH/WP-CLI install of the
   Complete bundle (the `nv-digital-cloudways-wpcli` skill's paste blocks) →
   assistant import → toolkit enable → optional Console Velocity app
   (Proposal 083) → gateway slug + key binding (the gateway skill's env-edit
   ritual) → directory listing. Every step is scripted *for a human operator*,
   not productised *for a customer*.
3. **Distribution exists but is unmonetised and unmarketplaced.** The gateway
   (`mcp.nvoos.pro`) already aggregates client sites behind namespaced tools
   (`sascha.*`, `ideabits.*`, `demo.*`), OAuth 2.1, and the official MCP
   Registry (entries verified live 2026-11-12: bridge 0.1.0-alpha.4, gateway
   0.3.1). But there is no catalogue of *what experts offer*, no self-serve
   onboarding, no pricing, no checkout — the marketplace layer that turns a
   fleet into a business model.
4. **The commerce rail exists but sells one product.** `addons/checkout-api`
   (Stripe, vendor-side) sells the Complete bundle to other plugins' users.
   It has never been pointed at the inverse: **selling expert services that
   run on the bundle.**

**Goal state.** A Vite frontend — the **Service Exchange** — where an expert
(a coach, an accountant, a jeweller, an F&B operator, an agency) walks a
setup wizard and, in minutes, has deployed: a Cloudways WordPress app with the
Complete bundle + their pre-built assistant + toolkits enabled; a Complete
Console Velocity app (Proposal 083 pattern); a gateway slug + API key with
their namespaced tools live on `mcp.nvoos.pro`; a Stripe price so customers
subscribe; and a directory listing so the service is discoverable. The
platform takes a marketplace fee for deploying, sharing, and selling the
commodity — the value-capture thesis, implemented.

---

## 2. Research & Industry Standards

### 2.1 MCP is the distribution standard that makes software a commodity

- MCP (Model Context Protocol, Anthropic, Nov 2024) is the open standard for
  giving AI models tools
  ([Anthropic announcement](https://www.anthropic.com/news/model-context-protocol)).
  Adoption is cross-vendor: OpenAI's Agents SDK + ChatGPT Connectors
  ([OpenAI, June 2025](https://openai.com/index/introducing-chatgpt-connectors/)),
  Google ADK
  ([google.github.io/adk-docs/mcp](https://google.github.io/adk-docs/mcp/)),
  Microsoft Copilot Studio, GitHub, and Zed all consume MCP servers. One
  deployment (one expert's service) is simultaneously sellable into every
  assistant runtime — the definitional property of a commodity with a
  standard interface.
- The **official MCP Registry** ([registry.modelcontextprotocol.io](https://registry.modelcontextprotocol.io/))
  is the machine-readable hub; directories (Glama, PulseMCP, mcp.directory,
  Smithery, mcpservers.org) poll it. Per the registry FAQ it is "primarily
  designed for programmatic consumption by subregistries" — the registry
  layer is infrastructure, **not** a marketplace; nobody owns the marketplace
  layer yet (Proposal 061 §3.1). Verified live 2026-11-12: NV oOS already has
  two active registry entries (`io.github.nvdigitalsolutions/nvoos-mcp-bridge`,
  `…/nvoos-mcp-gateway` 0.3.1), so per-expert listings are a solved mechanics
  problem, not a research problem.
- OAuth 2.1 protected-resource metadata (RFC 9728,
  [datatracker.ietf.org/doc/rfc9728](https://datatracker.ietf.org/doc/rfc9728/))
  is already live on the gateway (`/.well-known/oauth-protected-resource`,
  Auth0 tenant) — the enterprise auth story for selling services into
  managed fleets exists today.

### 2.2 Marketplace economics — the value accrues to the platform that owns deployment + distribution

Every successful "Amazon for X" captures value at the same two chokepoints —
**provisioning** and **distribution** — and charges a take for both:

| Marketplace | Model | Platform take (documented) |
|---|---|---|
| AWS Marketplace | ISVs list, AWS provisions/runs, buyer pays AWS | historically 20% listing fee; 2024–25 reductions to ~3% on the first $1M/yr ([aws.amazon.com/marketplace/sell](https://aws.amazon.com/marketplace/sell/)) |
| Shopify App Store | merchants install apps into Shopify stores | 0% on first $1M/yr since 2021, then 15% ([shopify.com/partners](https://www.shopify.com/partners)) |
| Apple App Store / Small Business | software sold into a device ecosystem | 30% standard, 15% under $1M ([developer.apple.com small-business program](https://developer.apple.com/programs/small-business/)) |
| Salesforce AppExchange | the pioneer ISV marketplace | revenue share on ISV sales, ~15% era standard |
| **NV oOS Service Exchange (this)** | experts deploy into the NV oOS + Cloudways runtime; customers buy via Stripe | provisioning fee + platform subscription + % of service revenue (see §3.6) |

The pattern: platforms that *only* aggregate listings (directories) stay free
and thin; platforms that **run the deployed commodity** (AWS runs the VM,
Shopify runs the store, Apple runs the device) earn compounding take rates.
NV oOS's equivalent of "running the commodity" is running the expert's
WordPress + Velocity estate on Cloudways — which is why the wizard must own
provisioning end-to-end, not just generate a ZIP.

### 2.3 The WordPress "WaaS" industry already validated the demand — and its failure mode

- **WP Ultimo** ([wpultimo.com](https://wpultimo.com)) turned WP multisite
  into a website-as-a-service business (plans, domains, limits). It proves
  demand for "sell a preconfigured WordPress product", but it encodes the
  industry's dominant architecture: **shared multisite tenancy**.
- The failure mode of shared tenancy is documented across the WaaS
  literature: one bad tenant degrades all tenants (no per-site blast radius),
  per-tenant custom domains require mapping plugins, plugin/theme conflicts
  compound, and no tenant can ever leave with their data.
- The agency-management layer (ManageWP, MainWP, InfiniteWP) shows the
  *control-plane* pattern — one pane over many independent WP installs — and
  matches what this repo already operates: NV Digital runs **~55 Cloudways
  apps across four servers** as isolated tenants, one app per client
  (`nv-digital-cloudways-wpcli` skill fleet map). The Exchange productises
  the same topology: **one Cloudways app per expert, not one multisite for
  all experts.**

### 2.4 Cloudways automation surface (verified research, Proposal 063)

- **Cloudways API v1** (`api.cloudways.com`) covers servers/apps/services for
  traditional hosting; **API v2** ("Enhanced Automation & Access", early
  access) adds applications, security, billing, integrations — **including
  Velocity** ([Cloudways blog](https://www.cloudways.com/blog/introducing-cloudways-api-v2/)).
  v1 is the fallback; the client is built version-tolerant (063 §6).
- **Velocity** (GA 2026-09-10) is GitOps: connect GitHub → repo+branch →
  framework-detected build settings → env vars → auto-deploy on push
  ([GA announcement](https://www.cloudways.com/blog/cloudways-velocity-is-generally-available/)).
  Constraint verified in production (gateway skill): **Velocity env vars are
  dashboard-only — no API/MCP surface** — so full "zero-touch" Velocity env
  wiring is impossible today; the wizard must either guide a dashboard step
  or keep env in the deployable repo for non-secret config.
- **WP Manager** subscription + SSH master access are the two WP-CLI rails
  this repo already uses daily (install cycles: deactivate → delete →
  install → activate; `sudo -u {sys_user}` ownership fixes). The wizard's WP
  provisioner reuses these verbatim — they are the most battle-tested code
  paths NV owns.

### 2.5 Commerce standards for platform-mediated sales

- **Stripe Connect** is the canonical marketplace-payments model (platform
  accounts, destination charges, application fees)
  ([docs.stripe.com/connect](https://docs.stripe.com/connect)). For v1 the
  Exchange deliberately does **not** become the merchant of record: each
  expert keeps their own Stripe via the existing vendor-checkout pattern
  (`addons/checkout-api`'s session/verify contract), and the platform bills
  the expert separately (platform subscription + take). This sidesteps
  marketplace-money-transmitter compliance entirely in Phase 1.
- **SaaS billing best practices** (proration, dunning, metered add-ons,
  usage caps) come from the Stripe SaaS guide
  ([stripe.com/guides/saas-pricing](https://stripe.com/guides/saas-pricing));
  the gateway already implements quota + rate-limit policy (Proposal 094),
  which is the metering layer an expert's usage-based pricing can lean on.
- **Twelve-Factor** ([12factor.net](https://12factor.net)) config-via-env and
  **CNCF GitOps** ([opengitops.dev](https://opengitops.dev)) deployable-
  state-in-git are already the repo's deploy conventions (gateway, console,
  forge all ship mirror-branch workflows). The Exchange's own API and wizard
  follow them.

### 2.6 The repo's own recorded decisions this builds on

- **083 §2.3** — BFF / same-origin proxy is the standard for cookie-authed
  SPA consoles (Auth0 + Duende citations); the SA/Complete `serve.mjs` is the
  proven implementation.
- **092 §3.2** — the contract invariant: whoever serves the contract is
  invisible to the frontend. The Exchange treats **assistant bundles** as the
  contract unit: WP, Velocity console, gateway, and directories all consume
  the same exported bundle; none may depend on how it was produced.
- **061 §3** — publish to the official registry first; directories follow
  automatically (Linkly, Tallyfy, RoxyAPI practitioner evidence).
- **083 §6.4** — cross-app SSO deferred; per-expert auth starts as
  per-app cookies/keys, same discipline here.
- **Portability skill rules** — credentials never move in bundles; MCP app
  tokens are redacted on export and re-entered on import. The wizard inherits
  this: what the expert exports, the wizard imports, and secrets are always
  minted *at the destination*, never carried.

---

## 3. Proposed Design

### 3.1 The product — anatomy

**NV oOS Service Exchange** = three coupled surfaces plus the existing fleet
infrastructure, all NV-authored:

```
Expert (the person IS the SaaS)
   │  browse → configure → deploy → publish
   ▼
┌────────────────────────────────────────────────────────────┐
│ Service Exchange  (examples/nvoos-service-exchange, Vite)   │
│  ┌─ Marketplace / Blueprint Gallery (storefront)           │
│  ├─ Provisioning Wizard (React wizard, spa-v2 stack)       │
│  └─ Exchange API (Node BFF on Velocity, control plane)     │
│      ├─ Cloudways client   (API v1 → v2 version-tolerant)  │
│      ├─ WP provisioner     (WP Manager / SSH + WP-CLI)     │
│      ├─ Velocity deployer  (mirror-branch GitOps)          │
│      ├─ Gateway registrar  (slug + key binding)            │
│      └─ Commerce           (checkout-api vendor client)    │
└──────────────┬─────────────────────────────────────────────┘
               │ provisions, one isolated estate per expert
   ┌───────────┼───────────────────────────────┐
   ▼           ▼                               ▼
Cloudways WP app                    Velocity app(s)          MCP Gateway
"MCP Server - <expert>"              (Complete Console,      mcp.nvoos.pro
  Complete bundle +                  083 pattern; optional   new slug per
  blueprint import +                storefront per 092)      expert, own key,
  toolkits enabled,                                             namespaced tools
  op_ credential minted                                      <expert>.<tool>
   │           │                               │
   └───────────┴───────────────┬───────────────┘
                               ▼
        Customers (subscribe via Stripe; connect via
        bridge npx snippet, ChatGPT Connector, Claude Code,
        or direct streamable HTTP)
```

**Components (from the owner's brief, plus the extras the research adds):**

| Component | Role in the Exchange | Reuse |
|---|---|---|
| **MCP Gateway** | the *share/sell* layer — one key per expert, namespaced tools, quotas, OAuth | `addons/mcp-gateway` (live) |
| **MCP Bridge** | the *consume* layer — customers' stdio relay | `packages/nvoos-mcp-bridge` (live) |
| **Complete plugin** | the *commodity* — the runtime every expert deploys | `nvdigital-open-operator-system-oos-complete` (live) |
| **Complete Velocity** | the *console* — the expert's branded admin surface | `examples/nvoos-complete-velocity` (083) |
| **Assistant Portability engine** | the *blueprint codec* — bundle export/import | v1.1.80 engine (live) |
| **checkout-api** | the *sell* rail — Stripe, vendor-side | `addons/checkout-api` (live) |
| **Astro storefront (092) / Forge (096)** | optional tier-2: a public marketing storefront for the expert's service | `examples/nvoos-astro-velocity`, forge |
| **Auth0 (gateway OAuth)** | enterprise buyers consuming via OAuth 2.1 | live tenant |

### 3.2 The unit of commodity — the Service Blueprint

A **Service Blueprint** extends the assistant-portability bundle with a
provisioning manifest. Format v1 (`blueprint_version: 1`):

```
service-blueprint/
├── bundle.json          # assistant-portability format v1 (denylisted, no creds)
├── manifest.json        # provisioning + commerce manifest
│   ├── name, slug, tagline, industry, icon
│   ├── toolkits[]       # toolkits/presets to enable at provisioning
│   ├── mcp_apps_refs[]  # connection_ref entries (Remote Sites) — no inline tokens
│   ├── site: { region, size, php, domain, wp_manager: bool }
│   ├── console: { enable: bool, template: "complete-velocity" }
│   ├── storefront: { enable: bool, template: "astro-storefront" | "forge" }
│   ├── gateway: { slug, visibility: public|private, tool_allowlist[] }
│   └── commerce: { stripe_price_id?, trial_days, metered: bool }
└── README.md            # human-facing listing copy (directory submission)
```

Non-negotiables (inherited from the portability skill):
1. **Credentials never move** — the bundle denylist already guarantees it;
   the manifest carries only `connection_ref` pointers and price IDs.
2. **One blueprint ⇒ one estate** — a blueprint never provisions shared
   tenancy; slug collision is a hard error, not a merge.
3. **Import is version-tolerant, export is version-pinned** — same rule the
   portability engine enforces for assistant bundles.

### 3.3 The Provisioning Wizard (the Vite frontend)

Stack per repo conventions (083, spa-ui skill): React 19.1 + Vite + TS,
spa-v2 host-bootstrap patterns (runtime-config fetch, shims), NDS design
tokens, Tailwind v4. Entry chunk budget ≤ 150 KB gzip (G4, 083). Deployed as
the same kind of zero-dependency Node Velocity app the console uses (`serve`
static + BFF proxy).

Wizard steps (each step idempotent, resumable, dry-run-capable):

1. **Profile** — expert identity, brand, contact (Auth0 account for the
   Exchange itself, separate from gateway OAuth).
2. **Blueprint** — pick from the gallery (profession templates seeded from
   the repo's toolkits: F&B operator, CRM agency, PM coach, Woo store, …) or
   upload an exported bundle; preview tools + assistant.
3. **Configure assistant** — system prompt polish, provider/model
   selection, toolkit toggles, MCP apps (`connection_ref` pickers).
4. **Site** — Cloudways server/region/size; create WP app; install Complete
   (proven install cycle); import bundle; mint `op_` operator credential.
5. **Console** — deploy the 083-pattern Velocity app from a per-expert
   mirror branch (forge-deploy skill mechanics); wire site URL + cookie
   domain; Velocity env step is dashboard-guided where API v2 is unavailable.
6. **Gateway** — register slug, bind key (32-byte base64url, fail-closed
   rules per gateway skill), allowlist tools; verify with the gateway skill's
   deploy-verification ladder (`/health/full`, one `tools/call`).
7. **Commerce** — Stripe price via checkout-api session/verify; platform
   subscription for the expert; trial days.
8. **Publish** — generate the customer connect snippets (bridge npx block,
   ChatGPT Connector config, Claude Code plugin install lines), optional
   registry entry per expert (`mcp-publisher`), directory submissions.

The wizard never runs shell blindly: every destructive Cloudways/WP action
reuses the repo's two-gate discipline (sanitise at entry, escape at exit) and
the destructive-ops gate (`confirm_destructive`) before app deletion or
toolkit reset.

### 3.4 The Exchange API (control plane)

A Node service on Velocity (`nvoos-service-exchange` mirror repo), built
from the `addons/mcp-gateway` anatomy (Express + helmet + rate-limit,
env-only config, `node --test`, one-way mirror, fail-closed):

- `POST /api/v1/estates` — orchestrated provisioning job (state machine:
  `cloudways_app → wp_install → bundle_import → console_deploy →
  gateway_bind → commerce → published`; every transition idempotent and
  recorded in an estates ledger).
- `GET /api/v1/estates/{slug}` — status, health, verifications (polls the
  gateway `/health/full` and the WP site's own MCP endpoint).
- Cloudways client — API v1 now, API v2 when GA (version-tolerant per 063);
  where no API exists (Velocity env, WP Manager absence), the API emits
  **operator paste-blocks** — the exact SSH/WP-CLI cycles from the
  `nv-digital-cloudways-wpcli` skill, rendered as copyable steps with
  verification commands. Degradation is graceful: the product works as
  "guided automation" even where Cloudways automation is incomplete.
- Secrets: `op_` tokens minted during provisioning are stored encrypted in
  the control plane (never in the blueprint, never in git); gateway keys
  follow the rotation discipline (PRIMARY/PREVIOUS env pairs).

### 3.5 What this deliberately does **not** do (v1)

- **No shared multisite / WaaS tenancy** (WP Ultimo model) — one app per
  expert, per §2.3.
- **No marketplace-as-merchant-of-record** — Stripe Connect destination
  charges are a Phase 2 option once volume justifies compliance cost (§2.5).
- **No cross-app SSO** (083 §6.4 precedent) — per-expert console cookie +
  per-expert gateway key are the v1 auth story.
- **No per-expert registry entries by default** — directory listings are
  opt-in at step 8; private gateway slugs stay invisible (PUBLIC_SITE_SLUGS
  discipline).
- **No code generation for the expert's service** — Forge (096) is an
  optional storefront tier, not a v1 requirement.

### 3.6 Pricing & the platform's take (the thesis, operationalised)

Three revenue lines, none of which requires the platform to touch customer
money in v1:

1. **Provisioning fee** — one-time per estate (deploy + configure).
2. **Platform subscription** — monthly, per estate, tiered by size (the
   Cloudways-style hosting margin, on top of the expert's own Cloudways
   account or a resold allocation).
3. **Marketplace take** — % of the expert's service revenue while the
   gateway is the metering point. Benchmark: AWS ~3–20%, Shopify 0–15%
   (§2.2). Because the gateway already enforces quotas + policy (Proposal
   094), usage-based pricing is a config change, not a build.

---

## 4. Implementation Plan

| Phase | Scope | Gate / exit criteria |
|---|---|---|
| **0 — Blueprint + wizard shell** (2–3 wks) | `service-blueprint` schema + validator (`blueprint_version: 1`); Vite wizard shell with steps 1–3 against a mock API; gallery seeded with 5 profession templates; `node --test` + vitest/jsdom suites | Blueprint round-trips through the portability engine; wizard completes end-to-end in mock mode |
| **1 — Cloudways client** (1–2 wks) | API v1 client (servers/apps) + v2 probe; operator paste-block renderer from the `nv-digital-cloudways-wpcli` install cycles; estates ledger | `dry_run` provisions a real WP app on a staging server with zero manual steps (or documented paste-blocks where the API lacks the op) |
| **2 — WP provisioner** (1–2 wks) | Complete-bundle install automation, bundle import via the portability engine, toolkit preset enable, `op_` credential mint + encrypted store | Fresh estate passes the gateway skill's Tier-1 smoke (tools/list + one tools/call) unattended |
| **3 — Console deploy** (1–2 wks) | Per-expert mirror-branch GitOps deploy of the 083 console pattern; cookie domain wiring; dashboard-guided env step | Expert logs into their branded console on the new estate's domain |
| **4 — Gateway registrar** (1 wk) | Slug + key binding automation with fail-closed validation; `/health/full` verification ladder; `PUBLIC_SITE_SLUGS` discipline | Expert's namespaced tools live on `mcp.nvoos.pro` with their own key; demo walkthrough recorded |
| **5 — Commerce** (1–2 wks) | checkout-api session/verify for expert Stripe prices; platform subscription billing for the expert; trial handling | End-to-end: buyer pays → license issued → gateway tool quota honoured |
| **6 — Publishing** (1 wk) | Connect-snippet generator (bridge npx, ChatGPT Connector, Claude Code plugin); optional per-expert registry entries via `mcp-publisher`; directory submission pack | One public expert listing discoverable in the registry API |

**Total estimate:** 8–11 dev-weeks to a sellable v1, leaning entirely on
already-shipped infrastructure; the only genuinely new code is the wizard,
the control-plane state machine, and the Cloudways client.

---

## 5. Risks & Mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| **Cloudways API v2 stays early-access / gaps** (Velocity env is dashboard-only today) | High | Version-tolerant client; operator paste-blocks as first-class degradation (§3.4); never block a sale on an API gap |
| **SSH/WP Manager friction on new apps** (publickey denials, no-sudo users — both observed in production) | Medium | Provisioner runs as the app user (`sudo -u {sys_user}`); falls back to guided admin-page steps; the console-app ops notes are the checklist |
| **Credential sprawl** (op_ tokens, gateway keys, Stripe) | High | Portability denylist inherited; secrets minted at destination only; encrypted control-plane store; key rotation discipline |
| **Tenant blast radius** (one expert's bad plugin/config) | Medium | One-app-per-expert isolation is the mitigation itself (§2.3); per-app monitoring via existing Cloudways MCP analytics |
| **Marketplace compliance** (tax, money transmission if we become MoR) | Medium | v1: platform never touches customer money (§3.6); Stripe Connect revisited only at volume |
| **Anti-Bot / Imunify walls on new apps** (gateway skill's 401-playbook root cause class) | Medium | Provisioning smoke test includes a direct `tools/list` against the new estate before publish; bot-wall fingerprints documented |
| **Support load from non-technical experts** | Medium | Wizard's verify-at-every-step design; paste-blocks end every line with `;` (known terminal-merge quirk); HealthCheck + status page per estate |

---

## 6. References

- Live MCP surfaces verified 2026-11-12: `registry.modelcontextprotocol.io/v0.1/servers` (bridge 0.1.0-alpha.4 + gateway 0.3.1 active)
- [Anthropic — Model Context Protocol](https://www.anthropic.com/news/model-context-protocol) · [OpenAI — ChatGPT Connectors](https://openai.com/index/introducing-chatgpt-connectors/) · [Google ADK MCP](https://google.github.io/adk-docs/mcp/) · [RFC 9728](https://datatracker.ietf.org/doc/rfc9728/)
- [Official MCP Registry](https://registry.modelcontextprotocol.io/) · Proposal 061 (registry/directory layers, propagation evidence)
- [AWS Marketplace — Sell](https://aws.amazon.com/marketplace/sell/) · [Shopify Partners](https://www.shopify.com/partners) · [Apple Small Business Program](https://developer.apple.com/programs/small-business/) · [Stripe Connect](https://docs.stripe.com/connect) · [Stripe SaaS pricing guide](https://stripe.com/guides/saas-pricing)
- [WP Ultimo](https://wpultimo.com) (WaaS precedent + tenancy contrast)
- Cloudways: [API v2 announcement](https://www.cloudways.com/blog/introducing-cloudways-api-v2/) · [Velocity GA](https://www.cloudways.com/blog/cloudways-velocity-is-generally-available/) · Proposal 063 (Velocity deep-dive, standards matrix)
- [Twelve-Factor](https://12factor.net) · [CNCF OpenGitOps](https://opengitops.dev)
- In-repo: Proposals 054, 055, 061, 063, 083, 092, 094, 096; `docs/assistant-import-export.md`; skills `nv-digital-cloudways-wpcli`, `mcp-ai-wpoos-gateway-ops`, `mcp-ai-wpoos-checkout-integration`, `mcp-ai-wpoos-assistant-portability`, `nvoos-forge-deploy`

---

## 7. Decisions (owner review)

1. **Name & home** — "NV oOS Service Exchange" (`examples/nvoos-service-exchange`,
   mirror `nvdigitalsolutions/nvoos-service-exchange`, domain
   `exchange.nvoos.pro` on Velocity). Alternatives welcome.
2. **Blueprint format v1** as specced in §3.2 — approve extending (not
   replacing) the portability bundle?
3. **Tenancy stance** — confirm one-Cloudways-app-per-expert over multisite
   (§2.3/§3.5)?
4. **Commerce stance** — confirm v1 platform-never-touches-customer-money
   (expert's own Stripe via checkout-api; platform bills the expert)?
5. **Marketplace take** — pick a launch number from the §3.6 benchmarks
   (suggested: 0% take during pilot, 10–15% at GA, plus provisioning fee +
   monthly platform subscription)?
6. **Phase 0 gate** — approve Phase 0 (blueprint schema + mock wizard) as the
   immediate next work item?
