# Proposal 151 — The Estate Runtime: Mission, Positioning & Executive Summary

**Status:** 🟡 ACTIVE — category and vocabulary adopted (D-1/D-2, 2026-11-17); commercial framing (D-3) and the published host contract (D-4) await owner sign-off.
**Date:** 2026-11-17
**Relates to:** Proposal 090 (Laravel runtime flip) · Proposal 149 (Laravel admin parity; D-1/D-5 commercial split) · Proposal 083 (Complete Console) · Proposal 090-m (tool registration & readiness) · Proposal 094 (gateway policy & governance) · Proposal 052 (decision-scope guard) · Proposal 067 (corpus governance) · Proposal 143 (Service Exchange) · `lib/core` · `lib/laravel-adapter` · `docker/laravel/app-skeleton` · `docs/operations/compliance/iso27001/ISMS-Scope.md` · `docs/operations/compliance/EU_AI_ACT_2026.md`
**Author:** NV oOS coding agent (Zed)
**Research companions:** none separate — this doc *is* the research output; external sources are listed in Appendix C and cited inline as S1–S10.
**Purpose:** one canonical source for how we describe NV oOS as a whole. Everything else (README, wp.org listing, sales page, deck) should quote this document rather than paraphrase it.

---

## 0. Decision Record

| # | Decision | Date | Consequence |
|---|---|---|---|
| D-1 | The category is **agent runtime**, and the product noun is **estate**. | 2026-11-17 | We describe NV oOS as *a host-neutral runtime for agent estates*, never as "an AI plugin" or "an agent OS" (§5, §7) |
| D-2 | The architecture is described in five layers: **kernel → hosts → control plane → workload → edge**. | 2026-11-17 | "The plugin is the runtime" is retired as a formulation; the plugin is a *host* (§4) |
| D-3 | Commercial shape: **free WordPress plugin as distribution; commercial estate runtime on Laravel; Pro remains available on WordPress.** | proposed (aligns with 149 D-1/D-5) | The mission must read as one product in two deployment postures, not two products (§6) |
| D-4 | "Platform" is used only after the **host contract is published**; until then the honest word is "runtime". | proposed | Sequencing gate in §8.3; no external copy may say "platform" before it |
| D-5 | Mission copy contains **no volatile counts**. Numbers live in the evidence table only. | 2026-11-17 | Counts change per release; the mission line must survive them (§7.1, R6) |

---

## 1. Mission

### 1.1 Mission statement (primary — 2 sentences)

> **NV oOS gives every organisation an estate it owns: one governed runtime where assistants, tools, workflows and memory run on the host it chooses — WordPress today, a standalone service next — without surrendering control of its models, data or policy.**

Components, per standard mission-statement practice (who / what / how / result — §13 S6–S10):

| Component | In the sentence |
|---|---|
| Who it serves | "every organisation" — operators and their teams, not only developers |
| What it does | "one governed runtime where assistants, tools, workflows and memory run" |
| How it is different | "on the host it chooses" + "without surrendering control" |
| Result | "an estate it owns" |

### 1.2 Mission, three registers

| Register | Statement | Used in |
|---|---|---|
| **Organisation** | Give every organisation an agent estate it owns, governs and can move. | README, About page, wp.org |
| **Product** | Run assistants, tools, workflows and schedules in one governed runtime that deploys inside WordPress or as a standalone multi-tenant service. | Sales page, deck p.1 |
| **Engineering** | Keep the engine host-neutral: contracts in `lib/core`, adapters in hosts, policy and audit at the boundary, and every claim CI-verifiable. | `AGENTS.md`, contributor docs |

### 1.3 Vision (10-year)

> Agents stop being features of one application and become residents of an estate the organisation controls — portable between hosts, accountable to policy, and auditable by default.

### 1.4 Operating principles

1. **Host-neutral by contract.** Anything the engine needs is a domain contract; the host supplies the adapter.
2. **Governance before capability.** A tool that cannot be scoped, costed and audited does not ship enabled.
3. **Honest claims.** Runtime, not OS. Policy isolation, not sandbox isolation. Mapping, not certification (§10).
4. **Reproducible evidence.** Every public claim traces to a command, a contract file or a test.
5. **Portable by default.** An estate that cannot be exported is not owned.

### 1.5 What the mission is not

