# Proposal: NV oOS MCP Registry & Directory Publishing

**Date:** 2026-10-08
**Status:** 🔮 PENDING
**Estimated Effort:** 10–14 hours (2 weeks, mostly CI + copywriting)
**Priority:** MEDIUM

> Scope: publish NV oOS's MCP surfaces to the **official MCP Registry**
> (`registry.modelcontextprotocol.io`) and the key community directories
> (mcpservers.org, awesome-mcp-servers, and the registry-crawling directories
> that pick us up automatically). Primary artifact: the already-published
> `@nvdigitalsolutions/nvoos-mcp-bridge` npm package, listed as a stdio
> server. Secondary artifacts (fleet gateway remotes) are **deferred** until a
> publicly reachable, non-auth-gated demo exists.
>
> Related in-repo work: `054-nvoos-mcp-bridge-npx-implementation-plan.md`,
> `055-nvoos-mcp-gateway-proposal.md`,
> `mcp-server-directory-addon-proposal.md` (the *internal* directory — this
> proposal is its external counterpart).

---

## 1. Executive Summary

NV oOS ships production-grade MCP surfaces — every site exposes a JSON-RPC 2.0
MCP endpoint at `https://<site>/wp-json/mcp-ai/v1/mcp` (~1,650 tools base+Pro),
the `@nvdigitalsolutions/nvoos-mcp-bridge` npx package relays stdio clients
(Zed / Claude Desktop / Cursor / Codex) to it, and two gateway addons
(`addons/mcp-gateway/`, `addons/mcp-wordpress-gateway/`) serve Streamable HTTP.
None of this is discoverable through the MCP ecosystem's discovery
infrastructure today.

