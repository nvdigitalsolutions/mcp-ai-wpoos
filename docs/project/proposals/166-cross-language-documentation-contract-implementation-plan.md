# 166 — Cross-language documentation contract: implementation plan

**Status:** plan (awaiting approval)
**Date:** 2026-11-26
**Proposal:** [`166-cross-language-documentation-contract-proposal.md`](166-cross-language-documentation-contract-proposal.md)
**Research:** [`166-cross-language-documentation-contract-research.md`](166-cross-language-documentation-contract-research.md)

---

## 0. Execution model

### 0.1 Workstreams

Six phases. P8 is deliberately one mechanical PR family; P9–P11 are per-tree;
P12 locks the result. Every phase is independently shippable and revertible.

| Phase | Deliverable | Depends on | PR shape |
|---|---|---|---|
| P8 | Polyglot licence identity + scope map + boundary delegation | — | tooling PR, then one mechanical PR per tree |
| P8b | Manifest licence completeness + `REUSE.toml` | P8 | small, per tree |
| P9 | File taxonomy + purpose headers + ratchet baseline | P8 | checker PR, then per-tree authoring |
| P10 | TSDoc / rustdoc public surface | P9 | per-tree PRs |
| P11 | Folder READMEs for non-PHP trees | P9 | per-tier PRs |
| P12 | CI wiring, budgets locked, docs/skills sync | P8–P11 | one closing PR |

### 0.2 PR sequence

The order matters: tooling lands before any file is touched, and the strict gate
lands only after the last budget reaches zero.

| # | Branch theme | Contents | Review size |
|---|---|---|---:|
| 1 | `docs/166-p8-tooling` | Inserter language adapters, `check-file-docs.php` (scope resolution, licence agreement, audit, self-test), `.github/docs-scopes.json`, fixtures. **No source files changed.** | ~700 lines |
| 2 | `docs/166-p8-licence-rust` | SPDX line on 153 first-party `.rs` | +153 / −0 |
| 3 | `docs/166-p8-licence-packages` | SPDX line on `packages/**/src` (~210) | +210 / −0 |
| 4 | `docs/166-p8-licence-addons` | SPDX on addon TS (~593) + Astro (106) | +699 / −0 |
| 5 | `docs/166-p8-licence-examples` | SPDX on example TS (~280) + root TS (~84) | +364 / −0 |
| 6 | `docs/166-p8-licence-js` | SPDX on first-party JS/MJS/CJS (~849) | +849 / −0 |
| 7 | `docs/166-p8b-manifests` | `license` fields, `REUSE.toml`, metadata | ~15 lines + config |
| 8 | `docs/166-p9-baseline` | Budget baseline, checker `--strict` in report mode, taxonomy doc | ~400 lines |
| 9+ | `docs/166-p9-<tree>` | Purpose headers, one tree per PR | 60–300 lines |
| N | `docs/166-p10-ts-<tree>` | jsdoc/tsdoc deps + config + authoring | per tree |
| N+1 | `docs/166-p10-rust` | `[lints]`, item docs, `cargo doc` gate | 100–400 lines |
| N+2 | `docs/166-p11-readmes-<tier>` | Folder READMEs by tier | 3–15 files |
| Last | `docs/166-p12-lock` | `--strict` blocking, composer, PR template, context/skills | ~200 lines |

PRs 2–6 are insert-only and can land in any order after #1. They must not be
combined with prose authoring — that is what keeps the mechanical diff reviewable.

### 0.3 New files

| Path | Purpose |
|---|---|
| `bin/check-file-docs.php` | Scope resolution, header/purpose checks, licence agreement, ratchet budget (`--json`, `--scope=`, `--strict`, `--update`, `--allow-increase`, `--audit-scopes`, `--explain`, `--licence-only`, `--self-test`) |
| `.github/docs-scopes.json` | **Single source of truth** for scope → globs → manifest authority → exemptions. Consumed by the checker and by the boundary shell script |
| `.github/docs-budget.json` | Per-scope baseline counts |
| `REUSE.toml` | Licence annotations for JSON/TOML/lockfiles/binaries |
| `docs/developer/file-header-convention.md` | The human contract: taxonomy, bars, examples |
| `.context/templates/file-header-examples.md` | Copy-paste headers by class and language |
| `.github/workflows/docs-contract.yml` | Runs self-test, strict check, boundary, folder READMEs |
| `tests/fixtures/headers/**` | Golden input/expected pairs for the inserter self-test |

### 0.4 Modified files