| Not this | Because |
|---|---|
| "An AI plugin" | One host of two; the kernel predates and outlives it |
| "An agent OS" | No process/peripheral isolation guarantees |
| "A coding agent" | Different layer — coding agents are *clients* over MCP (§3) |
| "A framework" | That is `lib/core` specifically, one layer down |
| "A no-code agent builder" | Tenancy, policy, audit and deployment are the product, not the canvas |

---

## 2. Executive Summary

**NV oOS is an agent runtime, and its unit of product is an estate.**

Most agent tooling built through 2025–2026 optimised the *developer's loop*: a coding agent in a terminal or IDE, with the model, harness and surface vertically integrated. That wave is real and useful, but it answers a different question — "how does an engineer get code written faster?" It does not answer "where do an organisation's agents *live*, under whose policy, with what memory, at what cost, and how do they move when the platform changes?"

NV oOS answers the second question. It is a host-neutral runtime: `lib/core` holds the orchestration engine, provider routing, workflow executor, MCP transport policy and tool-readiness manifest; hosts supply auth, storage, queue, tenancy and process model. Today there are two hosts — the WordPress plugin (`nvoos/wordpress-adapter`) and a standalone Laravel service (`nvoos/laravel-adapter`: Octane, queue workers, a real scheduler, MySQL + Redis). One React console drives both, against a single CI-guarded endpoint contract.

**What exists today (auditable, not aspirational):**

- **Two hosts, one engine.** ~1,747 tools across Base and Pro (live registry authoritative; ~360 base + ~1,388 Pro), registered through a 223-class readiness manifest rather than by hand, and CI-audited.
- **A declared contract instead of a promise.** The Complete Console declares 45 endpoint keys; the Laravel runtime measured **39 route-backed / 6 declared non-route with `endpoint_gaps: []`** on 2026-11-16 — the runtime answer matches the declaration key for key.
- **Governance as a first-class subsystem.** Twelve security classes on WordPress (request guard, destructive-ops gate, URL guard, concurrency guard, cost tracker, load guard, read-only profile gate, audit logger, CSP, CORS, API-key store, security posture); on Laravel, Gates + `AdminAccessPolicy`, sealed credentials, and `nvoos_admin_audit` writing both request rows and record rows. The gateway adds per-tool token-bucket quotas, deny-lists and W3C `traceparent` propagation.
- **State that survives the request.** Agent memory, Paper Store, transcripts, `nvoos_workflow_runs`, `nvoos_schedules`/`schedule_runs`, approvals, harness trace records under a `TracePolicy`.
- **Tenancy with an owner.** `Tenant`/`TenantContext`/`EstateScope`, an operator plane and a tenant plane, per-estate slug uniqueness, fail-closed scope resolution.
- **MCP as connective tissue, not a feature.** Server, gateway (`mcp.nvoos.pro`), Remote Sites client, MCP Apps, dual-era handshake policy shared in core.

**Why this matters now.** The market has visibly stratified into framework / harness / runtime / platform, and the runtime layer is where governance, deployment and audit value is accreting (S1–S5). Hyperscaler runtimes own the enterprise; coding-agent vendors are converging on being *clients* of runtimes. The unoccupied position is the **owned estate**: a runtime a business can run on infrastructure it already has, with tools that reflect its own operations, and an exit path that is an export, not a migration project.

**Why us and not a generic runtime.** Two independent hosts passing one contract is the evidence a runtime is real rather than a rebranded framework. That is also the artifact the WordPress ecosystem otherwise lacks: a runtime that is *also* a deep CMS integration surface, with 244 admin surfaces already mapped for parity.

**Commercial shape.** The WordPress plugin is the free distribution edge — where the data already lives and where the audience already is. The commercial estate runtime is the standalone host for operators who need real tenancy, queueing, scheduling and SLA. Pro remains available on WordPress (149 D-5). One product, two postures.

**What we do not claim.** No process-level sandbox for tool execution, no deterministic-replay durable execution, no certification against ISO/IEC 42001 or the EU AI Act. These are named non-claims with owners and sequencing (§10), because a runtime that overstates its isolation model is not trusted twice.

**The ask.** Adopt this vocabulary (D-1/D-2), treat this document as the single source of mission copy (D-5), and sequence the published host contract as the next strategic milestone after parity (§8) — that publication is what converts "runtime" into "platform".

