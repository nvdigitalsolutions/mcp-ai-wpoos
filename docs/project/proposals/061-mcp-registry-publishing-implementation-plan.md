# NV oOS MCP Registry & Directory Publishing — Comprehensive Implementation Plan

**Date:** 2026-10-08
**Status:** 🔮 PENDING — Phase 1 + Phase 2 executed 2026-10-08 (npm 0.1.0-alpha.4 live with the `mcpName` marker); Phases 3–6 gated on §Decision Gates
**Estimated Effort:** 10–14 hours core + 3 h optional (Phases 0–6)
**Priority:** MEDIUM
**Related:** [061-mcp-registry-publishing-proposal.md](./061-mcp-registry-publishing-proposal.md), [054-nvoos-mcp-bridge-npx-implementation-plan.md](./054-nvoos-mcp-bridge-npx-implementation-plan.md), [055-nvoos-mcp-gateway-proposal.md](./055-nvoos-mcp-gateway-proposal.md), [mcp-server-directory-addon-proposal.md](./mcp-server-directory-addon-proposal.md), `.github/workflows/npm-publish-nvoos-mcp-bridge.yml`, `packages/nvoos-mcp-bridge/`

---

## Executive Summary

Publish the shipped `@nvdigitalsolutions/nvoos-mcp-bridge` npx package to
the **official MCP Registry** (`registry.modelcontextprotocol.io`) as a stdio
npm server under the verified namespace
`io.github.nvdigitalsolutions/nvoos-mcp-bridge`, then submit it to the two
manual community directories (mcpservers.org, awesome-mcp-servers) and let
the registry-crawling directories (Glama, PulseMCP, mcp.directory) pick it
up automatically.

All research, rationale, and the full `server.json` draft live in the
proposal; this document is the phase-by-phase execution, verification, and
rollback plan. Two changes are already landed with this plan (Phase 1, marked
`[x]`): the `mcpName` ownership marker and the `server.json` metadata file.
Everything that touches the public npm registry, the MCP Registry, or
third-party directories is **gated on the §Decision Gates approvals** and
executed by a maintainer with org credentials.

---

## Phase 0 — Preflight & credential readiness (0.5 h)

- [ ] **GitHub org membership** — the publishing human must be a member of
      the `nvdigitalsolutions` org (registry namespace requirement for
      `io.github.nvdigitalsolutions/*`). Check: org → People.
- [ ] **npm maintainer access** — confirm the org owner/membership covers
      `@nvdigitalsolutions/nvoos-mcp-bridge` (the existing publish workflow's
      `NPM_TOKEN` already proves publish rights; this is a belt-and-braces
      check).
- [ ] **Baseline snapshot** (evidence for success metric 5):
  - `curl "https://registry.modelcontextprotocol.io/v0.1/servers?search=nvoos"` → expected `servers: []`
  - mcpservers.org search "nvoos" → screenshot
  - Glama / PulseMCP / mcp.directory searches "nvoos" → screenshots
  - npm weekly downloads baseline for the bridge (`npm view @nvdigitalsolutions/nvoos-mcp-bridge` → note current counts if available)
- [ ] **Confirm preview caveats** — re-check the registry docs for any
      breaking changes since 2026-10-08 before the first publish.

## Phase 1 — Repo-side prerequisites (done with this plan)

- [x] `packages/nvoos-mcp-bridge/package.json` — added
      `"mcpName": "io.github.nvdigitalsolutions/nvoos-mcp-bridge"` (the npm
      ownership-verification marker; registry validation rejects publishes
      whose referenced package version lacks it).
- [x] `packages/nvoos-mcp-bridge/server.json` — committed (proposal
      Appendix A): name/title/description (95 chars, limit 100),
      `repository.subfolder`, `packages[0]` npm stdio with `runtimeHint: npx`
      and env declarations (`MCP_AI_BASE_URL` required; `MCP_AI_TOKEN`
      `isSecret`; `MCP_AI_HTTP_TIMEOUT` defaulted), `_meta` under the
      publisher-provided key only.
- [x] `packages/nvoos-mcp-bridge/README.md` — added the "Ecosystem
      discovery" section pointing at the registry record and the
      `server.json` file.
- [x] `docs/project/proposals/README.md` — 061 entry under "Currently In
      Progress" + "Individual Proposals & Research"; date bumped.
- [ ] **Deliberate exclusions (verified, do not change):**
  - `server.json` is **not** added to the npm `files` array — it is registry
    metadata, not package content; the registry only requires `mcpName`
    inside the published package.
  - `package.json` version left at `0.1.0-alpha.3` — the publish workflow
    sets the version at release time; the marker ships with the next release.