| Path | Change |
|---|---|
| `bin/add-spdx-headers.php` | Language adapters (`//`, docblock, `#`), shebang, Astro frontmatter; keeps idempotence and dry-run default; never writes prose |
| `bin/check-license-boundary.sh` | Keeps packaging + direction rules; delegates file-level licence presence to `check-file-docs.php --licence-only` |
| `bin/check-folder-readmes.php` | `--scope=ts|rust|all`; normalise `\` → `/` before matching |
| `composer.json` | Add `docs:check-file`, `docs:check-file:strict`, `docs:budget`; insert `@docs:check-file` into `ci:all` (the folder-README scripts already exist) |
| `eslint.config.cjs` + adopted per-tree `eslint.config.{js,cjs}` | `jsdoc/require-file-overview`, `require-jsdoc` (`publicOnly`), `require-description`, `no-types`, `tsdoc/syntax` |
| Root and per-tree `package.json` + lockfiles | Explicit `eslint-plugin-jsdoc`, `eslint-plugin-tsdoc` |
| `examples/nvoos-dictate/src-tauri/Cargo.toml` | `[lints.rust]`, `[lints.rustdoc]`, `[lints.clippy]` |
| `.github/PULL_REQUEST_TEMPLATE.md` | Two checklist items under "Per-Story Gate Checklist" |
| `CLAUDE.md`, `AGENTS.md`, `.context/conventions.md`, adopted skills | Contract summary + gate commands |

### 0.5 Ground truth (re-verify before starting)

Measured 2026-11-26. The P8 audit must reproduce these numbers; a material
difference means the plan's scope split is stale.

| Fact | Value | How to verify |
|---|---:|---|
| First-party TS/TSX | 1,144 | `git ls-files '*.ts' '*.tsx' \| grep -vE '/(vendor\|dist\|build\|node_modules)/' \| wc -l` |
| First-party JS/JSX/MJS/CJS | 944 | same, including `.jsx` |
| In-scope source files (checker) | **2,247** | after generated-bundle and generated-dir exclusions |
| First-party Rust | **153** (+3 vendored) | `git ls-files '*.rs'`; `grep -c vendor` |
| Astro | 106 | all 106 open with `---` (verified) |
| TS/JS files with a shebang | **102** | `git grep -l '^#!'` intersected with the first-party list |
| First-party TS/JS/Rust with BOM | **0** | byte check |
| First-party TS/JS/Rust with CRLF | **0** | byte check |
| `phpcs-ratchet` location | `.github/scripts/phpcs-ratchet.php` | **not** `bin/` |
| Root devDeps `eslint-plugin-jsdoc` / `tsdoc` | **absent** | `package.json`; must be added in P10 |
| Rust CI gates already present | `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, `cargo test`, `+mcp`, `+document` | `.github/workflows/build-nvoos-dictate.yml` |

---

## P8 — Polyglot licence identity

### Goal

Every first-party source file declares the same licence as its nearest manifest,
and the boundary gate fails when it does not. No prose is written in this phase.

### P8.0 Pre-flight audit

Before writing anything:

```bash
php bin/add-spdx-headers.php --json            # dry-run (the default): files by scope + inferred licence, writes nothing
php bin/check-file-docs.php --audit-scopes   # unowned files, missing manifests, unmatched globs
```

Stop if any scope has **> 5%** unowned files (no resolvable manifest and no
fallback). Fix the scope map first; do not guess licences during the apply run.

### P8.1 Extend the inserter

`bin/add-spdx-headers.php` currently returns `skipped` for anything that does not
open with `<?php`. Add a language adapter selected by extension:

| Extension | Syntax | Insertion point | Notes |
|---|---|---|---|
| `.php` | existing docblock | unchanged | do not alter |
| `.ts`, `.tsx`, `.js`, `.jsx`, `.mjs`, `.cjs` | `//` | line 1, or line 2 after `#!` | 102 first-party files have a shebang |
| `.rs` | `//` | line 1, before `//!` | rustfmt does not reorder comments |
| `.astro` | `//` | inside the opening `---` fence | all 106 files have frontmatter |
| `.css`, `.scss` | `/* … */` | line 1 | **deferred** (proposal §10) |

Hard rules:

- **Never synthesise prose.** The PHP path currently writes a generic
  `File header.` docblock when none exists. The TS/Rust/Astro adapters insert the
  SPDX line only; a missing purpose header stays missing for P9.
- Detect existing identity in the **first 2 KB**, not the whole file, to avoid
  false-skipping a file that mentions SPDX in a string.
- Preserve: encoding, BOM, EOL, shebang, file mode, trailing-newline state.
  (Measured: 0 BOM and 0 CRLF in first-party TS/Rust — but the adapter must not
  assume it.)