---

## 3. Category context: why "runtime", not "agent" or "plugin"

The 2026 literature has converged on four distinct layers (S1–S5):

| Layer | Definition used in the industry | NV oOS artifact |
|---|---|---|
| **Framework** | Reusable primitives for agents, tools, state, orchestration; you call it from your code | `nvoos/core` (32 domain contracts, ChatOrchestrator, ProviderRouter) |
| **Harness** | The assembled agent loop — context, tools, permissions, persistence, guardrails around a model | Chat loop + tool dispatch + memory + harness layers |
| **Runtime** | Runs and *manages* agents in production: lifecycle, long-lived processes, identity, policy, observability | Hosts (WP + Laravel), gateway, scheduler, queue, audit, token manager |
| **Platform** | Third parties build on it: published contracts, extension points, marketplace/registry | *Not yet* — gated on D-4 (§8.3) |
| **Sandbox** | Isolates model-written code (container/microVM) | *Not claimed* — Media Worker sidecar is the existing out-of-process precedent |

Two consequences follow, and both are load-bearing for our copy:

1. **"Runtime" is the defensible word today** — lifecycle, policy, scheduling and observability exist in code. "Platform" is one gate away but requires publication.
2. **Coding agents are a client class, not a competitor.** They occupy harness + surface for developers. A runtime that serves MCP is a *host* to them. Framing them as rivals both insults the reader and misdescribes the architecture.

The evidence for the latter is directly observable in the ecosystem: an agent-tooling vendor's public surface is a set of IDE/terminal plugins plus a deployment platform and a read-only MCP server for inspecting deployed agents — i.e. the coding agent is the authoring surface, and the runtime is the product.

---

## 4. The architecture, as evidence

```
┌──────────────────────────────────────────────────────────────────┐
│ EDGE         MCP gateway (mcp.nvoos.pro) · REST · SSE · Remote    │
│              Sites client · MCP Apps · mesh / peer               │
├──────────────────────────────────────────────────────────────────┤
│ CONTROL      Complete Console (45-key contract) · Filament        │
│ PLANE        /ops (operator) · /admin/{estate} (tenant)           │
├──────────────────────────────────────────────────────────────────┤
│ WORKLOAD     ~1,747 tools (Base + Pro) · toolkit manifests ·      │
│              85 skills · workflows · schedules · OKF knowledge    │
├──────────────────────────────────────────────────────────────────┤
│ GOVERNANCE   12 security classes (WP) · Gates + AdminAccessPolicy │
│              (Laravel) · cost/concurrency/quotas · audit          │
├──────────────────────────────────────────────────────────────────┤
│ HOSTS        nvoos/wordpress-adapter      nvoos/laravel-adapter   │
│              (WP request/cron lifecycle)  (Octane + queue +       │
│                                            schedule:work + MySQL  │
│                                            + Redis, multi-tenant) │
├──────────────────────────────────────────────────────────────────┤
│ KERNEL       lib/core — 32 domain contracts · ChatOrchestrator ·  │
│              ProviderRouter · WorkflowExecutor (Kahn) ·           │
│              McpHandshakePolicy · ToolReadiness · OKF reader      │
└──────────────────────────────────────────────────────────────────┘
```

| Claim | Traceable to |
|---|---|
| Engine is host-neutral | `lib/core`, `nvoos/laravel-adapter` implements the same contracts as `nvoos/wordpress-adapter` |
| Console is host-neutral | `spa-v2` / `examples/nvoos-complete-velocity` against either backend; `contracts/console-endpoints.json` |
| Concurrency is real, not pseudo-cron | `oos-laravel-app` (Octane), `oos-laravel-worker`, `oos-laravel-scheduler` (`schedule:work`) |
| Tenancy is enforced | `Tenant`, `TenantContext`, `EstateScope`, `(tenant_id, slug)` indexes, `EstateScope` fails closed |
| Tools are declared, not ad hoc | `lib/core/resources/tool-readiness.php` (223 classes), `bin/audit-tool-readiness.php`, CI job |
| Governance is auditable | WP security classes; `nvoos_admin_audit`; gateway quotas/deny-lists (094); decision-scope guard (052); corpus governance (067) |
| Parity is measurable | 45-key contract, 39 true / 6 false, `endpoint_gaps: []` (2026-11-16) |
| Admin coverage is mapped | 244 WP admin page classes (85 core + 159 Pro) classified in 149's ownership matrix |

