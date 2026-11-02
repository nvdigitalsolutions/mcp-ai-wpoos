# Proposal 103 — NV oOS Cognition v0.3.0: Rebrand, Icon Parity, and Public Mirror Repository

- **Status:** Implemented (PR pending) — this document is the record of the
  change and its decisions.
- **Applies to:** `examples/nvoos-dictate` (the NV oOS desktop companion app,
  Proposal 067).
- **Related:** Proposal 067 (app), Proposal 100 (screen OCR), PR #7382
  (brand icons + accents), PR #7385/#7386 (Complete Velocity console as a
  first-party window).
- **Date:** 2026-11-02

## 1. Problem

Three gaps remained after the screen-OCR and console-integration rounds:

1. **Brand drift.** The product is marketed and displayed as "NV oOS
   Dictation", but the app has grown well past dictation (screen OCR,
   camera/vision, meetings, MCP, an AI-companion console). The NV oOS
   brand team's current name is **NV oOS Cognition**.
2. **Icon parity.** The brand mark (the cyan/purple `nvoos-icon.svg`) was
   the in-app identity, but the OS-level executable identity (window icon,
   taskbar, installer) was still placeholder art produced by
   `scripts/gen-icons.mjs`. PR #7382 committed the real icon set, but the
   publish workflow still regenerated placeholders over them at release
   time.
3. **No public home.** Every sibling surface has a public mirror
   (`nvoos-docs-hub`, `nvoos-content-graph`, `mcp-ai-wpoos-media-worker`,
   `nvoos-complete-velocity`, …). The desktop app had none, so there was
   no citable public repository for the companion app.

## 2. Goals

1. Rebrand the user-facing product identity to **NV oOS Cognition** and
   release it as **v0.3.0**.
2. Guarantee the executable icon is the brand mark across every build
   path (local debug builds, CI artifacts, release bundles).
3. Publish the app to a public one-way mirror repository
   `nvdigitalsolutions/nvoos-cognition`, maintained by the monorepo sync
   workflow, following the established media-worker pattern.

## 3. Industry-standards grounding

- **Staged rebrand (display name first, identifiers later).** Renaming a
  shipped desktop app's *product name* is cheap and safe; renaming its
  *technical identifiers* (crate, binary, bundle identifiers, data
  directories, updater feed slugs) breaks OS-level continuity. Industry
  precedent (Electron, Tauri, Chrome-style rebrands) separates the two:
  the display name changes in one release, and identifiers change only
  with an explicit migration. This proposal changes the display name and
  **keeps every technical identifier stable**.
- **SemVer.** 0.2.0 → 0.3.0: a minor bump for a visible branding change
  with no breaking API/behavior change. The updater feed
  (`nvoos-dictate-v{{current_version}}/latest.json`) and release-tag
  convention (`nvoos-dictate-v*`) are unchanged, so existing 0.2.x
  installs receive the update through the normal channel.
- **Identifier stability is a user-data contract.** `%APPDATA%/nvoos-dictate`
  (config, models, SQLite history), the Windows Credential Manager targets
  (`nvoos/assistant-credential.com.nvdigitalsolutions.nvoos-dictate`, …),
  and the macOS keychain ACLs are all keyed to the current identifiers. A
  rename without a migration layer would orphan users' models (multi-GB
  downloads), transcripts, and credentials. The deep rename is therefore
  a separate, migration-equipped project (see §7).
- **One-way subtree mirrors.** The monorepo → public-repo pattern used by
  `sync-media-worker.yml` (`git subtree split` + force push, flat mirror
  history) is the repo's standard. It preserves full history in the
  monorepo while giving the public repo a clean, always-current tree, and
  it needs no npm/cargo tooling on the mirror side.