## Phase 2 — npm release carrying the marker (1 h) — **EXECUTED 2026-10-08**

The current `latest` (alpha.3) predates `mcpName` and would fail registry
validation — **never register alpha.3**.

**Two workflow nuances discovered during execution (plan corrections):**

- The tag trigger is `v*.*.*-mcpbridge.*`, **not** plain `v*.*.*` — the
  original "Option A" tag would never fire, and a matching tag would have
  produced the wrong npm version string (`0.1.0-mcpbridge.N`). The
  workflow_dispatch path (Option B) is the correct one for `alpha.*`
  versions.
- The repo's **default branch is `main`**; the marker commit lives on
  `alpha-working`. A dispatch without `--ref alpha-working` would have
  checked out `main`'s package.json (no marker) and published a bad
  release. Always pass `--ref alpha-working` (or the branch that carries
  the marker) until the commit lands on `main`.

Execution record:

- [x] Commit `f5b2d7f6bc` (→ rebased `62917e9714` on `origin/alpha-working`
      after a remote build commit landed): marker + `server.json` + docs.
- [x] `gh workflow run npm-publish-nvoos-mcp-bridge.yml --ref alpha-working
      -f version=0.1.0-alpha.4 -f tag=latest -f dry_run=false`
      → run [37735273749](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/runs/37735273749):
      all gates green, publish step succeeded,
      provenance in sigstore (logIndex 3142271471).
- [x] Verified on npm (after the registry's async processing window — the
      version 404s for a few minutes while npm "processes" provenance-signed
      publishes; poll with `--prefer-online`):
  - `npm view @nvdigitalsolutions/nvoos-mcp-bridge@0.1.0-alpha.4 version mcpName`
    → `version = '0.1.0-alpha.4'`, `mcpName = 'io.github.nvdigitalsolutions/nvoos-mcp-bridge'`
  - `npm view @nvdigitalsolutions/nvoos-mcp-bridge dist-tags --json`
    → `latest: 0.1.0-alpha.4`
- [x] Smoke against the **published** tarball (npx, local dev site
      `oos-wp` on `localhost:8000`): bin resolved, stdio↔HTTP relay
      round-tripped, the site's structured 401
      (`wp_mcp_ai_mcp_auth_required`) relayed transparently — transport
      verified end-to-end.
- [x] **Authed smoke — EXECUTED 2026-10-08 against the live fleet gateway**
      (`https://mcp.nvoos.pro/mcp`, bearer key). Direct-HTTP path: `/health`
      green (`design-mcp-gateway 0.1.1`, sites console/ideabits/sascha/demo
      ok), GET discovery (protocol 2026-07-28, bearer, streamable HTTP), POST
      `initialize` OK (`serverInfo: NV oOS Gateway 0.1.1`). Bridge path
      (`npx @nvdigitalsolutions/nvoos-mcp-bridge@0.1.0-alpha.4`): initialize
      + tools/list relayed correctly. **Finding:** the test key binds to site
      `ideabits`, whose upstream returns 401 —
      `_meta.gateway.errors.ideabits: "upstream error (401)"` — the gateway's
      stored operator token for `ideabits` must be re-minted in Fleet
      Operator and the Velocity env updated (gateway ops, not bridge).
- [ ] Gateway ops follow-up (owner): rotate the `ideabits` upstream `op_`
      token in the gateway env; re-run tools/list until `tools` is non-empty.
      Also rotate the public gateway key shared during testing
      (`GATEWAY_PUBLIC_KEYS_PREVIOUS` overlap).