> **Rule for reuse:** any external document quoting a number must cite the file or command that produced it. Counts are release-scoped; the live registry and the `/api/v1/features` read are authoritative.

---

## 5. The estate: why this noun

"Estate" already exists in the codebase — `Tenant`, `EstateScope`, `/ops/estates`, "operator estate" — so the product noun and the data model agree. It is also the right noun strategically, because it carries what coding agents do not:

| Property | Why "estate" carries it | Product consequence |
|---|---|---|
| **Ownership** | An estate has an owner and a boundary | Unit of sale; licence and data-residency story |
| **Governance** | Estates are administered, not just used | Policy, roles, audit, approvals become native |
| **Continuity** | Estates outlive tooling choices | Memory, schedules and runs have a home |
| **Portability** | Estates can be transferred | Export/import becomes a feature, not a migration project |

**Unit definitions** (use these consistently):

- **Estate** — a tenant-scoped runtime instance: assistants, tools, memory, workflows, schedules, policies, credentials, audit.
- **Operator** — the party administering estates (`/ops`).
- **Resident** — an assistant or agent living in an estate.
- **Host** — where an estate runs (WordPress, Laravel).
- **Workload** — what a resident can do (tools, toolkits, skills, workflows).

The one-line formulation: **"your agents, tools and policy, in an estate you own, on whichever host you want."**

---

## 6. Commercial shape (one product, two postures)

| Posture | Host | Licence | Buyer | Job |
|---|---|---|---|---|
| **Distribution edge** | WordPress plugin | Free + Pro | Site owners, agencies | Fastest path to a working agent on data that already exists |
| **Owned estate** | Laravel service | MIT runtime + commercial admin (149 D-1) | Operators, multi-tenant businesses | Real tenancy, queue, scheduler, SLA, audit, migration |
| **Proof workloads** | Both + edge apps | Mixed | Reference customers | Astro Forge storefront, Cognition dictation, QuickBooks Desktop MCP, Service Exchange |

The mission must never read as "two products". It reads as *the same estate, deployable two ways*, with the free host as the on-ramp and the owned host as the destination.

Do not imply the WordPress posture is legacy: 149 D-4 makes wp-admin a first-class surface permanently, and D-5 keeps Pro on WordPress.

---

## 7. Drafting rules applied (research → practice)

### 7.1 What the research required, and how this doc complies

| Practice (S6–S10) | Application here |
|---|---|
| One or two sentences; no jargon pile-up | §1.1 is two sentences, 44 words |
| Name who is served, what changes, how | Component table §1.1 |
| Simple, clear, accurate, inspirational | Accurate clause "without surrendering control" carries the inspiration; no superlatives |
| Back claims with a number — but not *inside* the mission | Numbers live in §2/§4 only (D-5) |
| Separate mission from vision | §1.1 vs §1.3 |
| Executive summary readable standalone, one page | §2 is self-contained: problem → what exists → why now → differentiation → commercial → non-claims → ask |
| Executive summary leads with the decision, not the history | §2 opens with the category and the unit of product |

### 7.2 Voice

- Declarative, present tense, no "revolutionary", no "seamless", no "unleash".
- Name the layer before the benefit: readers who know the space will check the layer first.
- Every superlative replaced by a measurable statement. "Largest tool surface" becomes "~1,747 tools, live registry authoritative".
- Prefer "governed", "owned", "portable", "auditable" — the four words the estate actually earns.

---

## 8. Parity and the platform gate

### 8.1 Parity is contract parity, not tool parity

Chasing 1,747 tools on both hosts is unbounded and would fail. Define the milestone as:

1. **45/45 console endpoint keys** true on both hosts, or explicitly declared, with `endpoint_gaps: []`.
2. **Shared core suites green on both hosts**: `FixtureReplayTest`, `ToolReadiness`, `McpHandshakePolicy`, workflow executor.
3. **A published host-native exclusion list** — Elementor, JetEngine, WooCommerce, docs-hub, MJML `compile()`/`validate()` (Media Worker sidecar), `save_profession`. These are host-native by nature and are excluded by declaration, not by omission.
4. **One writer per operation**, per the 149 D-6 ownership matrix.

### 8.2 What becomes true at parity