- **CI must never regenerate checked-in brand assets.** The publish
  workflow's `gen-icons.mjs` step predates the committed brand icons and
  was silently overwriting them. Checked-in assets + a bootstrap-only
  generator is the standard (cf. the repo's own "keep alpha-working ZIP
  copies" convention for build artifacts).

## 4. Decision log

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Display name `NV oOS Dictation` → `NV oOS Cognition` everywhere user-visible (window titles, macOS `.app` bundle, permission prompts, release titles, UI header, docs). | Brand correction; zero technical risk when paired with D2. |
| D2 | **Keep** technical identifiers: crate/binary `nvoos-dictate`, npm name, app identifier `com.nvdigitalsolutions.nvoos-dictate`, updater tags `nvoos-dictate-v*`, `%APPDATA%/nvoos-dictate`, keychain targets, CI workflow filenames. | Updater/keychain/data-dir continuity (§3). Deep rename deferred to a migration-equipped follow-up (§7). |
| D3 | Version 0.3.0 in `Cargo.toml`, `package.json`, `tauri.conf.json`, `Cargo.lock` — all four together. | The updater and the UI version read from the Cargo manifest; drift between them produces a misleading UI version. |
| D4 | Exe icons = the committed brand set (PR #7382); remove the `gen-icons.mjs` step from the publish workflow and mark the script bootstrap-only. | CI must not regenerate placeholder art over brand assets (§3). |
| D5 | Public mirror `nvdigitalsolutions/nvoos-cognition` via a new `sync-nvoos-cognition.yml` (subtree split of `examples/nvoos-dictate`, force push, flat history). | Identical mechanism to `sync-media-worker.yml`; zero new infrastructure. |
| D6 | The mirror ships the proprietary `LICENSE.txt` as-is (source-visible, not open-source). | The app's license is `LicenseRef-Proprietary`; changing it is out of scope and would need legal sign-off. |
| D7 | macOS CI `.app` paths in `build-nvoos-dictate.yml`, `publish-nvoos-dictate.yml`, `dictate-macos-interactive.yml`, and `macos-matrix-remote.sh` renamed in the same PR as `productName`. | Tauri derives the bundle name from `productName`; splitting the rename across PRs would break the macOS boot-smoke gate in between. |

## 5. Scope of the change (implemented)

- App identity: `tauri.conf.json` (productName, title), `Cargo.toml` /
  `package.json` descriptions, `index.html`, `App.tsx` brand header,
  `styles.css`, `main.rs` / `lib.rs` / `error.rs` strings, `Info.plist`
  (`NSMicrophoneUsageDescription`), `NOTICE.md`, `LICENSE.txt` header,
  `README.md`, `docs/remote-sites-recipe.md`, `docs/macos-port-plan.md`
  (title + plist row), `e2e/smoke.spec.ts`, mac-helper source comments.
- CI/packaging: three workflow names + all macOS `.app` paths and log
  predicates; publish release title/body; `macos-matrix-remote.sh` default
  path; `gen-icons.mjs` step removed from publish + script re-marked
  bootstrap-only.
- Mirror: `.github/workflows/sync-nvoos-cognition.yml`; public repo
  `nvdigitalsolutions/nvoos-cognition` created.
- Docs: this proposal + plan pair; the dictate-app skill updated
  (brand, version metadata, boot-error string, workflow reference,
  `.app` pitfall, console direct-composer-delivery and PTT-liveness
  mechanics, and a status entry for the rebrand + mirror).

## 6. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Renaming `productName` breaks macOS CI paths | All `.app` paths renamed in the same PR (D7); the universal boot-smoke job verifies on every push. |
| Updater continuity | Tag convention and feed URL untouched; 0.2.x installs update to 0.3.0 normally. |
| Mirror repo receives placeholder/broken state | Mirror is a one-way flat mirror of the tracked monorepo tree only (`dist/` and `node_modules/` are untracked and never mirror); the subtree split includes only tracked files. |
| Placeholder icons regress | `gen-icons.mjs` no longer runs in CI and its header warns against post-branding use. |
| Secret-scoped token for the mirror push | Owner-only activation (repo-scoped deploy token as `NVOOS_COGNITION_REPO_TOKEN`); agents cannot mint secrets. |
| Deep rename expectations | Explicitly deferred and documented (§7) so nobody half-renames an identifier later. |

## 7. Deferred: the deep technical rename (separate project)

A future "identifier migration" release may rename the crate/binary to
`nvoos-cognition`. It must include, at minimum:

1. One-time migration of `%APPDATA%/nvoos-dictate` → `%APPDATA%/nvoos-cognition`
   (config, models, transcripts) with a fallback read path for one release.
2. Windows Credential Manager + macOS keychain migration or re-enrollment
   (the keychain ACL binds to the bundle identity).
3. Updater tag change `nvoos-dictate-v*` → `nvoos-cognition-v*` with a
   bridge release (the old feed must still answer for the last
   `nvoos-dictate-v*` version).
4. Renames of: Cargo/npm package names, app identifier
   `com.nvdigitalsolutions.nvoos-cognition`, CI workflow files
   (`build-nvoos-dictate.yml` → `build-nvoos-cognition.yml`, …), artifact
   names, the skill directory, and all external references (AGENTS.md,
   copilot instructions, remote-sites docs).

That work is intentionally NOT in this PR.

## 8. Alternatives considered

- **Full deep rename now.** Rejected: breaks updater/keychain/data-dir
  continuity for every existing install, and would need the §7 migration
  machinery in the same release as a branding change — two orthogonal
  risks in one ship.
- **Publish the app as a standalone rewritten repo (like the standalone
  plugins).** Rejected: the app is actively developed inside the monorepo
  (skills, CI, shared conventions); a fork would either drift or need
  bidirectional syncing. The subtree mirror keeps one source of truth.
- **Keep the placeholder icons.** Rejected: the brand mark is already the
  app's identity; shipping placeholder art undermines the release.
- **Rename the sync secret to a shared token.** Rejected: repo-scoped
  deploy tokens follow the per-mirror convention (`MEDIA_WORKER_REPO_TOKEN`,
  `NVOOS_COMPLETE_VELOCITY_REPO_TOKEN`, …) and limit blast radius.