- [ ] Registry opportunity (owner decision, gated on D1/D2):
      `mcp.nvoos.pro/mcp` is a public bearer-auth streamable-HTTP endpoint
      and is therefore eligible for a `remotes` entry with
      `headers: [{ name: "Authorization", isRequired: true, isSecret: true }]`
      — proposal §4.6 revisit. Candidate name:
      `io.github.nvdigitalsolutions/nvoos-mcp-gateway` (a second server
      entry, next to the bridge's stdio entry). Requires the gateway to be
      open to key issuance and the `ideabits` 401 fixed first.

## Phase 3 — First registry publish (manual, device-flow) (2 h)

- [ ] Install `mcp-publisher` (prebuilt binary). Windows (maintainer host):
  ```powershell
  $arch = if ([System.Runtime.InteropServices.RuntimeInformation]::ProcessArchitecture -eq "Arm64") { "arm64" } else { "amd64" }
  Invoke-WebRequest -Uri "https://github.com/modelcontextprotocol/registry/releases/latest/download/mcp-publisher_windows_$arch.tar.gz" -OutFile "mcp-publisher.tar.gz"
  tar xf mcp-publisher.tar.gz mcp-publisher.exe; rm mcp-publisher.tar.gz
  ```
  macOS/Linux: Homebrew (`brew install mcp-publisher`) or the curl+tar
  one-liner from the registry quickstart.
- [ ] `mcp-publisher login github` → device-code OAuth as the org member.
- [ ] **Version sync** — `server.json` must match the published npm version
      exactly (currently `0.1.0-alpha.4`). If the release version differs,
      update `version` (top-level) and `packages[0].version` first. One-liner:
  ```sh
  V=$(npm pkg get version --workspaces=false) # run inside packages/nvoos-mcp-bridge
  node -e "const fs=require('fs');const s=JSON.parse(fs.readFileSync('server.json','utf8'));s.version=process.argv[1];s.packages[0].version=process.argv[1];fs.writeFileSync('server.json',JSON.stringify(s,null,2)+'\n')" "$V"
  ```
- [ ] `mcp-publisher validate` — must pass before any publish (catches the
      100-char description limit, `_meta` restrictions, package marker
      mismatches).
- [ ] `mcp-publisher publish`.
- [ ] Verify:
  - `curl "https://registry.modelcontextprotocol.io/v0.1/servers?search=io.github.nvdigitalsolutions/nvoos-mcp-bridge"` → entry present, `_meta.status: active`, `isLatest: true`
  - `curl "https://registry.modelcontextprotocol.io/v0.1/servers/io.github.nvdigitalsolutions%2Fnvoos-mcp-bridge"` → metadata matches `server.json`
- [ ] Record the publish evidence (API JSON) in the Phase 0 baseline folder.

## Phase 4 — CI automation (GitHub OIDC) (3 h)

- [ ] Confirm the OIDC configuration shape against the registry's
      GitHub Actions guide at implementation time (FAQ confirms GitHub OIDC
      for Actions; the exact claim/token exchange is the one item not
      fully verified in this plan).
- [ ] Extend `.github/workflows/npm-publish-nvoos-mcp-bridge.yml` (or add a
      sibling `mcp-registry-publish.yml`) with a post-npm-publish job:
  1. `id-token: write` permission for OIDC.
  2. Resolve `VERSION` (same logic as the npm job).
  3. Version-sync `server.json` (Phase 3 one-liner) — committed values stay
     a placeholder; CI is authoritative.
  4. Download `mcp-publisher` binary (pinned release tag, checksum
     verification if published).
  5. `mcp-publisher login` (OIDC, non-interactive) → `mcp-publisher validate`
     → `mcp-publisher publish`.
  6. Run only when the npm publish step succeeded; `DRY_RUN` input skips both
     publishes but runs all gates.
- [ ] Two consecutive manual (or dry-run) passes before enabling on tag push.

## Phase 5 — Directory submissions (2.5 h)

| Surface | Action | Copy | Gating |
|---|---|---|---|
| mcpservers.org | Web form at `/submit` | Proposal Appendix B | D2, D3 |
| awesome-mcp-servers | PR to `punkpeye/awesome-mcp-servers` | Proposal Appendix C | D2 |
| Glama / PulseMCP / mcp.directory | Wait for registry propagation (1×/hour polling per registry design); claim entries where the directory supports owner-claims | — | — |
| mcp.so (optional) | Free listing; $39 tier optional | same short description | D2, D3 |
| Smithery (optional, P2) | `manifest.json` vendor checklist | — | D2 |

- [ ] mcpservers.org: submit free tier; track the ≤2-week review window.
- [ ] awesome-mcp-servers: open PR following the list's contribution rules;
      respond to review feedback within 48 h.
- [ ] Propagation check at +24 h / +7 d / +30 d: search each crawler for
      `nvoos`; file claims where supported.
- [ ] Keep a single canonical short description + category (Development)
      across every listing — consistent metadata is a discovery best
      practice and avoids review pushback.

## Phase 6 — Ops runbook & monitoring (2 h)

- [ ] Write the runbook (append to the proposal or
      `docs/operations/`): monthly checks —
  - registry entry `active` + `isLatest` + version == npm `latest`
  - directory listings resolve; no stale URLs
  - npm downloads trending vs Phase 0 baseline
  - registry preview announcements (GitHub repo releases/issues) reviewed
    for breaking changes
- [ ] Deprecation path: final version publish + `mcp-publisher status`
      (active → deprecated) — never leave stale metadata.
- [ ] Cross-link the registry entry from:
  - `packages/nvoos-mcp-bridge/README.md` (done)
  - the `mcp-ai-wpoos-plugin` skill release notes on the next version bump
    (updates-track convention)
  - docs hub / wpoos marketing pages (homepage field already points at
    `nvdigitalsolutions.com/wpoos`)
- [ ] Revisit deferred items from the proposal §4.6 at the monthly review:
      public demo remote for `addons/mcp-gateway/`; DNS namespace claim;
      SEP-1649 server-card.json.

---

## Verification matrix

| # | Gate | Command / evidence | Expected |
|---|---|---|---|
| V1 | Package JSON valid + marker | `node -e` JSON.parse + `mcpName` read (done locally) | marker == registry name |
| V2 | Drift gate | `npm run sync -- --check` in package dir (done locally) | exit 0 |
| V3 | Unit suite | `npm test` in package dir (done locally) | 0 failures |
| V4 | Marker on npm | `npm view @nvdigitalsolutions/nvoos-mcp-bridge@0.1.0-alpha.4 mcpName` | registry name |
| V5 | server.json valid | `mcp-publisher validate` | pass |
| V6 | Registry entry live | `curl …/v0.1/servers?search=io.github.nvdigitalsolutions%2Fnvoos-mcp-bridge` | `servers[0]` present, active |
| V7 | Propagation | Glama/PulseMCP/mcp.directory searches at +24h/+7d | entry visible (auto) |
| V8 | Manual listings | mcpservers.org page live; awesome PR merged | both live |
| V9 | CI repeatability | two consecutive workflow runs (dry-run then real) | both green |
| V10 | No secrets in metadata | grep `server.json` + registry API response for token patterns | none |

## Rollback plan

| Incident | Response |
|---|---|
| Bad npm release (wrong code/version) | `npm deprecate @nvdigitalsolutions/nvoos-mcp-bridge@<ver> "<reason>"`, publish a fixed patch as the next version, re-publish registry metadata for the fixed version |
| Bad registry metadata | Metadata is immutable per version — publish the corrected `server.json` as a new version; if the entry itself is wrong (name squat/imposter case), open a registry GitHub issue per the moderation policy |
| Namespace auth failure | Re-login with an org member; confirm org membership; confirm the registry preview's current auth methods (device-flow vs OIDC) |
| Directory review rejection | Fix the flagged copy field (usually description/category), resubmit; premium tier exists as an escape hatch for mcpservers.org |
| Registry preview data reset | Re-run the Phase 3/4 publish from the in-repo `server.json` — metadata is version-controlled precisely for this |

## Decision gates

Approvals from proposal §10, mapped to phases:

| Gate | Decision | Blocks |
|---|---|---|
| D1 | Approve public namespace `io.github.nvdigitalsolutions/nvoos-mcp-bridge` | Phase 3 |
| D2 | Approve listing copy (Appendices A–C) | Phases 3, 5 |
| D3 | Free vs premium tiers (mcpservers.org / mcp.so, $39 one-time each) | Phase 5 |
| D4 | Approve OIDC CI publishing + maintainer device-flow backup | Phase 4 |
| D5 | Confirm honest "relay" positioning (vs deferring to a tool-bearing entry) | Phases 3, 5 |

## File manifest

**Landed with this plan (2026-10-08):**

| File | Change |
|---|---|
| `packages/nvoos-mcp-bridge/package.json` | + `mcpName` ownership marker |
| `packages/nvoos-mcp-bridge/server.json` | New — registry metadata (proposal Appendix A) |
| `packages/nvoos-mcp-bridge/README.md` | + "Ecosystem discovery" section |
| `docs/project/proposals/README.md` | 061 entries + date bump |
| `docs/project/proposals/061-mcp-registry-publishing-proposal.md` | New (previous change) |
| `docs/project/proposals/061-mcp-registry-publishing-implementation-plan.md` | New (this document) |

**Planned (Phases 2–6):**

| File | Change |
|---|---|
| `.github/workflows/npm-publish-nvoos-mcp-bridge.yml` (or sibling) | + registry OIDC publish job (Phase 4) |
| `packages/nvoos-mcp-bridge/package.json` | version bumps at each release (workflow-managed) |
| `packages/nvoos-mcp-bridge/server.json` | version sync at each registry publish (CI-managed) |
| `docs/operations/` | registry/directory upkeep runbook (Phase 6) |