| New claim | Why it only holds then |
|---|---|
| "Same estate, any host" | Export/import round-trip proven across hosts, not just surfaces |
| "Conformance-tested runtime" | Two implementations passing one contract = a spec, not a doc |
| "SLA-able" | Real worker + scheduler processes, no request-bound agent loop |
| "Vendor-neutral tool library" | Manifest-registered tools served identically on both hosts |
| "MCP-native by construction" | Gateway fronts a contract, not one implementation |

### 8.3 The platform gate (D-4)

Sequence, strictly:

1. Parity per §8.1.
2. Publish the **host contract v1**: endpoint keys, adapter interfaces, exclusion list, conformance suite.
3. Only then use "platform" externally; open the adapter path for a third host.

Until step 2 ships, external copy says **runtime**, and internal copy may say "platform-shaped".

---

## 9. Success metrics

| Metric | Baseline | Target | Source of truth |
|---|---|---|---|
| Console contract parity | 39 route-backed / 45 keys, `endpoint_gaps: []` | 45/45 or declared exclusions | `GET /api/v1/features` |
| Host-native exclusions declared | ad hoc in prose | one published list, versioned | this doc + host contract |
| Tool readiness completeness | 223 classes, CI-gated | 100% of registrable tools | `bin/audit-tool-readiness.php` |
| Cross-host portability | assistant bundle v1, same-host surfaces | WP → Laravel round-trip in CI | portability tests |
| Governance parity | 12 WP classes; Laravel Gates + audit | per-estate policy parity both hosts | 094, 052, 149 |
| Admin surface classification | waves 0–2 shipped (149) | 244/244 classified, zero unowned | 149 ownership matrix |
| Mission-copy drift | multiple competing formulations | one source (this doc), quoted | docs review |

Measure before publishing any of these externally. A stale number in a mission document costs more trust than a missing one.

---

## 10. Non-claims and risks

| # | Risk / non-claim | Honest position | Mitigation |
|---|---|---|---|
| R1 | **No process sandbox** | Isolation is capability/policy-level (gates, read-only profiles, quotas, cost ceilings), not container/VM per agent | Name it in §2; sequence out-of-process tool execution (Media Worker pattern) before any "secure execution" claim |
| R2 | **No durable replay** | `WorkflowExecutor` runs Kahn-order, fail-fast, with run rows — not event-sourced step resumption | Say "run history and approvals", never "durable execution" until checkpoint/resume ships |
| R3 | **Two-host tax** | Every Pro toolkit is potentially two implementations | Exclusion list + host contract + "one writer" rule; parity is a gate, not a permanent backlog |
| R4 | **WordPress perception** | The strongest distribution asset is also the strongest credibility liability ("just a plugin") | Lead with the Laravel host in technical material; lead with WordPress in acquisition material |
| R5 | **Standards over-claim** | We map to standards; we are not certified | §11 wording rules; legal checklist gate before sales copy |
| R6 | **Count drift** | Tool/admin counts change per release | D-5: no counts in mission; evidence table cites commands |
| R7 | **Platform before contract** | "Platform" without published contracts is a promise with no exit | D-4 sequencing |

---

## 11. Standards alignment (mapping, not certification)

> **Wording rule:** write "aligned with", "mapped to", "tracked against". Never "certified", "compliant", or "approved". Certification is an audit outcome, not a positioning choice.