- Refuse to write outside the resolved scope; report and continue.
- Dry-run is the default; `--apply` is explicit.
- `.astro` without frontmatter: insert `<!-- SPDX-License-Identifier: … -->` and
  emit a warning. (Not needed today; all 106 have frontmatter.)

### P8.2 Scope map and boundary delegation

One machine-readable map, two consumers:

```json
{
  "version": 1,
  "exclude": [
    "**/vendor/**", "**/node_modules/**", "**/dist/**",
    "**/build/**", "**/target/**", "**/gen/**"
  ],
  "scopes": {
    "ts:packages":     { "lang": "ts",   "paths": ["packages/*/src/**/*.{ts,tsx}"],
                         "manifest": "package.json", "fallback_licence": "MIT" },
    "ts:spa-v2":       { "lang": "ts",   "paths": ["addons/pro/assets/spa-v2/src/**/*.{ts,tsx}"],
                         "manifest": "package.json", "fallback_licence": "LicenseRef-Proprietary" },
    "ts:bridge":       { "lang": "ts",   "paths": ["addons/nvoos-astro-bridge/src/**/*.{ts,tsx}"],
                         "manifest": "package.json", "fallback_licence": "LicenseRef-Proprietary" },
    "astro":           { "lang": "astro","paths": ["**/*.astro"],
                         "manifest": "package.json" },
    "rust:dictate":    { "lang": "rust", "paths": ["examples/nvoos-dictate/src-tauri/**/*.rs"],
                         "manifest": "Cargo.toml",   "fallback_licence": "LicenseRef-Proprietary" }
  }
}
```

The excerpt lists five scopes; the committed map lists every scope in §P9.2. A
scope whose trees all carry a manifest **omits** `fallback_licence` — an unowned
file is then a configuration error (exit 2), not a guess. `fallback_licence` is
for a product-wide fact ("everything under this scope is Pro"), never for a
directory that merely lacks a manifest today.