This proposal covers publishing `nvoos-mcp-bridge` to the official MCP
Registry as a stdio npm package under the verified namespace
`io.github.nvdigitalsolutions/`, then submitting the same package to the two
manual community directories (mcpservers.org, awesome-mcp-servers). The
registry-first strategy is deliberate: the major directories (Glama,
PulseMCP, mcp.directory, Smithery's crawler) poll the registry, so one
authoritative `server.json` publish propagates to most of the ecosystem for
free.

**Outcome:** NV oOS appears in the registry API and in ≥3 community
directories, discoverable by hosts, aggregators, and users researching
WordPress MCP tooling; the npm package gains ecosystem trust via namespace
verification and provenance.

---

## 2. Problem Statement

1. **Zero ecosystem presence.** Searching the registry and the directories
   for WordPress MCP servers surfaces competitors (docdyhr/mcp-wordpress,
   WordPress.com's official server, third-party bridges) but not NV oOS,
   despite NV oOS exposing the largest WordPress tool surface of any of them.
2. **The bridge package is published but orphaned.** `nvoos-mcp-bridge` has
   been on npm since 2026-10-05 (`latest` = `0.1.0-alpha.3`) with zero
   downstream discovery surface. npm alone is not a discovery channel for MCP
   hosts — the registry API is.
3. **The gateway deployments cannot be listed as-is.** Both gateway addons
   are API-key-gated, per-deployment instances. The official registry
   explicitly excludes private/auth-gated remote servers, and a listing would
   401 for every consumer. We need the *installable package* path instead.
4. **Missed adoption funnel.** Docs-driven adoption (READMEs, Fleet Operator
   config blocks) works for existing users but cannot reach users who
   discover tooling through directories. Every competitor-adjacent search is
   a lost trial.

---

## 3. Research: Industry Standards & Best Practices

### 3.1 Registry vs directory — two layers

| Layer | Examples | Character | Consumption |
|---|---|---|---|
| **Registry** (machine-readable, authoritative metadata) | Official MCP Registry (`registry.modelcontextprotocol.io`) | REST API, `server.json`, namespace-verified, self-serve publish | Hosts, subregistries, aggregators (recommended polling: 1×/hour) |
| **Directories** (human-browsable, curated) | mcpservers.org, mcp.so, Smithery, Glama, PulseMCP, mcp.directory | Search/browse UI, categories, ratings, optional paid tiers | People researching servers |

The official registry FAQ is explicit: it is "primarily designed for
programmatic consumption by subregistries (Smithery, PulseMCP, Docker Hub,
Anthropic, GitHub, etc.)", not end users. The two layers are complementary:
**publish to the registry first; directories follow.**

### 3.2 Registry-first propagation (verified community evidence)

Multiple 2026 industry guides confirm the registry is the hub:

- "Publish here first, because many of the directories below crawl it: we
  found ourselves already listed on Glama, mcp.directory and PulseMCP without
  doing anything, purely because we were in the registry."
  ([Linkly](https://linklyhq.com/blog/mcp-server-directories))
- "Many clients and tools read it as the source feed, so a record there tends
  to propagate to the discovery directories."
  ([Tallyfy](https://tallyfy.com/how-to-list-mcp-server-registry-smithery-glama-pulsemcp/))
- A common industry recommendation: **submit to ~4 surfaces total** — the
  official registry, mcp.so, Smithery, Glama, and open a PR against
  `punkpeye/awesome-mcp-servers`
  ([RoxyAPI](https://roxyapi.com/blogs/mcp-registries-where-to-list-your-server)).

### 3.3 Official registry mechanics (verified against registry docs, Oct 2026)

- **Metadata only.** The registry stores `server.json`; artifacts live on
  allowlisted public registries only: `registry.npmjs.org`, `pypi.org`,
  `api.nuget.org`, `crates.io`, Docker Hub, GHCR, Quay.io, GAR, ACR, MCR,
  and GitHub/GitLab releases for MCPB. Private registries are rejected.
- **Namespace authentication.** Reverse-DNS names are ownership-verified:
  GitHub OAuth/OIDC → `io.github.<user|org>/<name>`; DNS TXT →
  `com.example.*`; HTTP file → `com.example/*`. We qualify for
  `io.github.nvdigitalsolutions/` via org membership.
- **Package ownership verification.** For npm, the published package.json
  must contain `"mcpName": "<registry name>"` matching `server.json`. This is
  the single hard blocker today — our package has no `mcpName`.
- **No human review queue.** Publishing is self-serve (`mcp-publisher init →
  login → publish`); governance is namespace verification + rate limits +
  manual takedown/moderation policy.
- **Validation limits.** `description` ≤ 100 chars; free-form fields have
  strict regex/character limits; `_meta` custom data is preserved **only**
  under `io.modelcontextprotocol.registry/publisher-provided` (≤ 4 KB);
  version metadata is immutable once published.
- **Transport model.** npm/PyPI/NuGet/Cargo/OCI packages declare
  `transport.type: "stdio"` and are spawned by hosts via `npx`/`uvx`/`dnx`
  (`runtimeHint`). Public HTTP endpoints are declared via `remotes`
  (`streamable-http` or `sse`), with URL templating and header variables for
  multi-tenant setups.
- **Private servers excluded.** "Servers published on a private network or on
  private package registries… are generally not supported."
- **Preview status.** Launched 2025-09-08, still preview — breaking changes
  or data resets possible; community-maintained reliability (FAQ: expect up
  to 1 business day of downtime, no guarantees).

### 3.4 Directory landscape & submission norms (Oct 2026)

| Directory | Submission | Cost | Notes |
|---|---|---|---|
| **Official MCP Registry** | `mcp-publisher` CLI (GitHub/DNS/HTTP auth) | Free | The hub; API-consumed |
| **mcpservers.org** | Web form (`/submit`): name, category, short description, repo URL, optional official-registry name, "supports remote connections" checkbox, contact email | Free (review ≤ 2 wks) or **$39 one-time premium** (24h review, badge, priority, dofollow link) | Manual listing; does not auto-crawl |
| **awesome-mcp-servers** (`punkpeye`) | GitHub PR following README style | Free | The most-starred community list; PR review required |
| **mcp.so** | Submission + optional **$39 one-time** (dofollow + badge) | Free/$39 | High-traffic directory |
| **Smithery** | `manifest.json` vendor checklist + deploy config | Free | "Docker Hub of MCP"; also enables one-click deploys |
| **Glama** | Auto-lists from registry; ownership claim via well-known file | Free | Registry crawler |
| **PulseMCP** | Crawls registry (manual submissions reportedly paused) | Free | Registry crawler |
| **mcp.directory** | Auto-imports from registry; owner "claims" entry | Free | Registry crawler |

Sources: [MentionAgent](https://mentionagent.ai/blog/mcp-server-directories/),
[DYNO Mapper](https://dynomapper.com/blog/ai/mcp-server-directories/),
[Geekflare](https://geekflare.com/guides/mcp-server-directories/),
[Skiln](https://skiln.co/blog/best-mcp-directories-2026/),
[TrueFoundry](https://www.truefoundry.com/blog/best-mcp-registries/), plus the
live mcpservers.org submit form (fetched 2026-10-08).

### 3.5 Security & trust best practices for listings

- **Secrets are environment variables, never arguments.** Registry metadata
  declares them with `isSecret: true`; tokens in `args`/URLs leak into process
  listings and client configs. Our bridge already enforces this (`MCP_AI_TOKEN`
  env-only) — the listing must too.
- **Metadata accuracy is a safety property.** "Review server.json command
  arguments, environment variables, headers, remotes, and URL template
  variables before publishing because clients may turn that metadata into
  executable install or connection steps" ([HeyClaude](https://heyclau.de/entry/guides/publishing-an-mcp-server-to-the-official-registry)).
- **The registry verifies provenance, not safety.** Namespace/package
  verification proves control, not that code is safe; security scanning is
  delegated to npm/PyPI + subregistries. Our npm provenance attestations
  (already enabled in `npm-publish-nvoos-mcp-bridge.yml`) are the trust anchor.
- **Publish the artifact before the metadata**, and match versions exactly —
  mismatches confuse hosts and aggregators.

### 3.6 Future-proofing standards (emerging SEPs)

- **SEP-1649 (Server Cards)**: structured metadata at
  `/.well-known/mcp/server-card.json` per server
  ([issue #1649](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1649)).
- **SEP-1960 (Endpoint enumeration)**: `/.well-known/mcp.json` for
  transport/auth discovery
  ([issue #1960](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1960)).
- Both are cheap to add later to the plugin and the gateways; tracked as
  deferred items, not part of this proposal's core scope.

---

## 4. Proposed Solution

### 4.1 Phase 1 — Unblock the official registry (the `mcpName` marker)

1. Add `"mcpName": "io.github.nvdigitalsolutions/nvoos-mcp-bridge"` to
   `packages/nvoos-mcp-bridge/package.json` (per the registry's npm ownership
   verification requirement).
2. Bump to `0.1.0-alpha.4` and publish through the existing
   `npm-publish-nvoos-mcp-bridge.yml` workflow (provenance already enabled).
   The current `latest` (alpha.3) lacks `mcpName` and would fail registry
   validation — never register that version.
3. Verify the marker via the npm registry API.

### 4.2 Phase 2 — `server.json` + first registry publish

1. Commit `server.json` to `packages/nvoos-mcp-bridge/` (draft in
   Appendix A) so metadata is versioned with the code it describes.
2. Install `mcp-publisher` (prebuilt binary or Homebrew).
3. `mcp-publisher login github` — device-flow OAuth by an
   `nvdigitalsolutions` GitHub org member (namespace requirement).
4. `mcp-publisher validate`, then `mcp-publisher publish`.
5. Verify: `curl "https://registry.modelcontextprotocol.io/v0.1/servers?search=io.github.nvdigitalsolutions/nvoos-mcp-bridge"`.

**Transport choice — `stdio` (correct for this package):** the registry's npm
package type is stdio by definition; hosts spawn
`npx -y @nvdigitalsolutions/nvoos-mcp-bridge@latest`, which resolves the
primary bin and behaves as a compliant stdio MCP server (JSON-RPC per stdin
line, stdout-only responses, diagnostics on stderr). The env-driven config
(`MCP_AI_BASE_URL`, `MCP_AI_TOKEN`) is declared as registry
`environmentVariables`, so hosts surface proper config UIs.

**Honest positioning:** the bridge is a *transport relay*, not a tool-bearing
server — tools come from the target NV oOS site. The description must say
"stdio relay … to NV oOS WordPress sites" so moderators and users don't flag
it as misrepresented. This is an accepted pattern (transport adapters exist in
the registry ecosystem); the fallback if moderation objects is documented in
§6.

### 4.3 Phase 3 — Automate with CI (GitHub OIDC)

1. Add a registry-publish step to `npm-publish-nvoos-mcp-bridge.yml` (or a
   sibling `mcp-registry-publish.yml`) using GitHub **OIDC** authentication
   (supported by the registry; FAQ-confirmed for Actions workflows).
2. Gates, mirroring the npm job: version format, `bin/` sync drift check,
   tests, `npm pack --dry-run`, then `mcp-publisher publish` with the
   matching version; registry versions track npm releases 1:1.
3. Keep a maintainer-local fallback runbook (device-flow) for emergencies.

### 4.4 Phase 4 — Community directories

| Action | Surface | Cost |
|---|---|---|
| Submit form (draft copy in Appendix B) | mcpservers.org | Free tier first; premium $39 optional, decision-gated |
| PR to `punkpeye/awesome-mcp-servers` (Appendix C) | awesome list | Free |
| Verify auto-propagation; claim entries where supported | Glama, PulseMCP, mcp.directory | Free |
| Optional: `manifest.json` for one-click deploy | Smithery | Free (deferred to P2) |
| Optional: well-known ownership file if a manual Glama claim is needed | Glama | Free (deferred) |

### 4.5 Phase 5 — Governance & upkeep runbook

- **Version policy:** registry publishes are 1:1 with npm releases; metadata
  is immutable per version; a bad publish requires a new version, so
  `validate` always precedes `publish` in CI.
- **Publisher credentials:** OIDC in CI (recommended) + one named maintainer
  with device-flow access as backup; both require GitHub org membership.
- **Monitoring:** monthly check that the entry is `active`/`latest` and that
  directory listings still resolve; react to registry preview changes (data
  resets are possible).
- **Deprecation path:** if the bridge is superseded, publish a final version
  and set status via `mcp-publisher status` rather than leaving stale
  metadata.

### 4.6 Explicitly out of scope (this proposal)

- Listing the deployed `addons/mcp-wordpress-gateway/` instances as
  `remotes` — per-deployment, API-key-gated, no public endpoint; the
  registry excludes private remote servers. The **fleet gateway**
  (`addons/mcp-gateway/` at `mcp.nvoos.pro`) is a different case: its
  endpoint is live, publicly reachable, and bearer-key auth fits the
  registry's `headers`/`isSecret` model, so a `remotes` draft landed with
  the implementation plan (`addons/mcp-gateway/server.json`, Appendix E).
  Publishing it is decision **D6**, pending the `ideabits` upstream-401 fix
  and the public key-issuance story.
- Claiming a DNS namespace (`com.nvdigitalsolutions.*`) — unnecessary until
  we host custom-domain remote servers.
- Paid tiers ($39 × mcpservers.org/mcp.so) — decision-gated, see §8.
- Registering the WordPress plugin itself — distributed via wp.org, which is
  not an MCP registry package type; the bridge is the correct registry
  artifact.

---

## 5. Benefits

1. **Discoverability funnel** — one verified registry entry propagates to
   Glama/PulseMCP/mcp.directory automatically; manual listings add
   mcpservers.org + the awesome list; every WordPress-MCP search now surfaces
   NV oOS alongside (and above) docdyhr/mcp-wordpress and WordPress.com's
   server.
2. **Ecosystem trust** — namespace verification (`io.github.nvdigitalsolutions/`)
   + npm provenance attestations position the bridge as a first-party,
   auditable artifact rather than an anonymous package.
3. **Adoption path** — hosts that consume the registry can install the bridge
   with real config UIs (env var declarations); the Fleet Operator's
   copy-paste blocks get a second, registry-driven acquisition channel.
4. **SEO/backlinks** — directory listings link the repo (dofollow on paid
   tiers); the docs hub and plugin pages inherit authority.
5. **Cheap to maintain** — CI-coupled publishing means near-zero recurring
   effort after Phase 3.

---

## 6. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Registry is in preview; data resets/breaking changes | Medium | Metadata lives in-repo (`server.json`); re-publish is one CI command |
| Moderation flags the bridge as "not a server" (relay semantics) | Low | Honest description wording; precedent of transport adapters; fallback: publish the fleet gateway as a `remotes` entry once a public demo exists |
| `io.github.nvdigitalsolutions/` namespace blocked (org membership, 2FA) | Low | Publisher runs as org member; OIDC in Actions is org-scoped |
| Version drift npm ↔ registry (alpha tags) | Medium | CI couples both publishes to one version resolution; registry version = npm version, always |
| Credential exposure in metadata | Low | Tokens are env-only in both the package and `server.json`; `isSecret: true` |
| Registry downtime (community-maintained) | Medium | Directories cache; our docs remain the canonical install path |
| mcpservers.org review delay (≤ 2 weeks free tier) | Medium | Submit early; premium tier exists as an escape hatch (decision-gated) |

---

## 7. Implementation Plan

| Phase | Tasks | Est. |
|---|---|---|
| 0 — Preflight | Confirm GitHub org membership for publisher; confirm npm scope ownership; snapshot current directory landscape (baseline screenshots) | 0.5 h |
| 1 — `mcpName` + release | package.json marker, bump, CI publish alpha.4, npm-registry verification | 1 h |
| 2 — First registry publish | Commit `server.json`; `mcp-publisher login/validate/publish`; API verification | 2 h |
| 3 — CI automation | OIDC wiring + gates + runbook doc | 3 h |
| 4 — Directories | mcpservers.org form; awesome-mcp-servers PR; propagation checks + claims | 2.5 h |
| 5 — Ops & docs | Monitoring checklist, version policy doc, cross-link from `mcp-ai-wpoos-plugin` skill + docs hub | 2 h |
| Optional P2 | Smithery manifest; Glama well-known file; premium tiers | 3 h |

**Total: ~11 h core, +3 h optional.**

---

## 8. Effort Estimation

- **People:** one maintainer with GitHub org access; no new hires.
- **Dependencies:** none new — `mcp-publisher` binary (download, not a repo
  dep); GitHub OIDC (already used by existing workflows).
- **Cost:** $0 core. Optional: $39 mcpservers.org premium, $39 mcp.so
  premium.
- **Timeline:** 2 weeks wall-clock (directory review queues dominate).

---

## 9. Success Metrics

1. Registry entry `io.github.nvdigitalsolutions/nvoos-mcp-bridge` live,
   searchable via API, marked `active`/`latest` — within 1 week of approval.
2. Propagation to ≥2 registry-crawling directories (Glama, PulseMCP,
   mcp.directory) within 30 days — zero marginal work.
3. Manual listings live: mcpservers.org + awesome-mcp-servers merged.
4. npm downloads (weekly) for the bridge measurably above the pre-listing
   baseline within 60 days.
5. Zero credential/secret leaks in any published metadata (audit via the
   registry API + directory pages).
6. CI registry publish works repeatably (2 consecutive releases without
   manual intervention).

---

## 10. Decision Required

1. **Approve** publishing under the public namespace
   `io.github.nvdigitalsolutions/nvoos-mcp-bridge` (implies GitHub org
   membership for the publisher and acceptance of the registry's moderation
   policy).
2. **Approve** the listing copy in Appendices A–C (name, description,
   category, wording).
3. **Choose** free vs premium tiers on mcpservers.org (and optionally
   mcp.so) — $39 one-time each, dofollow link + badge + faster review.
4. **Approve** OIDC-based automated registry publishing in CI, with
   maintainer device-flow as backup.
5. **Confirm** the honest-relay positioning of the bridge description (vs
   deferring until a tool-bearing server entry — e.g., the fleet gateway —
   exists).
6. **Approve** the gateway `remotes` entry —
   `io.github.nvdigitalsolutions/nvoos-mcp-gateway` for
   `https://mcp.nvoos.pro/mcp` (draft: `addons/mcp-gateway/server.json`,
   Appendix E) — including the public key-issuance expectation, and fix the
   `ideabits` upstream 401 before publishing.

---

## Appendix A — Draft `server.json`

Committed at `packages/nvoos-mcp-bridge/server.json`:

```json
{
  "$schema": "https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json",
  "name": "io.github.nvdigitalsolutions/nvoos-mcp-bridge",
  "title": "NV oOS MCP Bridge",
  "description": "Stdio relay connecting Zed, Claude Desktop, Cursor and Codex to NV oOS WordPress MCP endpoints.",
  "websiteUrl": "https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/packages/nvoos-mcp-bridge",
  "repository": {
    "url": "https://github.com/nvdigitalsolutions/mcp-ai-wpoos",
    "source": "github",
    "subfolder": "packages/nvoos-mcp-bridge"
  },
  "version": "0.1.0-alpha.4",
  "packages": [
    {
      "registryType": "npm",
      "registryBaseUrl": "https://registry.npmjs.org",
      "identifier": "@nvdigitalsolutions/nvoos-mcp-bridge",
      "version": "0.1.0-alpha.4",
      "runtimeHint": "npx",
      "transport": { "type": "stdio" },
      "environmentVariables": [
        {
          "name": "MCP_AI_BASE_URL",
          "description": "Full URL of the NV oOS MCP endpoint (https://<site>/wp-json/mcp-ai/v1/mcp)",
          "isRequired": true
        },
        {
          "name": "MCP_AI_TOKEN",
          "description": "Credential (cred_*) or operator (op_*) token minted in Fleet Operator",
          "isRequired": false,
          "isSecret": true
        },
        {
          "name": "MCP_AI_HTTP_TIMEOUT",
          "description": "Request timeout in milliseconds",
          "default": "120000"
        }
      ]
    }
  ],
  "_meta": {
    "io.modelcontextprotocol.registry/publisher-provided": {
      "tool": "npm-publish-nvoos-mcp-bridge",
      "version": "1.0.0"
    }
  }
}
```

Notes: description is 95 chars (limit 100). Version must equal the npm
version that carries `mcpName`. The SSH variant (`nvoos-mcp-ssh` bin) is
deliberately not declared — its env contract (SSH host/user/keys) is
documented in the README; a second server entry can be added later if
demand appears.

## Appendix B — Draft mcpservers.org submission

| Field | Value |
|---|---|
| Server Name | NV oOS MCP Bridge |
| Category | Development |
| Short Description | Zero-dependency stdio relay exposing 1,600+ WordPress management tools from any NV oOS site to Zed, Claude Desktop, Cursor and Codex. |
| Repository / Website / Docs | `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/packages/nvoos-mcp-bridge` |
| Official MCP Registry Name (optional) | `io.github.nvdigitalsolutions/nvoos-mcp-bridge` (post-Phase 2) |
| Supports remote connections | ✅ (relays to the site's Streamable HTTP `/mcp` endpoint) |
| Contact Email | maintainer address |

## Appendix C — Draft awesome-mcp-servers PR entry

```markdown
- [NV oOS MCP Bridge](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/packages/nvoos-mcp-bridge) - Zero-dependency stdio↔HTTP relay exposing 1,600+ WordPress management tools from any NV oOS site to Zed, Claude Desktop, Cursor and Codex.
```

(Placement in the WordPress/Cloud Platforms section per the list's current
README structure; PR must follow its contribution guidelines.)

## Appendix E — Draft `server.json` (fleet gateway, remotes entry)

Committed at `addons/mcp-gateway/server.json`:

```json
{
  "$schema": "https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json",
  "name": "io.github.nvdigitalsolutions/nvoos-mcp-gateway",
  "title": "NV oOS Gateway",
  "description": "Streamable HTTP MCP gateway exposing your NV oOS WordPress fleet behind one API key.",
  "websiteUrl": "https://mcp.nvoos.pro/",
  "repository": {
    "url": "https://github.com/nvdigitalsolutions/mcp-ai-wpoos",
    "source": "github",
    "subfolder": "addons/mcp-gateway"
  },
  "version": "0.1.1",
  "remotes": [
    {
      "type": "streamable-http",
      "url": "https://mcp.nvoos.pro/mcp",
      "headers": [
        {
          "name": "Authorization",
          "description": "Gateway API key issued by NV Digital Solutions, bound to one or more site slugs. Send as \"Authorization: Bearer <key>\".",
          "isRequired": true,
          "isSecret": true
        }
      ]
    }
  ],
  "_meta": {
    "io.modelcontextprotocol.registry/publisher-provided": {
      "tool": "mcp-publisher",
      "version": "1.0.0"
    }
  }
}
```

Notes: remote-only entries need no `packages`/ownership marker (registry
remote-servers doc); the URL is verified live (protocol 2026-07-28, bearer
auth, `GET /` landing page for reviewers); **each remote URL can be claimed
by only one server name** — publish under this name only; description is 84
chars (limit 100); registry version aligned to the live gateway version
(0.1.1). The actual API key is never embedded — hosts surface the declared
secret header and users supply their own key.

## Appendix D — Sources

- Official registry: <https://registry.modelcontextprotocol.io/docs> ·
  <https://github.com/modelcontextprotocol/registry>
- Registry announcement (2025-09-08):
  <https://blog.modelcontextprotocol.io/posts/2025-09-08-mcp-registry-preview/>
- About the MCP Registry: <https://modelcontextprotocol.io/registry/about>
- Registry FAQ: <https://modelcontextprotocol.info/tools/registry/faq/>
- Publishing quickstart (mcp-publisher):
  <https://github.com/modelcontextprotocol/registry/blob/main/docs/modelcontextprotocol-io/quickstart.mdx>
- server.json spec:
  <https://github.com/modelcontextprotocol/registry/blob/main/docs/reference/server-json/generic-server-json.md>
- Official registry requirements (`mcpName`, `_meta`, allowlist):
  <https://github.com/modelcontextprotocol/registry/blob/main/docs/reference/server-json/official-registry-requirements.md>
- Directories landscape: [MentionAgent](https://mentionagent.ai/blog/mcp-server-directories/) ·
  [DYNO Mapper](https://dynomapper.com/blog/ai/mcp-server-directories/) ·
  [Geekflare](https://geekflare.com/guides/mcp-server-directories/) ·
  [Skiln](https://skiln.co/blog/best-mcp-directories-2026/) ·
  [RoxyAPI](https://roxyapi.com/blogs/mcp-registries-where-to-list-your-server) ·
  [Linkly](https://linklyhq.com/blog/mcp-server-directories) ·
  [TrueFoundry](https://www.truefoundry.com/blog/best-mcp-registries/) ·
  [Tallyfy](https://tallyfy.com/how-to-list-mcp-server-registry-smithery-glama-pulsemcp/) ·
  [HeyClaude](https://heyclau.de/entry/guides/publishing-an-mcp-server-to-the-official-registry)
- mcpservers.org submit form (live, fetched 2026-10-08):
  <https://mcpservers.org/submit>
- SEP-1649: <https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1649> ·
  SEP-1960: <https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1960>