| Standard / framework | What we map | Where it lives | Status |
|---|---|---|---|
| **NIST AI RMF** (Govern / Map / Measure / Manage) | Govern: policy gates, read-only profiles, decision-scope guard (052). Map: tool readiness manifest, capability registry. Measure: cost tracker, telemetry, harness traces. Manage: approvals, audit rows, incident records | `includes/security/`, `lib/core`, 052, 067 | Active mapping |
| **ISO/IEC 42001** (AI management system) | Management-system scaffolding: scope, roles, documented controls, evidence trails | `docs/operations/compliance/iso27001/ISMS-Scope.md` (ISMS scope), audit logs | Scaffolding only — no certification |
| **ISO/IEC 27001** | Information-security controls, ISMS scope and interfaces | `docs/operations/compliance/iso27001/` | ISMS scope documented |
| **EU AI Act** (Arts. 13–15, 50, 52) | Transparency, human oversight, logging, AI-interaction disclosure | `docs/operations/compliance/EU_AI_ACT_2026.md`; legal checklist §2 | Mapping documented; per-release re-check |
| **OWASP LLM Top 10 / Agentic threats** | Prompt-injection guardrail, output guardrail, citation verifier, tool schema auditing, SSRF guard, path/URL guards | `docs/DOCUMENTATION_INDEX.md` (v1.1.51 coverage note), `docs/reference/orchestration/orchestration-harness-reference.md` | Coverage tracked; re-measure before quoting |
| **SGI transparency** | Disclosure and transparency obligations | Proposal 017 | Implemented track |
| **MCP specification** (2026-07-28 dual-era) | `server/discover` → `initialize` fallback; no retry on 401/429/500; SEP-aligned request envelope | Proposal 015, `lib/core/Domain/Mcp` | Implemented |
| **WCAG 2.2 AA** | Console + chat contrast, focus, labels | `contrast_check`, SPA a11y gates | Applied in UI work |
| **WordPress plugin standards** | WPCS, Plugin Check, wp.org guidelines | CI (`composer run ci:all`, PCP gate) | Enforced in CI |

The pattern to keep: **standards enter through a named artifact**, not through adjectives. If a statement cannot point at a file, a suite or a command, it does not go in external copy.

---

## 12. Roadmap

| Phase | Work | Exit criterion |
|---|---|---|
| **P0 — Adopt (now)** | D-1/D-2; this doc as the single source of mission copy; replace competing formulations in README/About/deck | One formulation in circulation |
| **P1 — Parity** | §8.1 contract parity + exclusion list | 45/45 or declared; shared suites green |
| **P2 — Publish** | Host contract v1 + conformance suite; "platform" unlocked (D-4) | External doc set updated once, not per-host |
| **P3 — Close the non-claims** | Out-of-process tool execution; workflow checkpoint/resume | R1/R2 retired with evidence |
| **P4 — Network** | Third host or third-party toolkits against the published contract; registry/gateway distribution | First external implementer |

---

## 13. Adoption (where this copy goes)

| Surface | What to use | Notes |
|---|---|---|
| `README.md` | §1.2 Product register + §5 one-liner | Do not paste the executive summary |
| `readme.txt` (wp.org) | §1.2 Product register, WordPress-first framing | Keep counts out; link to docs |
| Sales page / deck | §2 executive summary + §4 layer diagram | Numbers must cite §4 |
| `AGENTS.md` / contributor docs | §1.2 Engineering register + §1.4 principles | These are already the working rules |
| Docs index | §3 category ladder + §8 parity definition | Keeps research and product aligned |
| Legal / compliance copy | §11 with the wording rule | Legal checklist is the gate |

Change control: edit this document first, then propagate. If a downstream surface needs a shorter form, add it to Appendix A rather than inventing one.

---

## Appendix A — Copy ladder

**10 words**
> An agent runtime you own, on the host you choose.

**25 words**
> NV oOS is a host-neutral agent runtime. Run assistants, tools, workflows and memory in one governed estate — inside WordPress or as a standalone service.

**50 words**
> NV oOS gives organisations an agent estate they own. One runtime holds the assistants, tools, workflows, schedules and memory; hosts supply WordPress or a standalone Laravel service; policy, cost and audit apply everywhere. Move the estate without rebuilding it, and keep the tools that reflect how the business actually works.

**100 words (deck opener)**
> Every organisation is accumulating agents, and most of them are trapped: locked to one application, ungoverned at the boundary, and impossible to move. NV oOS is an agent runtime built the other way round. A host-neutral kernel holds orchestration, provider routing, workflows, MCP transport and a declared tool manifest. Two hosts implement the same contract — a WordPress plugin where the data already lives, and a standalone multi-tenant Laravel service with real queueing, scheduling and audit. One console drives both. The unit of product is an estate you own: governed by policy, measured by cost, exportable by design.

**Taglines**
- The agent runtime you own.
- One runtime, any host.
- Agents, tools and policy in one estate.
- Your estate, not their platform.

**Do not say**
> "AI operating system" · "agent OS" · "coding agent" · "just a plugin" · "fully sandboxed" · "durable execution" · "ISO 42001 certified" · "EU AI Act compliant" · "unlimited tools"

---

## Appendix B — Glossary (use exactly)

