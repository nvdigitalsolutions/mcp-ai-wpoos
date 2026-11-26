# 166 — Cross-language documentation contract (Unix Theory P8–P12)

**Status:** proposal (awaiting approval to implement)
**Date:** 2026-11-26
**Builds on:** `UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md` (P0–P7), the v1.2.19 SPDX licence wave (#7545), and `docs/developer/folder-readme-convention.md` (P7)
**Research:** [`166-cross-language-documentation-contract-research.md`](166-cross-language-documentation-contract-research.md)
**Plan:** [`166-cross-language-documentation-contract-implementation-plan.md`](166-cross-language-documentation-contract-implementation-plan.md)

---

## 1. Summary

The Unix Theory series and the v1.2.19 licence wave gave PHP a strict
documentation discipline: every file declares its licence and package, every
folder introduces itself, and a CI gate fails when either is missing. That
discipline stops at the `.php` glob.

The other languages in the tree are now large enough to matter:

| Surface | First-party files | Licence headers | File overviews | Enforced |
|---|---:|---:|---:|---|
| TypeScript / TSX | 1,144 | **0** | **0** | no |
| JavaScript / JSX / MJS / CJS | 944 | **0** | **0** | no |
| Astro | 106 | 0 | 0 | no |
| Rust | 153 (+3 vendored) | **0** | 131/132 sampled module docs | no |
| CSS / SCSS | 231 | 0 | 0 | no |

This proposal extends the **documentation** half of Unix Theory — not the
behavioural half — to those trees, as phases **P8–P12**:

| Phase | Deliverable |
|---|---|
| **P8** | Polyglot licence identity: SPDX on every first-party source file; boundary gate covers TS/JS/Rust; manifests declare a licence |
| **P9** | File-root purpose: every source file opens with a purpose statement sized to its class |
| **P10** | Public-surface documentation: TSDoc on exported TS symbols, rustdoc on public Rust items, with executable examples |
| **P11** | Folder READMEs for non-PHP source trees, reusing the P7 seven-section template |
| **P12** | Ratchet budgets + CI wiring so the contract survives the next 2,000 files |

The design principle is deliberately conservative: **adopt each language's native
documentation format** (`@fileoverview`, TSDoc, `//!`, rustdoc), and standardise
only the *shape, the required facts, and the enforcement*. No new comment dialect
is invented.

---

## 2. What "documentation at the root" means here

The phrase has three legitimate readings. All three are in scope, and they must
agree.

| Root | Artefact | Answers | Example |
|---|---|---|---|
| **File root** | the comment block above the first import/declaration | "What is this file for, and what must stay true?" | `@fileoverview` / `//!` |
| **Folder root** | `README.md` beside the sources | "What does this directory own, and how do I use it?" | P7 template |
| **Tree root** | the manifest (`package.json`, `Cargo.toml`, `composer.json`) | "What licence and entry points does this package have?" | `license`, `[package]` |

The **agreement rule** (§4.1) is the contract: a file's licence must equal its
nearest manifest's licence, and a folder README must describe the surface the
files actually export. A mismatch in either direction is a defect.

---

## 3. Measured current state (2026-11-26)

> **Post-P8 update.** The audit below is the **pre-implementation baseline** that
> motivated this proposal. P8 then inserted a verified licence line in every
> in-scope source file (2,237 insertions; 0 missing, 0 wrong). See §9 for the
> current numbers.

### 3.1 The gap is not stylistic — it is total on licences

Every `SPDX-License-Identifier` in the TS and JS trees is inside `vendor/`,
`dist/` or `build/`:

```
$ git ls-files '*.ts' '*.tsx' | grep -vE '/(vendor|dist|build|node_modules)/' | wc -l
1144
$ # of those, files carrying SPDX:
0
```

The vendored copies carry their upstream licences. The first-party code carries
none. That is the inverse of the intended state: the files the repo authors are
the ones with no licence identity, and the files it must not touch are the ones
that have it.

### 3.2 Four competing header styles

| Style | Example | Count |
|---|---|---|
| JSDoc purpose block | `addons/nvoos-astro-bridge/src/auth/permissions.ts` | small |
| ASCII banner | `examples/nvoos-astro-forge/lib/ssrf.ts` (`// --- ssrf.ts — …`) | small |
| Bare `import` on line 1 | most `addons/pro/assets/spa-v2/src/**` | majority |
| Nothing | tests, configs, most packages | majority |

None of them is wrong; the problem is that none is *required*, so the shape
depends on which agent or contributor touched the file last.

### 3.3 Rust is the mirror image

The Cognition crate already writes excellent module prose — 131 of 132 sampled
files open with `//!` — but has:

- **0** SPDX headers (the crate is proprietary; the files say nothing),
- **no** `[lints]` table in `Cargo.toml`,
- **no** `#![deny(missing_docs)]`,
- **no** `rustfmt.toml` / `clippy.toml`.

So the Rust work is mostly mechanical (licence + lint levels), while the TS work
is mostly authoring. The plan sequences accordingly.

### 3.4 Why the existing policy stopped at PHP

It is not a policy decision — it is a glob decision:

| Tool | Current scope |
|---|---|
| `bin/add-spdx-headers.php` | `preg_match('/^(?:\xEF\xBB\xBF)?<\?php/')` — skips every non-PHP file |
| `bin/check-license-boundary.sh` | `--include="*.php"` on every `check_tree` / `require_tree` call |
| `bin/check-folder-readmes.php` | walks `includes/` and `addons/pro/includes/` only |
| `license-boundary.yml` | runs the script above, nothing else |

The PHP wave proved the mechanics. Extending the globs is the smallest possible
change that closes the largest gap.

---

## 4. The contract

### 4.1 Three roots, one agreement rule

```
file header  ──licence──▶  must equal  ──▶  nearest manifest licence
     │
     └──purpose──▶ describes what the folder README claims the folder owns
```

| Layer | Authoritative for | Never authoritative for |
|---|---|---|
| Manifest | licence, package name, entry points, runtime/MSRV | behaviour, ownership of individual files |
| Folder README | responsibility, public surface, seams, tests, loading order | licence text, cross-cutting rules |
| File header | that file's responsibility and invariant | package metadata, API reference (that is the symbol docs) |

If two layers disagree, the **manifest wins on licence** and the **folder README
wins on responsibility**. The file header is corrected, not the other two.

### 4.2 File taxonomy — the Rule of Silence, applied

A blanket "document every file" mandate produces boilerplate in tests and
generated bindings. Each class gets a minimum bar instead:

| Class | Detected by | Licence | Purpose statement | Symbol docs | Notes |
|---|---|---|---|---|---|
| `entrypoint` | `index.ts`, `main.ts`, `lib.rs`, `main.rs`, `server.js` | ✅ | ✅ + public-surface map + start-up order | ✅ | the one file a reader opens first |
| `module` | any other `.ts`, `.tsx`, `.rs`, `.js`, `.mjs` | ✅ | ✅ | exports / public items only | the default |
| `component` | `.tsx`, `.astro` | ✅ | ✅ + props contract | props interface + exported component | |
| `test` | `*.test.*`, `*.spec.*`, `tests/**` | ✅ | ✅ 1–3 lines: what it pins + fixtures | ❌ | never an essay |
| `config` | `*.config.*`, `build.rs`, `vite.config.*`, `eslint.config.*` | ✅ | ✅ 1–3 lines: what it affects | ❌ | |
| `generated` | allowlisted paths, `@generated` marker | ✅ | ❌ | ❌ | must name the generator command |
| `vendored` | allowlisted paths (`vendor/`, `dist/`, `build/`, `node_modules/`) | inherited | ❌ | ❌ | untouched |

A file may only claim a class if the path matches the class's allowlist. Silent
exemption is not permitted: every exemption is a committed path pattern, so a new
generated directory is a reviewable change.

### 4.3 Canonical file-root headers

#### TypeScript / JavaScript (`.ts`, `.tsx`, `.js`, `.mjs`, `.cjs`)

```ts
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * @fileoverview Rejects unsafe checkout handoff URLs before a redirect.
 *
 * Invariant: the returned URL is same-site and on the allowlist; userinfo and
 * non-standard ports are rejected. Never performs the redirect or a network
 * call — the caller owns that.
 */
import { … } from '…';
```

Rules:

- SPDX is **line 1**. If the file has a shebang, SPDX is line 2.
- Exactly one blank line after the header block, then imports (Google TS guide's
  file structure).
- `@fileoverview` is the only overview tag. `@file` is accepted for
  compatibility; `@overview` is not used.
- No `@package`, no `@since`. Ownership lives in the folder README; stability
  lives in TSDoc release tags (§4.5). (`@packageDocumentation` on a package
  entry point is a different, TSDoc-standard tag — it is encouraged.)
- No JSDoc type annotations (`{string}`, `@returns {Foo}`) — TypeScript owns types.

#### Rust (`.rs`)

```rust
// SPDX-License-Identifier: LicenseRef-Proprietary
//! Checkout URL allowlist policy.
//!
//! Invariant: the returned URL is same-site and on the allowlist; userinfo and
//! non-standard ports are rejected. Performs no I/O — the controller owns the
//! request. Mirrors `addons/nvoos-astro-bridge/src/auth/permissions.ts`.
```

Rules:

- SPDX is **line 1**, before `//!`. This is the kernel/Rust convention and the
  only ordering REUSE accepts without ambiguity.
- `//!` is the module/crate purpose. `///` is for items (§4.5).
- Binary crates (`main.rs`) follow the same shape; they are `entrypoint` class.
- Tests in `tests/` use `//!` for the file's "what it pins" line, or a `//`
  comment if the harness requires it.

#### Astro (`.astro`)

```astro
---
// SPDX-License-Identifier: MIT
/**
 * @fileoverview Product detail page — server-rendered, no client islands.
 *
 * Reads `product` from the content layer; never fetches at request time.
 */
---
```

Rules:

- Header goes inside the frontmatter fence, where JS comment syntax is valid.
- A page that owns a layout contract names it in the overview.

#### Manifests and uncommentable files

`package.json`, `Cargo.toml`, JSON data, lockfiles and binaries cannot carry a
comment header. They are covered by a committed `REUSE.toml` (schema v1) with
`path` globs and the tree's SPDX identifier — not by 600 adjacent `.license`
files.

### 4.4 The purpose-statement bar

A purpose statement is 1–5 lines and answers three things:

| # | Question | Failure mode it prevents |
|---|---|---|
| 1 | **Responsibility** — what is the one thing this file does? | "utils.ts" with no stated scope |
| 2 | **Invariant** — what must remain true? | a refactor that quietly drops validation |
| 3 | **Non-goal** — what does this file deliberately not do? | duplicated logic creeping in |

It **must not** restate the filename, the type signature, or the language
mechanics. "This file contains helper functions" fails all three questions and is
rejected in review.

Good:

```
@fileoverview Normalises the checkout allowlist before a redirect.
Invariant: every accepted URL is same-site and on the operator allowlist.
Never performs the redirect — the caller owns the network and the response.
```

Bad:

```
@fileoverview Authentication helpers.
```

### 4.5 Public-surface documentation

#### TypeScript

| Item | Requirement |
|---|---|
| Every export | TSDoc block (`/** … */`), validated by `eslint-plugin-tsdoc` |
| Package entry point (`index.ts`) | `@packageDocumentation` block listing the exported surface |
| Stability | `@public` (stable), `@beta` / `@alpha` (preview), `@internal` (not API) |
| Deprecation | `@deprecated` **must name the replacement** (Google rule) |
| Failure | `@throws` on functions with documented failure modes |
| Examples | `@example` where the call is non-obvious; not compiled (TS has no doctest) |
| Types | never in JSDoc — TypeScript is the type source |

For the published packages under `packages/**`, the public surface is also pinned
by an API Extractor `.api.md` report; a change to it without a report update is a
contract break.

#### Rust

| Item | Requirement |
|---|---|
| Crate root | `//!` with a worked example (C-CRATE-DOC) |
| Every public item | `///` (enforced by `missing_docs`) |
| Fallible public fn | `# Errors` section (clippy `missing_errors_doc`) |
| Panicking public fn | `# Panics` section (clippy `missing_panics_doc`) |
| `unsafe` public fn | `# Safety` section (clippy `missing_safety_doc`) |
| Internal public items | `#[doc(hidden)]` or `pub(crate)` (C-HIDDEN) |
| Examples | compiled by `cargo test`; CI must run doctests |
| Links | intra-doc links; `broken_intra_doc_links` denied |

### 4.6 Folder README (P7, extended)

The seven required H2 sections stay exactly as they are — **Purpose, Tier,
Public Surface, Inputs / Outputs / Neighbors, Conventions, Tests, Also Load** —
so a reader does not learn a second template. Two optional sections are added for
non-PHP trees:

| Optional section | When |
|---|---|
| **Runtime** | Node version / browser target / Rust edition + MSRV |
| **Generated artefacts** | what is built here and must never be hand-edited |

The layering rule still applies: a folder README links to `.context/*` and
`CLAUDE.md`; it does not restate naming, security, or PHP-compat rules.

**Rust exception.** A Rust module directory is already documented by its `//!`
header, so a folder README there would duplicate it. A folder README is required
only where a directory is **not** a module: the crate root (`src-tauri/`), the
`examples/` probes, `tests/`, and non-Rust asset/config directories. The crate's
module tree is read through `lib.rs` and the `//!` blocks, not through READMEs.

### 4.7 What this contract is not

- Not a behaviour refactor. No TS/Rust logic changes in P8–P12.
- Not a documentation site. TypeDoc/rustdoc output is a *by-product*, not a
  deliverable.
- Not an i18n mandate.
- Not a rewrite of existing prose. Existing good docs are moved/normalised, not
  replaced.

---

## 5. Enforcement

Four gates, ordered by cost. A rule belongs to the cheapest gate that can
express it.

### 5.1 Gate 1 — polyglot mechanical checks (PR-blocking)

Extend the existing tools rather than adding a parallel system:

| Tool | Change |
|---|---|
| `bin/add-spdx-headers.php` | language-aware adapters (`//`, docblock, `#`), shebang/Astro-frontmatter handling, `--scope=`; still idempotent and dry-run by default; **never writes prose** |
| `bin/check-license-boundary.sh` | keeps the packaging and direction rules; delegates the file-level contract to `check-file-docs.php --strict` (budget-aware, so the recorded baseline is allowed) |
| `.github/docs-scopes.json` *(new)* | scope → globs → manifest authority → exemptions, plus the explicit `folder_readmes` target list |
| `bin/check-file-docs.php` *(new)* | taxonomy + header presence + purpose bar + manifest agreement + ratchet budget; `--json`, `--scope=`, `--strict`, `--update`, `--allow-increase`, `--audit-scopes`, `--licence-only`, `--new-files-only`, `--folder-docs`, `--self-test` |
| `bin/check-folder-readmes.php` | unchanged for PHP; non-PHP folders are listed in `.github/docs-scopes.json` and checked by `check-file-docs.php --folder-docs` (Rust module dirs are documented by `//!`, not READMEs) |
| `REUSE.toml` *(new)* | licence annotations for JSON/TOML/lockfiles/binaries |

The new checker is deliberately **text-based, not AST-based**: it must run in
milliseconds, before `npm ci` / `cargo fetch`, and it must be readable by a
contributor in one sitting. AST-level checks are delegated to ESLint and rustc.

### 5.2 Gate 2 — native linters (per tree)

| Tree | Configuration |
|---|---|
| TS per-addon `eslint.config.js` | `jsdoc/require-file-overview`, `jsdoc/require-jsdoc` (`publicOnly`), `jsdoc/require-description`, `jsdoc/no-types`, `tsdoc/syntax` |
| Root `eslint.config.cjs` | same rules scoped to `assets/js/src/**` and `src/**` |
| Rust `Cargo.toml` | `[lints.rust] missing_docs = "deny"`, `[lints.rustdoc] broken_intra_doc_links = "deny"`, `[lints.clippy]` pedantic trio |

`jsdoc/check-values` is deliberately **not** used: the licence lives in the SPDX
line, and a second `@license` tag would create two sources of truth.

### 5.3 Gate 3 — ratchet budgets (the adoption mechanism)

A 2,245-file in-scope mandate cannot land as a single red build. `.github/docs-budget.json`
records the current debt per scope, modelled on the existing
`.github/phpcs-budget.json` and `.github/scripts/phpcs-ratchet.php`:

```json
{
  "version": 1,
  "tolerance": 25,
  "scopes": {
    "ts:spa-v2":    { "files": 212, "missing_spdx": 0, "missing_overview": 212 },
    "rust:dictate": { "files": 153, "missing_spdx": 0, "missing_overview":   1 }
  }
}
```

Five rules, copied from the PHPCS ratchet because they are known to work:

1. No scope may exceed a recorded **debt** count (`missing_spdx`, `wrong_spdx`,
   `missing_overview`). The `files` count is informational: a new compliant file
   is the goal, not debt.
2. No stale slack — a scope more than `tolerance` under budget fails, so an
   improvement must be recorded in the same PR.
3. New files are always in scope; they must be clean on arrival.
4. Named scopes carry their own budgets; there is no global average.
5. `--update` refuses to raise a budget without `--allow-increase`, which must be
   justified in the PR body.

### 5.4 Gate 4 — drift and surface reports (non-blocking first)

| Check | Purpose | Blocking? |
|---|---|---|
| `cargo test --doc` | examples compile and pass | yes |
| `cargo doc --no-deps` with `RUSTDOCFLAGS="-D warnings"` | rustdoc warnings are real | yes |
| TypeDoc validation report | undocumented exports, broken links | no (report) |
| API Extractor `.api.md` diff | public-surface contract | yes for `packages/**` |
| `reuse lint` | full REUSE compliance | no (report; revisit) |

### 5.5 Exemptions

| Case | Handling |
|---|---|
| Vendored code | path allowlist (`**/vendor/**`, `**/assets/vendor/**`) |
| Build output | path allowlist (`**/dist/**`, `**/build/**`, `**/target/**`) |
| Generated code | `@generated` + generator command in the header |
| Third-party `.d.ts` | allowlisted by path, never edited |
| Astro generated route types | `@generated` |
| Empty `index.ts` barrels | still require a header; one line is enough |

An exemption is a committed pattern, not a comment in the PR description.

---

## 6. Phases P8–P12

### P8 — Licence identity (mechanical, ships first)

Extend the inserter and the boundary gate to every first-party source file.
Fix the manifests that declare no licence. Add `REUSE.toml` for uncommentable
files. **No prose is written in this phase** — the diff is a licence line per
file, which keeps review honest.

Acceptance: boundary gate green across PHP + TS/JS/Rust; every manifest declares
an SPDX licence; the diff is insert-only except the manifest fixes.

### P9 — File-root purpose

Introduce the taxonomy and the new checker; baseline every scope; require clean
headers on new files. Author purpose statements for the highest-value trees first
(`packages/**`, `addons/nvoos-astro-bridge/src`, `spa-v2/src/api`,
`examples/nvoos-dictate/src-tauri/src`). Burn the baseline down by scope.

Acceptance: no new file lands without a purpose statement; every scope is at or
under budget; the ratchet has been lowered at least once.

### P10 — Public surface

TSDoc on exported symbols; rustdoc lints on; doctests in CI; release tags and an
API report for published packages. This is where the contract becomes useful to
consumers rather than just to maintainers.

Acceptance: `cargo doc` produces zero warnings; `eslint` clean for the JSDoc
rules in adopted trees; `packages/**` publishes with an `.api.md` report.

### P11 — Folder READMEs

Reuse the P7 template across non-PHP source trees. The plan tiers 115 candidate
directories down to the ones that are genuine modules (packages, example apps,
addon `src/` trees); fixtures, generated directories and single-file folders are
exempt by pattern.

Acceptance: `php bin/check-folder-readmes.php --scope=all` is green; every
README links to the canonical context files instead of restating them.

### P12 — Ratchet close-out

Wire `docs:check-contract` into `composer run ci:all` and the relevant GitHub
workflows; update `CLAUDE.md`, `AGENTS.md`, `.context/conventions.md`, and the
coding-time skills; add the contract to the PR template.

Acceptance: a new TS or Rust file that omits its header fails CI in under a
minute; the docs budget is at zero for all adopted scopes; the skill/context
surfaces describe the contract accurately.

**Dependency order:** P8 → P9 → P10 → P11 → P12. P10 may run in parallel with
P11; P12 is last because it locks the budgets.

---

## 7. Decisions

| # | Decision | Rationale | Rejected alternative |
|---|---|---|---|
| **D1** | Use each language's native format; invent no dialect | Tools already parse `@fileoverview`, TSDoc and rustdoc | A bespoke `NV-DOC:` block parsed by a custom script |
| **D2** | Licence first, purpose second, code third | REUSE requires the header as close to the top as possible; a copied file keeps its licence even if prose is stripped | Purpose first, licence at the bottom |
| **D3** | The nearest manifest is the licence authority | One place to change; enables a machine comparison | Per-file licence as the source of truth |
| **D4** | No `@package` / `@since` in TS/Rust headers | `@package` is ambiguous (Java visibility), `@since` drifts from package semver; ownership belongs to the folder README | Mirror the PHP docblock verbatim |
| **D5** | Three roots (file, folder, tree) with an agreement rule | Catches the "header says MIT, manifest says proprietary" class of bug | Check file headers only |
| **D6** | File taxonomy with minimum bars | Prevents boilerplate in tests and generated code; honours the Rule of Silence | One bar for every file |
| **D7** | Ratchet, not big bang | 2,245 in-scope files; a red build teaches contributors to bypass the gate | Require 100% before merging the gate |
| **D8** | Generated/vendored excluded by committed path patterns | Silent exemptions are unauditable | Exempt by comment |
| **D9** | One new checker; extend three existing tools | Minimises surface area and keeps the PHP gates authoritative | A parallel docs system in Node |
| **D10** | Never auto-generate prose; only licence and `@generated` lines are machine-written | Generated prose is noise and erodes trust in the headers | Generate `@fileoverview` from the filename |

---

## 8. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Boilerplate headers ("This file contains functions") | high | the gate becomes theatre | §4.4 information bar; review checklist; checker rejects filename restatement heuristically |
| Manifest/header drift | medium | wrong licence ships | D3 + Gate 1 comparison; exceptions are committed patterns |
| Mechanical PR obscures semantic review | high | a real change hides in 2,000 lines | P8 is licence-only; prose lands in P9+; `.gitattributes` marks the diff as generated where appropriate |
| Agent context bloat | medium | slower sessions | headers stay ≤ 8 lines; folder READMEs are load-on-demand; no mega-doc |
| Lint fatigue in legacy trees | high | contributors disable rules | budgets + tree-by-tree adoption; no rule lands on a tree before its baseline exists |
| Generated files trip the checker | medium | false failures | `@generated` + allowlists; the checker reports the class it inferred |
| Licence misclassification of vendored code | medium | boundary gate false positive | `REUSE.toml` + path allowlists; audit step in P8 |
| Astro frontmatter stripped in build | low | no runtime effect | header is authoring-time only; no runtime consumer |
| Rust doctests slow CI | low | longer builds | doctests are already fast; run them in the existing Rust job |

---

## 9. Success metrics

| Metric | Baseline (2026-11-26) | Target |
|---|---:|---:|
| First-party TS/JS/Astro files with SPDX | 0 → **2,094 / 2,094** (in scope) | 100% |
| Rust files with SPDX | 0 → **153 / 153** | 100% |
| First-party manifests declaring a licence | 6 fixed | 100% |
| TS files with a purpose statement | 0 / 1,144 (2,078 in-scope files below the bar, baselined) | 100% (ratcheted) |
| Rust files with module docs | 131/132 sampled → **153 / 153** | 100% |
| Exported TS symbols with TSDoc in `packages/**` | unmeasured | 100% |
| Public Rust items with docs | **739 missing** (measured) | best-effort; bar reduced per P10.3 |
| Non-PHP source folders with a README | 3 Rust non-module folders | Tier A/B/C TS folders |
| Boundary gate languages covered | 1 → **5** (PHP, TS, JS, Astro, Rust) | 5 |

Vanity metrics explicitly rejected: line counts, doc-site page counts, and
"percentage documented" computed by a tool that counts `/** */` blocks.

---

## 10. Non-goals

- No behavioural change to TS, JS, Astro or Rust code.
- No new documentation website or hosted docs.
- No requirement to document private Rust items (the restriction lint stays
  opt-in).
- No headers on vendored code.
- No CSS/SCSS mandate in P8–P12 (231 files; follow-up if the contract holds).
- No i18n of comments.
- No retroactive rewrite of good existing prose.

---

## 11. Open questions

1. **`assets/js/**` scope.** 1,000+ legacy JS files. P9 should adopt `src/**`
   first and decide whether `assets/js/**` is in scope or frozen.
2. **CSS/SCSS.** 231 first-party files. Same shape (`/* SPDX */` + purpose), but
   deferred until the JS/TS adoption proves the ratchet.
3. **`reuse lint` in addition to the boundary gate.** Adds full REUSE compliance
   but demands a `LICENSES/` directory layout; decide after P8.
4. **`@internal` policy for TS.** Whether unpublished internal exports must be
   tagged `@internal` or merely left undocumented.
5. **Astro components.** Whether `.astro` components need a props contract or
   only a purpose line.

---

## Appendix A — Licence map rule and verified samples

**Rule:** the header equals the nearest manifest's `license` field, normalised to
an SPDX expression; when a manifest has no field, the nearest ancestor manifest
(in the same product) applies; explicit exceptions are committed in
`REUSE.toml` with a comment. `UNLICENSED` maps to `LicenseRef-Proprietary`.

Verified samples (2026-11-26; the P8 audit produces the authoritative map):

| Tree | Evidence | SPDX |
|---|---|---|
| `assets/js/**`, `src/**` | root `package.json` | `GPL-3.0-or-later` |
| `packages/**` | `package.json` | `MIT` |
| `examples/nvoos-astro-forge/**` | `LICENSE` + manifest | `MIT` |
| `examples/nvoos-astro-velocity/**` | `LICENSE` | `MIT` |
| `examples/nvoos-dictate/**` | `Cargo.toml` `license = "proprietary"`, npm `UNLICENSED` | `LicenseRef-Proprietary` |
| `addons/mcp-gateway/**` | `package.json` | `GPL-3.0-or-later` |
| `addons/docs-hub/**` | `LICENSE` (GPLv3), no manifest field | `GPL-3.0-or-later` |
| `addons/nvoos-astro-bridge/**` | `LICENSE` (Proprietary) | `LicenseRef-Proprietary` |
| `addons/algorave/**` | `package.json` | `AGPL-3.0-or-later` |
| `addons/pro/assets/spa-v2/**` | no field → inherits Pro | `LicenseRef-Proprietary` |
| `examples/nvoos-service-exchange/**`, `nvoos-pro-spa-vite/**`, `nvoos-complete-velocity/**` | `UNLICENSED` | `LicenseRef-Proprietary` |
| `addons/media-worker/**` | `package.json` `UNLICENSED` (separately-distributed service) | `LicenseRef-Proprietary` |
| `examples/qb-online-mcp/**` | `package.json` `UNLICENSED`, `private: true` | `LicenseRef-Proprietary` |
| `examples/qb-desktop-mcp/**` | `LICENSE` + `pyproject.toml` `license = "MIT"` (upstream fork) | `MIT` |
| `addons/pro/assets/vendor/**` | upstream | excluded (vendored) |
| `examples/nvoos-dictate/vendor/whisper-rs-sys/**` | vendored crate (3 `.rs` files) | excluded (vendored) |
| `addons/canvas/assets/canvas/**` | `package.json` MIT, vendored lib | `MIT` (vendored exception) |
| `docker/laravel/app-skeleton/**` | Proposal 149 (Commercial) | `LicenseRef-Proprietary` |

`addons/canvas/assets/canvas/**` is the one case where an MIT header legitimately
sits inside a proprietary *product* tree: the vendored directory is a distinct
artefact. It must be an explicit `REUSE.toml` exception so the boundary rule
stays "direction matters" rather than "MIT never appears here".

### Decision log — the two scopes held at "licence decision required"

Closed 2026-11-26. `addons/media-worker/**` (67 files) and
`examples/qb-online-mcp/**` (11 files) were the only P8 scopes with no decisive
licence source. Both resolve to `LicenseRef-Proprietary`:

- **`addons/media-worker`** is a separately-deployed Design Stack service, not
  part of the GPL-3.0-or-later WordPress plugin ZIP (`.distignore` excludes
  `addons/`). Its manifest declares `UNLICENSED`, which under npm semantics
  means "no licence granted". P8b added that field to the manifest; the 67
  source files carry the matching header.
- **`examples/qb-online-mcp`** is an original first-party example
  (`private: true`, `UNLICENSED`). It is not a fork, so the MIT grant on the
  sibling `examples/qb-desktop-mcp` — which derives from its upstream
  `lilfroog/qbbridge` provenance — does **not** flow down. If the owner later
  publishes the example under MIT, flip the manifest and the headers in one
  change; the ratchet flags the drift either way.

`LicenseRef-Proprietary` is a REUSE custom reference, so its text is committed
to `LICENSES/LicenseRef-Proprietary.txt`. That keeps a later `reuse lint` able
to resolve every identifier the tree uses without deciding the fuller
`LICENSES/` layout now (open question 3).

---

## Appendix B — Header examples by class

### `entrypoint` — TS package

```ts
// SPDX-License-Identifier: MIT
/**
 * @packageDocumentation
 * Public surface of `@nvoos/api`: client factory, typed errors, and the
 * canonical envelope decoder.
 *
 * Start here. Import order matters: `createApiClient` must be constructed
 * before any resource helper; the helpers read the client's resolver.
 */
```

### `module` — Rust

```rust
// SPDX-License-Identifier: LicenseRef-Proprietary
//! Storage for the meeting transcript buffer.
//!
//! Invariant: a flush writes the whole buffer or nothing; a partial write is
//! treated as corruption and the buffer is retained. Never deletes the previous
//! transcript — retention is the caller's policy.
```

### `test`

```ts
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * @fileoverview Pins the checkout URL policy: same-site, allowlist, userinfo.
 * Fixtures: `__fixtures__/checkout-urls.json`.
 */
```

### `config`

```ts
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * @fileoverview Vite build for the Pro SPA.
 *
 * Owns the alias map onto `spa-v2` sources and the two-bundle split. Changing
 * `entry` without the matching PHP enqueue breaks the admin page.
 */
```

### `generated`

```ts
// SPDX-License-Identifier: GPL-3.0-or-later
// @generated by `npm run build:routes` — do not edit.
```

---

## Appendix C — Mapping to the Unix rules

| Unix rule | P8–P12 artefact |
|---|---|
| Modularity | One responsibility per file, named in the header |
| Composition | Folder README's Inputs / Outputs / Neighbors |
| Separation | Licence (manifest) ≠ responsibility (README) ≠ reference (symbol docs) |
| Transparency | A reader knows what a file does without running it |
| Representation | The public surface is explicit in `@packageDocumentation` / rustdoc |
| Parsimony | File-class minimum bars; no restating types or names |
| Silence | Tests and configs get one line; generated files get none |
| Least surprise | The same shape in every language, adapted only in comment syntax |
