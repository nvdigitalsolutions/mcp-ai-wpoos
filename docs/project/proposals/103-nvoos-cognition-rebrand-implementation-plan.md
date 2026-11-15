# Implementation Plan — Proposal 103 (NV oOS Cognition v0.3.0)

Companion to `103-nvoos-cognition-rebrand-proposal.md`. Checkboxes marked
**done** were implemented in the accompanying PR.

## Phase 1 — Brand rename (done)

- [x] `examples/nvoos-dictate/src-tauri/tauri.conf.json` — `productName`,
  window `title` → `NV oOS Cognition`.
- [x] `Cargo.toml` + `package.json` descriptions → Cognition.
- [x] Webview identity: `index.html` `<title>`, `src/App.tsx` brand header,
  `src/styles.css` header comment.
- [x] Rust user-facing strings: `main.rs` (headless-MCP message),
  `lib.rs` (module doc + boot `expect`), `error.rs` module doc,
  `util/win_foreground.rs` test fixture string.
- [x] macOS: `Info.plist` `NSMicrophoneUsageDescription`; `mac-helper`
  `Package.swift` + `Snipper.swift` comments.
- [x] App docs: `README.md` (title, `.app` xattr line, interactive-workflow
  reference, gen-icons note), `NOTICE.md`, `LICENSE.txt` header,
  `docs/remote-sites-recipe.md`, `docs/macos-port-plan.md` (title + plist row).
- [x] e2e: `e2e/smoke.spec.ts` brand assertion.

## Phase 2 — Version 0.3.0 (done)

- [x] `src-tauri/Cargo.toml` `version = "0.3.0"` (authoritative — UI and
  updater read it).
- [x] `package.json`, `src-tauri/tauri.conf.json`, `src-tauri/Cargo.lock`
  bumped in lockstep.
- [x] Updater contract untouched: tag `nvoos-dictate-v*`, endpoint
  `nvoos-dictate-v{{current_version}}/latest.json`.

## Phase 3 — Icon parity (done)

- [x] Brand icon set (from `nvoos-icon.svg` via `tauri icon`) confirmed
  committed in `src-tauri/icons/` (PR #7382).
- [x] `scripts/gen-icons.mjs` re-marked **bootstrap-only** (header warning);
  it must never run after branding.
- [x] `publish-nvoos-dictate.yml`: "Generate icons" step removed — release
  bundles now carry the committed brand icons.
- [x] Local rebuild of the complete-union debug exe so the shipped binary
  shows the new icon/name/version (see Phase 6 gates).

## Phase 4 — Public mirror (done, activation pending)

- [x] Public repo created: `nvdigitalsolutions/nvoos-cognition`.
- [x] `.github/workflows/sync-nvoos-cognition.yml` — subtree split of
  `examples/nvoos-dictate`, force push to the mirror's `main`, triggers on
  pushes to `main`/`alpha-working` touching `examples/nvoos-dictate/**`,
  plus `workflow_dispatch`.
- [ ] **Owner:** add a repo-scoped deploy token as the repository secret
  `NVOOS_COGNITION_REPO_TOKEN`.
- [ ] **Owner:** dispatch the sync workflow once to backfill the mirror.
- [ ] **Owner:** review the mirror README render (brand, features, license).

## Phase 5 — Docs (done)

- [x] `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` — brand in title
  + description frontmatter, `app-version` 0.3.0, boot-error string,
  interactive-workflow name, `.app` pitfall, console **direct composer
  delivery** + **PTT liveness** mechanics, rebrand + mirror status entry.
- [x] `103-nvoos-cognition-rebrand-proposal.md` + this plan.
- [ ] `docs/project/proposals/README.md` index entry for 103 — added by the
  next agent to touch the index (the file was concurrently dirty in the
  shared worktree during this PR).

## Phase 6 — Validation gates

- [x] `npm run build` (tsc + vite) — frontend compiles with the new brand
  string.
- [x] `cargo fmt --check` (default features).
- [x] `cargo clippy --all-targets -- -D warnings` (default features).
- [x] `cargo test` (default feature set) — includes the updated
  `win_foreground` title test.
- [x] Complete-union debug exe built:
  `npm run tauri -- build --debug --no-bundle --features fastconformer,parakeet,whisper,silero-vad,camera,vision,agent,mcp,snippet,snippet-onnx`
  → `src-tauri/target/debug/nvoos-dictate.exe`.
- [ ] CI: the PR run of `build-nvoos-dictate.yml` re-verifies the renamed
  macOS `.app` paths and the windows-bundle job.

## Rollback

- Revert = restore the pre-PR `productName`/`version` in `tauri.conf.json`
  and `Cargo.toml`; the mirror workflow is inert until the secret exists,
  so it can be disabled by deleting the workflow file. The public repo can
  be archived if the mirror approach is abandoned.

## Follow-ups

1. Deep technical rename (crate/binary/identifier/data-dir/updater-tag
   migration) — see proposal §7.
2. CHANGELOG file in the app root (the publish release notes currently
   point at one that does not exist).
3. `docs/project/proposals/README.md` index entry (Phase 5).