| Term | Meaning | Never confuse with |
|---|---|---|
| **Kernel** | `lib/core` + domain contracts; host-neutral | The plugin |
| **Host** | Environment implementing the contracts (WordPress, Laravel) | The product |
| **Estate** | Tenant-scoped runtime instance and its residents | A site, a workspace |
| **Operator** | Party administering estates (`/ops`) | A site administrator |
| **Resident** | An assistant/agent living in an estate | A chat session |
| **Workload** | Tools, toolkits, skills, workflows a resident can use | The runtime |
| **Harness** | The assembled agent loop around a model | The runtime |
| **Sandbox** | Isolation of model-written code (not claimed) | Policy gating |
| **Parity** | Contract equivalence + declared exclusions | Feature-for-feature cloning |

---

## Appendix C — Sources (retrieved 2026-11-17)

Secondary sources, used for definitions and drafting practice only — not as evidence about our own system.

| # | Source | Proposition used |
|---|---|---|
| S1 | Restate — *What Is an Agent Runtime? Framework vs. Harness* — https://restate.dev/what-is-an-agent-runtime | Framework builds an agent, harness runs the loop, runtime runs/manages it in production incl. isolation concerns |
| S2 | Orca Security — *Best AI Agent Runtime Tools & Platforms 2026* — https://orca.security/resources/blog/best-ai-agent-runtime-tools-platforms/ | The 2026 market has settled into distinct runtime layers, separate from framework and model |
| S3 | CamelAI — *Agent Harness vs Framework vs Runtime vs Sandbox* — https://camelai.com/blog/agent-harness-vs-framework-vs-runtime | Framework / harness / runtime / sandbox are four different things; runtime keeps agents alive over time |
| S4 | Arize — *AI agent frameworks compared* — https://arize.com/guides/ai-agent-handbook/agent-frameworks | Framework = primitives; harness = execution loop, context, tools, permissions, persistence |
| S5 | Agentspan — *Best AI Agent Runtime Platforms 2026* — https://agentspan.ai/blogs/best-ai-agent-runtime-platforms-2026/ | Runtime platforms decompose into Runtime, Gateway, Memory, Identity, Policy, Observability |
| S6 | Zeffy — *How to Write a Nonprofit Mission Statement* — https://www.zeffy.com/blog/the-ultimate-guide-to-effective-nonprofit-mission-statements | Mission = 1–2 sentences: why the organisation exists, who it serves, how |
| S7 | Blackbaud — *How to Craft a Mission Statement That Inspires* — https://blog.blackbaud.com/mission-statement-inspiration/ | Name who is served and make the change visible |
| S8 | Beacon Nonprofit — *Mission Statement Guide* — https://www.beaconnonprofit.com/blog/how-to-write-a-nonprofit-mission-statement/ | Who you serve + what you do + result, in plain sentences |
| S9 | Anedot — *21 Mission Statement Examples* — https://www.anedot.com/blog/mission-statement-examples | Back the statement with one concrete number *outside* the statement |
| S10 | The Nonprofit Prof — *Mission, Vision, Values* — https://www.thenonprofitprof.com/blog/vision | Mission should be simple, clear, accurate, inspirational; mission ≠ vision |

Internal sources are cited inline by path and proposal number throughout.

---

## Appendix D — Verification log

| Claim | Command / file | Verified |
|---|---|---|
| 45 endpoint keys; 39 true / 6 false; `endpoint_gaps: []` | `php artisan tinker --execute='$m=App\Support\EndpointCapabilities::map(); …'`; `contracts/console-endpoints.json` | 2026-11-16 (149) |
| 223 tool-readiness classes | `lib/core/resources/tool-readiness.php` | 2026-11-16 (090-m) |
| 244 admin page classes (85 + 159) | `includes/admin/`, `addons/pro/includes/admin/` | 2026-11-16 (149) |
| Two hosts implement the same contracts | `lib/wordpress-adapter/src/Adapter/`, `lib/laravel-adapter/src/Adapter/` | 2026-11-17 |
| Real process topology | `docker/docker-compose.laravel.yml` (`oos-laravel-app|worker|scheduler|db|redis`) | 2026-11-13 (skill) |
| 12 security classes | `includes/security/` | 2026-11-17 |
| Commercial split | 149 D-1/D-5 | 2026-11-16 |