Resolution algorithm (documented in the checker's `--help`):

1. Expand the scope's globs against `git ls-files`.
2. Drop `exclude` matches and report the skip count.
3. Find the **nearest ancestor manifest** of the declared type for each file.
4. Read `license` (npm) or `package.license` (Cargo).
5. Normalise: `UNLICENSED`, `proprietary`, `SEE LICENSE IN …` →
   `LicenseRef-Proprietary`; anything already SPDX is kept.
6. No manifest → `fallback_licence`; no fallback → **exit 2** (unowned file).

`bin/check-license-boundary.sh` keeps the packaging and direction rules
(`check_tree` for forbidden licences) and calls
`php bin/check-file-docs.php --strict` for the file-level contract. The strict
mode is budget-aware, so the recorded baseline is allowed while a new file or a
wrong licence still fails. This removes the duplicated glob table from bash and
makes the map reviewable in one place.

### P8.3 Scope and expected volume

| Scope | Files | Change |
|---|---:|---|
| `packages/**/src/**/*.ts` | ~210 | +1 line each |
| `addons/**/src/**/*.{ts,tsx}` | ~593 | +1 line each |
| `examples/**/src/**/*.{ts,tsx}` | ~280 | +1 line each |
| root `src/**`, `assets/js/src/**` | ~84 | +1 line each |
| `.astro` | 106 | +1 line each |
| `.rs` (first-party) | **153** | +1 line each |
| `.js` / `.mjs` / `.cjs` (first-party) | ~849 | +1 line each |

Totals: 1,144 TS + 944 JS/JSX + 153 Rust + 106 Astro = 2,347 tracked first-party
files. The checker's in-scope total is **2,247**: generated bundles
(`*.min.js`) and the `dist`/`build`/`gen`/`target` outputs are excluded. Every
in-scope file carries its manifest licence (0 missing, 0 wrong).

### P8.4 Self-test fixtures

`src-tauri`-style fixtures under `tests/fixtures/headers/`:

```
php/{input,expected}.php
ts/{input,expected}.ts
ts-shebang/{input,expected}.mjs
rust/{input,expected}.rs
astro/{input,expected}.astro
crlf/{input,expected}.ts
bom/{input,expected}.ts
already-spdx/{input,expected}.ts   # must be byte-identical
```

`php bin/check-file-docs.php --self-test` (or the inserter's `--self-test`) runs
each pair in a temp dir and diffs. This is cheaper and more portable than a
PHPUnit harness for a procedural bin script.

### P8.5 Acceptance

```bash
php bin/check-file-docs.php --self-test          # all fixtures pass
php bin/add-spdx-headers.php --apply --scope=rust:dictate
php bin/add-spdx-headers.php --apply --scope=rust:dictate   # second run: unchanged
bash bin/check-license-boundary.sh               # exit 0
```

- [ ] Boundary gate covers PHP, TS, JS, Astro, Rust.
- [ ] `git diff --numstat` shows `1 0` for every source file and nothing else.
- [ ] `git diff -U0 | grep '^+' | grep -v 'SPDX-License-Identifier'` is empty
      (the "no prose" check).
- [ ] No file under an excluded path was modified.
- [ ] Second apply is a no-op.
- [ ] Windows and Linux both pass.

### P8.6 Rollback

`git revert` the tree PR. Insert-only, so the revert restores the tree exactly;
tooling PR #1 is independent and can stay.

---

## P8b — Manifests and uncommentable files

### Tasks

1. Add `license` to first-party manifests that lack it. Verified starting set:

| Manifest | Value |
|---|---|
| `addons/pro/assets/spa-v2/package.json` | `UNLICENSED` (inherits Pro) |
| `addons/docs-hub/package.json` | `GPL-3.0-or-later` |
| `addons/nvoos-astro-bridge/package.json` | `UNLICENSED` |
| `plugins/nvoos-forge-bridge/package.json` | `GPL-3.0-or-later` |
| `plugins/nvoos-content-graph/package.json` | `GPL-3.0-or-later` |
| `addons/media-worker/package.json` | `UNLICENSED` → `LicenseRef-Proprietary` (decided; distributed service) |
| `examples/qb-desktop-mcp/pyproject.toml` | `MIT` — already declared; no npm manifest |
| `examples/qb-online-mcp/package.json` | `UNLICENSED` → `LicenseRef-Proprietary` (decided; first-party, not a fork) |

   `--audit-scopes` enumerates the authoritative list; the table is the starting set.

2. Add `REUSE.toml` (schema v1):

```toml
version = 1

[[annotations]]
path = ["package-lock.json", "**/package-lock.json", "**/Cargo.lock", "composer.lock"]
precedence = "aggregate"
SPDX-FileCopyrightText = "NV Digital Solutions"
SPDX-License-Identifier = "LicenseRef-Proprietary"

[[annotations]]
path = "addons/canvas/assets/canvas/**"
precedence = "override"
SPDX-License-Identifier = "MIT"
```

   The second block is the documented exception: an MIT vendored library inside a
   proprietary product tree. It must stay explicit so the direction rule remains
   "MIT never flows back into `lib/core`", not "MIT never appears under `addons/`".

3. Add `repository`, `description` and `authors` where missing (C-METADATA).

### Acceptance

- [x] `--audit-scopes` reports zero unowned files.
- [x] Every first-party manifest has an SPDX-valid `license`.
- [ ] `REUSE.toml` parses and every annotation has `path` + `SPDX-License-Identifier`.
- [ ] No `.license` companion files were created.

---

## P9 — File-root purpose

### Goal

Every source file opens with a purpose statement sized to its class, enforced by
a ratchet that lets the legacy tree burn down.

### P9.1 Checker contract

| Flag | Behaviour |
|---|---|
| *(none)* | Report; exit 0 even when over budget |
| `--strict` | Exit 1 on any over-budget scope |
| `--scope=<name>` | One scope; accepts a comma list |
| `--json` | Deterministic, sorted machine output |
| `--explain <file>` | Print inferred class, resolved manifest, licence, and which check failed |
| `--audit-scopes` | Unowned files, unmatched globs, manifest gaps; writes nothing |
| `--licence-only` | Presence + manifest agreement only (used by the shell gate) |
| `--self-test` | Fixture suite |
| `--update` | Rewrite budgets; refuses to raise unless `--allow-increase --reason='…'` |
| `--timing` | Per-stage timings; must stay < 5 s |

Per-file checks:

| Check | Rule |
|---|---|
| `spdx` | Identifier on line 1 (or line 2 after a shebang) |
| `class` | Taxonomy inferred from path/extension; printed in `--json` |
| `overview` | `@file` / `@fileoverview` (JS/TS); `//!` (Rust); frontmatter JSDoc (Astro) |
| `bar` | ≥ 2 non-empty lines and does not merely restate the basename |
| `generated` | If `@generated`, require a generator command in the header |
| `exempt` | Allowlisted paths are reported, never failed |

Exit codes: `0` clean, `1` violation/over budget, `2` configuration error (unowned
file, invalid scope, unmatched glob). No other codes.

The `bar` heuristic is intentionally weak (length + filename restatement). The
real bar is review; the checker only stops the empty/placeholder case.

### P9.2 Budget schema

```json
{
    "version": 1,
    "tolerance": 25,
    "scopes": {
        "rust:dictate": {
            "files": 153,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 0
        },
        "ts:addons": {
            "files": 865,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 856
        },
        "ts:bin": {
            "files": 45,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 45
        },
        "ts:docker": {
            "files": 3,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 3
        },
        "ts:docs": {
            "files": 1,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 1
        },
        "ts:examples": {
            "files": 760,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 753
        },
        "ts:extensions": {
            "files": 3,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 3
        },
        "ts:includes": {
            "files": 1,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 1
        },
        "ts:packages": {
            "files": 71,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 71
        },
        "ts:plugins": {
            "files": 51,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 51
        },
        "ts:repo-tooling": {
            "files": 11,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 11
        },
        "ts:root": {
            "files": 220,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 220
        },
        "ts:tests": {
            "files": 63,
            "missing_spdx": 0,
            "wrong_spdx": 0,
            "missing_overview": 63
        }
    }
}
```

Rules, mirroring `.github/scripts/phpcs-ratchet.php`:

1. No scope may exceed a recorded **debt** count (`missing_spdx`, `wrong_spdx`,
   `missing_overview`). The `files` count is informational: a new compliant file
   is the goal, not debt.
2. No stale slack — a scope more than `tolerance` under budget fails, so an
   improvement must be recorded in the same PR.
3. New files are always in scope and must be clean on arrival.
4. Named scopes carry their own budgets; there is no global average.
5. `--update` refuses to raise a budget without `--allow-increase`, which must be
   justified in the PR body.

Scopes are disjoint and sum to **2,247** — the in-scope total — so no source
file is unowned. There is no licence debt in the baseline (0 missing, 0 wrong);
the remaining debt is 2,078 purpose headers.

### P9.3 Authoring order

1. `packages/**` — the published API surface.
2. `addons/nvoos-astro-bridge/src/**` — the commerce contract.
3. `addons/pro/assets/spa-v2/src/api/**` — the console contract.
4. `examples/nvoos-dictate/src-tauri/src/**` — normalise the last module, then done.
5. Remaining addon/example trees.
6. `assets/js/**` — **excluded by default** (see P9.4).

### P9.4 The `assets/js` decision

`assets/js/**` is the largest and oldest tree. It is **not** in P9's default
scope. The ratchet treats it as a separate decision after the adopted trees reach
zero, because:

- it is 1,000+ files with mixed provenance,
- much of it is being progressively replaced by `src/**` and the SPA packages,
- a failed adoption there would dominate the budget and hide progress elsewhere.

If adopted later, it gets its own scope and its own PR series.

### P9.5 Acceptance

- [ ] `--strict` passes for every adopted scope.
- [ ] A new `.ts` file with no header fails CI.
- [ ] The Rust `missing_overview` budget reaches 0 without changing prose.
- [ ] At least one scope's budget has been lowered in a follow-up PR.
- [ ] `--timing` is under 5 seconds.

### P9.6 Rollback

Raise the affected budget with `--allow-increase --reason=…` (recorded in the PR),
or remove the scope from `.github/docs-scopes.json`. The checker stays; only the
scope becomes unenforced.

---

## P10 — Public-surface documentation

### Goal

Consumers of the TS packages and the Rust crate get the same contract quality the
PHP tools already get.

### P10.1 Dependencies (new)

Neither `eslint-plugin-jsdoc` nor `eslint-plugin-tsdoc` is declared in the root
`package.json`, and no first-party tree declares them. P10 must add them
explicitly rather than rely on a transitive hoist from `@wordpress/eslint-plugin`:

```bash
npm install --save-dev eslint-plugin-jsdoc eslint-plugin-tsdoc
```

Per tree that is adopted. This is a real dependency change with lockfile churn;
it should be its own commit inside the tree PR, not mixed with authoring.
TypeDoc and API Extractor are **optional** and land only if adopted (§P10.2).

### P10.2 TypeScript

Per-tree ESLint (flat config) — note the file name varies (`eslint.config.js` in
most addons, `eslint.config.cjs` at the root):

```js
{
  files: ['src/**/*.{ts,tsx}'],
  plugins: { jsdoc, tsdoc },
  rules: {
    'jsdoc/require-file-overview': 'error',
    'jsdoc/require-jsdoc': ['warn', { publicOnly: true }],
    'jsdoc/require-description': 'warn',
    'jsdoc/no-types': 'error',
    'tsdoc/syntax': 'error',
  },
}
```

Adoption order:

1. `packages/**` — `@packageDocumentation` on every entry point; release tags on
   exported symbols.
2. `addons/nvoos-astro-bridge/src/**`.
3. `addons/pro/assets/spa-v2/src/api/**`.
4. Remaining trees at `warn` until their budget reaches zero, then `error`.

Documented exemptions (in the config, not silent): re-export barrels,
`export type`-only files, generated declaration files.

**API Extractor is opt-in per package.** It needs `declaration: true` and a
committed `.api.md`. Start with `packages/nvoos-api`; do not assume every package
emits declarations. TypeDoc validation stays a report, not a gate.

### P10.3 Rust — the `-D warnings` trap

The Rust job already runs:

```
cargo fmt --check
cargo clippy --all-targets -- -D warnings
cargo test
cargo test --features mcp
cargo test --features document
```

This changes the sequencing. A lint added at `warn` is an **immediate hard
failure** because of `-D warnings`. Do not add `missing_docs` or the clippy docs
lints to `[lints]` until the findings are authored or explicitly baselined.

Measurement first:

```bash
# Count only — no -D warnings.
RUSTFLAGS="-W missing_docs" cargo check --manifest-path examples/nvoos-dictate/src-tauri/Cargo.toml 2>&1 \
  | grep -c "missing documentation"

cargo clippy --manifest-path examples/nvoos-dictate/src-tauri/Cargo.toml --all-targets 2>&1 \
  | grep -cE "missing (errors|panics|safety) doc"
```

Go/no-go:

| `missing_docs` count | Action |
|---:|---|
| ≤ 50 | author in P10, then enable `deny` |
| 51–300 | author the public API surface first; enable `deny` on the lib target, leave the binary target at `allow` |
| > 300 | reduce the Rust bar to crate + module docs (`//!`) and record the decision; item docs become best-effort |

**Measured outcome (2026-11-26).** `RUSTFLAGS="-W missing_docs" cargo check`
reports **739** undocumented public items. That is above the 300 threshold, so
the `missing_docs = "deny"` line is **not enabled**. The enforced bar stays at
crate + module docs (`//!`), which is now clean (**153/153 files**). Item docs are
best-effort, and a report-only CI step should publish the count so the burn-down
is visible. The clippy docs lints stay off until separately measured.

Target `[lints]` block (not applied — see the measured outcome):

```toml
# examples/nvoos-dictate/src-tauri/Cargo.toml
# Do NOT enable missing_docs until the 739-item baseline is burned down.
[lints.rust]
missing_docs = "deny"
missing_crate_level_docs = "warn"

[lints.rustdoc]
broken_intra_doc_links = "deny"
bare_urls = "warn"
unescaped_backticks = "warn"

[lints.clippy]
missing_errors_doc = "warn"
missing_panics_doc = "warn"
missing_safety_doc = "deny"
```

Notes:

- `missing_docs` is a rustc lint and applies to **public** items; the lib target
  (`nvoos_dictate_lib`) is where it matters.
- `missing_docs_in_private_items` stays **off** (application crate).
- `cargo test` already runs doctests (no `--lib`), so examples are executable.
  Keep it that way; if a future job narrows to `--lib`, add `cargo test --doc`.
- `cargo fmt --check` is already enforced; the SPDX line above `//!` is
  rustfmt-stable.

### P10.4 Acceptance

- [ ] `cargo fmt --check` still passes.
- [ ] `cargo clippy --all-targets -- -D warnings` still passes.
- [ ] `RUSTDOCFLAGS="-D warnings" cargo doc --no-deps` is clean.
- [ ] `cargo test` (which includes doctests) passes on the three feature sets.
- [ ] No `@param {type}` remains in any adopted TS tree.
- [ ] `packages/**` adopted at `error`, with release tags.

---

## P11 — Folder READMEs

### Goal

Every non-PHP source folder that is a genuine module introduces itself with the
same seven H2 sections as P7.

### P11.1 Tiering

115 candidate directories were counted at depth ≤ 4. They are tiered, not
blanket-covered:

| Tier | Trees | Count (est.) |
|---|---|---:|
| A — published or contract-bearing | `packages/*/src`, `addons/nvoos-astro-bridge/src`, `spa-v2/src`, `examples/nvoos-dictate/src-tauri` (crate root), `src-tauri/examples`, `src-tauri/tests` | ~40 |
| B — example apps | `examples/*/src`, `examples/*/lib`, `examples/*/mcp` | ~25 |
| C — addon SPAs | `addons/*/src` | ~15 |
| Exempt | `__tests__`, `fixtures`, `gen`, `dist`, single-file directories, **Rust module directories** (covered by `//!`) | rest |

All 24 directories that contain `.rs` files are currently without a README. That
is deliberate for the 20 module directories (`src/agent`, `src/audio`, …): each
already opens with a substantial `//!` block, and a README would duplicate it.
The Rust README work is therefore **3 files**, not 20.

### P11.2 Rust: the three READMEs

| Path | Must contain |
|---|---|
| `src-tauri/README.md` | Purpose; `Runtime` (edition 2021, MSRV 1.90, Tauri 2); the feature matrix (`vision`, `agent`, `snippet`, `document`, `memory`, `mcp`, `onnx`); what `gen/` and `capabilities/` are and that they are generated; how to run the five CI gates |
| `src-tauri/examples/README.md` | Purpose (de-risk probes, not shipped); one line per probe: what it proves, required feature, and whether it needs network/model files |
| `src-tauri/tests/README.md` | Purpose; the fixture directories; which suites are feature-gated; how to run one test |

These follow the P7 template, with **Runtime** and **Generated artefacts** filled.

### P11.3 Tasks

1. Copy `.context/templates/folder-readme-template.md` into each Tier A/B/C
   folder; fill the seven required sections.
2. Non-PHP folders are checked from an explicit `folder_readmes` list in
   `.github/docs-scopes.json` via `php bin/check-file-docs.php --folder-docs`.
   This is deliberate: Rust module directories are documented by `//!`, so a
   blanket walk would demand duplicate READMEs. Adding a folder is a reviewable
   config change.
3. Add `docs:check-folder-readmes:all` to the CI composite command.

### P11.4 Acceptance

- [ ] `php bin/check-file-docs.php --folder-docs` exits 0 (Rust non-module folders).
- [ ] `php bin/check-folder-readmes.php --scope=all` exits 0 (PHP folders).
- [ ] No README restates naming/security/PHP-compat rules (drift heuristic clean).
- [ ] Every README's "Also Load" points at real files.
- [ ] The 20 Rust module directories remain README-free by rule.

---

## P12 — Ratchet close-out and CI wiring

### Tasks

1. `composer.json` — add and wire. `docs:check-folder-readmes:all` already
exists; add only the three new scripts and the strict gate:

```json
{
  "scripts": {
    "docs:check-file": "php bin/check-file-docs.php",
    "docs:check-file:strict": "php bin/check-file-docs.php --strict",
    "docs:budget": "php bin/check-file-docs.php --update"
  }
}
```

   Insert `@docs:check-file` into `ci:all` immediately after
   `@docs:check-folder-readmes` (before the quality gates and the test run).

2. `.github/workflows/docs-contract.yml`:

```yaml
name: Docs Contract
on:
  push:
    branches: [ main, alpha-working ]
  pull_request:
jobs:
  contract:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.1' }
      - run: php bin/check-file-docs.php --self-test
      - run: php bin/check-file-docs.php --audit-scopes
      - run: php bin/check-file-docs.php --strict
      - run: git fetch --no-tags origin ${{ github.base_ref }}:refs/remotes/origin/${{ github.base_ref }}
        if: github.event_name == 'pull_request'
      - run: php bin/check-file-docs.php --new-files-only --strict --base=origin/${{ github.base_ref }}
        if: github.event_name == 'pull_request'
      - run: php bin/check-file-docs.php --folder-docs
      - run: bash bin/check-license-boundary.sh
      - run: php bin/check-folder-readmes.php --scope=all --strict
```

   Do not pipe any of these through `grep`/`head`; the exit code must reach the
   job (the repo's recorded CI lesson).

3. `.github/PULL_REQUEST_TEMPLATE.md` — under **Per-Story Gate Checklist**, after
   the PHPDoc item:

```markdown
- [ ] New TS/JS/Rust/Astro files carry an SPDX line and a purpose header
      (`@fileoverview` / `//!`)
- [ ] Docs budget unchanged, or raised with `--allow-increase` and a reason
      (`composer run docs:check-file:strict`)
```

4. Update `CLAUDE.md`, `AGENTS.md`, `.context/conventions.md` with the P8–P12
   summary and the load order (header → folder README → manifest).
5. Update adopted skills with the gate commands: `mcp-ai-wpoos-spa-ui`,
   `mcp-ai-wpoos-astro-storefront`, `mcp-ai-wpoos-dictation-app`,
   `mcp-ai-wpoos-git-ops`.

### Acceptance

- [ ] A new TS file without a header fails CI in under 60 seconds.
- [ ] A new public Rust item without `///` fails `cargo doc`.
- [ ] `composer run ci:all` includes the docs gates.
- [ ] The context/skill surfaces describe the contract without contradicting it.
- [ ] The PR template items render and are actionable.

---

## Validation matrix

Run on both Windows (Git Bash) and Linux. Every command is an existing repo
convention except the three new ones.

| Check | Command | Expected |
|---|---|---|
| PHP syntax | `php -l bin/check-file-docs.php` | no error |
| Inserter self-test | `php bin/check-file-docs.php --self-test` | all fixtures pass |
| Inserter idempotence | run `--apply` twice | second run `unchanged` |
| Scope audit | `php bin/check-file-docs.php --audit-scopes` | 0 unowned |
| Boundary | `bash bin/check-license-boundary.sh` | exit 0 |
| File docs | `php bin/check-file-docs.php --strict` | exit 0 at budget |
| Folder READMEs | `php bin/check-file-docs.php --folder-docs` + `php bin/check-folder-readmes.php --scope=all --strict` | exit 0 |
| PHPCS ratchet | `composer run lint:ratchet` | unchanged (no regression) |
| TS lint | `npm run lint:js` (per tree) | no new violations |
| Rust format | `cargo fmt --check` | clean |
| Rust lints | `cargo clippy --all-targets -- -D warnings` | clean |
| Rust doc | `RUSTDOCFLAGS="-D warnings" cargo doc --no-deps` | no warnings |
| Rust tests | `cargo test` / `+mcp` / `+document` | pass |
| Budget guard | `--update` without `--allow-increase` | refuses to raise |

Performance budget: `check-file-docs` must stay under **5 seconds** on this tree
(grep/scan only; no AST, no network, no `npm ci` dependency).

---

## Stop conditions (go/no-go)

| Trigger | Action |
|---|---|
| P8 audit finds > 5% unowned files in a scope | stop; fix `.github/docs-scopes.json` before applying |
| Inserter touches any excluded path | abort the run; the scope filter is broken |
| `git diff -U0` contains a non-SPDX added line in a P8 PR | abort; prose crept into the mechanical PR |
| Rust `missing_docs` count > 300 | reduce the Rust bar per P10.3; record the decision |
| `jsdoc`/`tsdoc` install breaks a tree's lint | install at the root and scope the config; do not fork versions |
| `bar` heuristic false-positive rate > 10% in a 50-file sample | demote `bar` to a warning; rely on review |
| A scope's budget is not lowered in two consecutive PRs | pause authoring; reassess the bar or the tooling |
| Checker runtime > 5 s | cache the `git ls-files` result; do not add an AST dependency |

---

## Rollback

| Phase | Rollback |
|---|---|
| P8 | `git revert` the tree PR; insert-only, restores exactly |
| P8b | revert manifests + `REUSE.toml`; no code impact |
| P9 | raise the budget with `--allow-increase` (documented) or drop the scope from the map |
| P10 | `[lints]` levels are downgradable; ESLint rules are per-config; the dependency commit reverts cleanly |
| P11 | a README can be removed without code impact |
| P12 | remove the workflow/composer hook; the checkers remain runnable manually |

---

## Appendix A — Baseline snapshot commands

Record these outputs in the P8 tooling PR body, so the budget starts from
measured numbers:

```bash
# First-party file counts
git ls-files '*.ts' '*.tsx' | grep -vE '/(vendor|dist|build|node_modules)/' | wc -l
git ls-files '*.js' '*.mjs' '*.cjs' | grep -vE '/(vendor|dist|build|node_modules)/' | wc -l
git ls-files '*.rs' | grep -v '/vendor/' | wc -l
git ls-files '*.astro' | wc -l

# Rust vendored (must stay untouched)
git ls-files '*.rs' | grep '/vendor/'

# Licence coverage in first-party source
git grep -l 'SPDX-License-Identifier' -- '*.ts' '*.tsx' '*.js' '*.mjs' '*.cjs' '*.rs' \
  | grep -vE '/(vendor|dist|build|node_modules)/' | wc -l

# Shebang files (insert after line 1)
git grep -l '^#!' -- '*.ts' '*.tsx' '*.js' '*.mjs' '*.cjs' | wc -l

# Manifests without a licence field
for f in $(git ls-files '*package.json' | grep -v node_modules); do
  grep -q '"license"' "$f" || echo "$f"
done
```

---

## Appendix B — `.github/docs-scopes.json` invariants

1. Every scope has `lang`, `paths`, `manifest`, `fallback_licence`.
2. `paths` are globs evaluated against `git ls-files` (forward slashes only).
3. Scopes are disjoint; the checker fails on an overlap.
4. Every first-party file matches exactly one scope, or the check exits 2.
5. `exclude` is global and applied before scope matching.
6. Adding a scope is a code review, not a config toggle: it changes what CI
   enforces.
