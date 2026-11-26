# 166 — Cross-language documentation contract: research

**Status:** research (feeds the proposal)
**Date:** 2026-11-26
**Feeds:** [`166-cross-language-documentation-contract-proposal.md`](166-cross-language-documentation-contract-proposal.md)
**Plan:** [`166-cross-language-documentation-contract-implementation-plan.md`](166-cross-language-documentation-contract-implementation-plan.md)

---

## 1. The question

The repo applies a documentation discipline to PHP that it has never applied to
TypeScript, JavaScript, Astro or Rust. This document surveys what the industry
actually standardises in those languages, so the proposal can adopt native
formats instead of inventing a house dialect.

Every standard below was judged on three questions:

| # | Question | Why it decides adoption |
|---|---|---|
| Q1 | Does it define a **file-root** shape (what must appear before the first import/declaration)? | That is the PHP `@package` + `@license` + SPDX header being ported |
| Q2 | Is it **machine-checkable** without a bespoke parser? | A convention that only a reviewer can enforce decays |
| Q3 | Does it survive a **polyglot monorepo** (one repo, four licences, five runtimes)? | NV oOS is not a single-language project |

A standard that answers Q1 and Q2 but not Q3 (e.g. a single-language style guide)
is still useful as *shape*, but cannot be the enforcement layer.

---

## 2. What P0–P7 actually established

The [Unix Theory Compliance proposal](UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md)
landed eight phases. Only two of them are documentation artefacts, and only one
of those is language-agnostic in principle:

| Phase | Artefact | Kind | Language-specific? |
|---|---|---|---|
| P0/P1 | Canonical return envelope | Behaviour | PHP |
| P2 | Capability fence for optional deps | Behaviour | PHP |
| P3 | `produces` / `consumes` data contract | Metadata | PHP |
| P4 | Structured lifecycle hook payload | Behaviour | PHP |
| P5 | Back-compat alias infrastructure | Behaviour | PHP |
| P6 | Sanitise-at-entry / escape-at-exit | Behaviour | PHP |
| **P7** | **Folder README convention** | **Documentation** | **No — but implemented PHP-only** |
| *SPDX wave* | `bin/add-spdx-headers.php` + `bin/check-license-boundary.sh` | Documentation | **PHP-only by glob** |

The port target is therefore narrow and non-controversial: **P7 plus the SPDX
header wave**, re-expressed for the other languages. P0–P6 have no TS/Rust
analogue because those trees do not implement WordPress tools.

This matters for scoping: the proposal is a *documentation* contract, not a
behavioural refactor of 2,247 in-scope source files.

---

## 3. Unix rules that survive translation

The [Art of Unix Programming](http://www.catb.org/~esr/writings/taoup/html/ch01s06.html)
states the design rules the original proposal paraphrased. Seven of them map
directly onto documentation artefacts in any language:

| Unix rule | Documentation consequence |
|---|---|
| Rule of Modularity | One file, one responsibility — the header names it |
| Rule of Composition | The header declares inputs, outputs and seams |
| Rule of Separation | Policy (README) is separated from mechanism (code) |
| Rule of Transparency | A reader can see what a file does without running it |
| Rule of Representation | The folder README makes the external contract explicit |
| Rule of Parsimony | Do not write what the type system or the name already says |
| **Rule of Silence** | When a file has nothing surprising to say, it should say nothing — hence a minimum bar per file class, not an essay per file |

The last rule is the one most often lost when a "document every file" mandate is
introduced. Google's style guide states the equivalent for JSDoc: *"Make comments
that actually add information"* and *"Avoid merely restating the property or
parameter name."* Both are cited in §4.1.

---

## 4. TypeScript / JavaScript

### 4.1 Google TypeScript Style Guide — the file-root shape

The [Google TypeScript Style Guide](https://google.github.io/styleguide/tsguide.html)
is the only widely-adopted TS guide that prescribes an ordered file root:

> Files consist of the following, **in order**: Copyright information, if present;
> JSDoc with `@fileoverview` and/or other file-level JSDoc tags if present;
> imports, if present; the file's implementation. **Exactly one blank line**
> separates each section that is present.

It also fixes four rules the proposal reuses verbatim:

| Rule | Text (paraphrased) |
|---|---|
| File overview | `@fileoverview` "may provide a description of the file's content, its uses, or information about its dependencies" |
| Exports | "Document all top-level exports of modules" |
| Types | "JSDoc type annotations are redundant in TypeScript source code" — do not write `{type}` in `@param`/`@return` |
| Deprecation | Mark with `@deprecated`; the comment "must include simple, clear directions for people to fix their call sites" |

The guide is explicit that `@param`/`@return` lines are only required when they
add information — which is the Rule of Silence expressed as lint guidance.

### 4.2 TSDoc — the comment *syntax* standard

[TSDoc](https://tsdoc.org/) is Microsoft's proposal to standardise TypeScript doc
comments so that "different tools can extract content without getting confused by
each other's markup." Two parts matter here:

1. **Package entry point marker.** TSDoc defines `@packageDocumentation` for the
   file that serves as a package's entry point. That is the natural place for the
   "public surface map" the PHP folder README carries.
2. **Release tags.** `@public`, `@beta`, `@alpha`, `@internal` classify an
   exported symbol's stability. [API Extractor](https://api-extractor.com/pages/tsdoc/doc_comment_syntax/)
   consumes them and emits an `.api.md` report — a diffable public-API contract.

TSDoc is a *syntax and tag* standard, not a file-header mandate. It answers Q2
(via `eslint-plugin-tsdoc`) but not Q1.

### 4.3 `eslint-plugin-jsdoc` — the enforcement that already exists

[`eslint-plugin-jsdoc`](https://github.com/gajus/eslint-plugin-jsdoc) is the
mature enforcement layer. The relevant rules:

| Rule | What it enforces | Fit |
|---|---|---|
| `jsdoc/require-file-overview` | Every file has `@file`, `@fileoverview` or `@overview`; not duplicated; must be "at the beginning of the file" (`initialCommentsOnly`) | **Exact Q1 match** |
| `jsdoc/require-jsdoc` (`publicOnly`) | Exported symbols are documented | Q2 for the public surface |
| `jsdoc/check-values` (`allowedLicenses`) | A licence tag is present and on an allow-list | Licence guard, if a `@license` tag is used |
| `jsdoc/no-types` | No JSDoc `{type}` in TS | Encodes Google's redundancy rule |
| `jsdoc/require-description` | A description, not just tags | Raises the information bar |

`require-file-overview` explicitly tolerates a preceding comment before the
overview block ("where beginning of the file is interpreted … as being when the
overview tag is not preceded by anything other than a comment"), which means an
SPDX line comment can sit on line 1 above the JSDoc block without violating it.

### 4.4 TypeDoc and API Extractor — the drift gates

- [TypeDoc](https://typedoc.org/) generates API docs from TSDoc and exposes
  validation options (undocumented exports, invalid links, non-exported
  references). Useful as a report, not a hard gate at first.
- [API Extractor](https://api-extractor.com/) produces an `.api.md` report and
  fails when the public surface changes without the report being updated. That is
  the TS analogue of the repo's tool-schema contract gate.

### 4.5 Measured adoption in this repo (2026-11-26)

| Metric | Count | Note |
|---|---:|---|
| Tracked `.ts` / `.tsx` | 2,654 | includes vendor + build output |
| First-party `.ts` / `.tsx` (excludes `vendor/`, `dist/`, `build/`, `node_modules/`) | **1,144** | the real surface |
| … carrying `SPDX-License-Identifier` | **0** | all 341 SPDX-tagged TS files are vendored `.d.ts` |
| … carrying `@fileoverview` | **0** | convention never adopted |
| … carrying an `@file` tag | 8 | isolated |
| First-party `.js` / `.jsx` / `.mjs` / `.cjs` | **944** | |
| … carrying `SPDX-License-Identifier` | **0** | all 338 SPDX-tagged JS files are vendor/dist |
| `.astro` | **106** | no header convention |
| Manifests with **no** `license` field | ≥ 6 first-party (`spa-v2`, `qb-desktop-mcp`, `qb-online-mcp`, `forge-bridge`, `content-graph`, `media-worker`) | C-METADATA gap |

At least four header styles coexist today: a JSDoc block (the bridge), an ASCII
`// ---` banner (`examples/nvoos-astro-forge/lib/ssrf.ts`), a bare `import` on
line 1 (most SPA files), and nothing.

### 4.6 What the TS standards leave unspecified

- **A required information bar.** Google says "may"; it does not say what a
  `@fileoverview` must contain. A file can satisfy it with `@fileoverview Foo`.
- **A file taxonomy.** No standard distinguishes an entry point from a test or a
  generated binding.
- **Licence placement.** Google says "if present"; REUSE (§6) is the only
  standard that says where and in what form.
- **Private-symbol docs.** Optional in every TS guide.

These four gaps are the parts NV oOS must own.

---

## 5. Rust

### 5.1 Rust API Guidelines — the documentation chapter

The [Rust API Guidelines](https://rust-lang.github.io/api-guidelines/documentation.html)
define the content expectations:

| Guideline | Requirement |
|---|---|
| **C-CRATE-DOC** | Crate-level docs are thorough and include examples (RFC 1687) |
| **C-EXAMPLE** | Every public item has a rustdoc example |
| **C-QUESTION-MARK** | Examples use `?`, not `unwrap()` |
| **C-FAILURE** | Function docs include **`# Errors`**, **`# Panics`** and **`# Safety`** sections |
| **C-LINK** | Prose links relevant items (intra-doc links) |
| **C-METADATA** | `Cargo.toml` includes `authors`, `description`, `license`, `repository`, `keywords`, `categories` |
| **C-RELNOTES** | Release notes document all significant changes |
| **C-HIDDEN** | Rustdoc does not show unhelpful implementation details (`#[doc(hidden)]`, `pub(crate)`) |

C-METADATA is the Rust analogue of the missing `license` fields found in §4.5:
the manifest is part of the documentation contract.

### 5.2 RFC 1574 / RFC 1687 — the conventions

[RFC 1574](https://rust-lang.github.io/rfcs/1574-more-api-documentation-conventions.html)
("API documentation conventions") is the normative source for the summary-sentence
shape, the `# Errors` / `# Panics` / `# Safety` sections, and "link all the
things". [RFC 1687](https://rust-lang.github.io/rfcs/1687-crate-level-docs.html)
covers crate-level documentation. Together they answer Q1 for Rust: `//!` at the
crate/module root, `///` on items, in that order.

### 5.3 rustdoc lints — the machine-checkable subset

The [rustdoc book](https://doc.rust-lang.org/rustdoc/lints.html) documents the
lints that make documentation a gate:

| Lint | Default | What it catches |
|---|---|---|
| `missing_docs` | allow (available from `rustc`) | Undocumented public items |
| `missing_crate_level_docs` | allow | No crate-level docs at all |
| `broken_intra_doc_links` | **warn** | Unresolved `[`links`]` |
| `private_intra_doc_links` | **warn** | Public docs linking private items |
| `bare_urls` | **warn** | URLs that are not links |
| `unescaped_backticks` | allow | Broken inline code |
| `invalid_rust_codeblocks` | **warn** | Code blocks that do not parse |
| `invalid_html_tags` | **warn** | Unclosed tags |

Because `missing_docs` is available from `rustc`, `#![deny(missing_docs)]` in
`lib.rs` is a compile-time gate — no extra tooling, no runtime cost.

### 5.4 Clippy — the content lints

Four clippy lints cover the API-guideline content rules that rustdoc does not:

| Lint | Group | Enforces |
|---|---|---|
| `clippy::missing_errors_doc` | pedantic | `# Errors` on fallible public fns |
| `clippy::missing_panics_doc` | pedantic | `# Panics` on panicking public fns |
| `clippy::missing_safety_doc` | pedantic | `# Safety` on `unsafe` public fns |
| `clippy::missing_docs_in_private_items` | restriction | Private items too |

The pedantic trio is the right default for public libraries. The restriction lint
is opt-in per-tree (it is noisy on application code, which is most of this repo).

### 5.5 Cargo `[lints]` and doc tests

- Cargo's `[lints]` table (stable since Rust 1.74; this repo's MSRV is 1.90)
  centralises lint levels per crate instead of repeating attributes in `lib.rs`.
- Rust doc examples are **compiled and run by `cargo test`** by default. That
  makes documentation executable: a stale example is a failing test. The gate is
  only real if CI runs doctests (not `cargo test --lib` alone).

### 5.6 Measured adoption in this repo (2026-11-26)

| Metric | Count | Note |
|---|---:|---|
| Tracked `.rs` | 156 | **153 first-party** + 3 vendored (`vendor/whisper-rs-sys`, excluded) |
| Files whose first non-blank line is `//!` | **131 of 132 sampled** | module prose is already excellent |
| Files carrying `SPDX-License-Identifier` | **0** | licence identity absent |
| `[lints]` section in `Cargo.toml` | **absent** | no lint gate |
| `#![deny(missing_docs)]` | **absent** | public items unenforced |
| `rustfmt.toml` / `clippy.toml` / `.rustfmt.toml` | **absent** | formatting not pinned |

The Rust tree is the mirror image of the TS tree: strong prose, no identity and
no gate. This is a useful asymmetry — the Rust work is mostly *mechanical*
(licence line + lint levels), while the TS work is mostly *authoring*.

### 5.7 What the Rust standards leave unspecified

- **Licence headers.** Cargo metadata covers the crate, not the file; REUSE (§6)
  fills the gap.
- **Folder-level docs.** `//!` documents a module, not a directory that is not a
  module (e.g. `tests/`, `examples/`, `capabilities/`).
- **Application crates.** The API guidelines are written for libraries; a Tauri
  binary needs a reduced bar (no `@public` release tags, no API report).

---

## 6. Licensing headers across languages

### 6.1 REUSE 3.3 — the only cross-language standard

The [REUSE Specification 3.3](https://reuse.software/spec-3.3/) (FSFE, 2024-11-14)
is the one standard that answers Q1 and Q3 for *licensing* in every language:

| Requirement | Detail |
|---|---|
| Comment header | Licensing information should sit as close to the top of the file as possible, in a comment header |
| SPDX tags | One or more `SPDX-FileCopyrightText` and one or more `SPDX-License-Identifier` tag-value pairs |
| Licence expression | `SPDX-License-Identifier` must be followed by a valid SPDX licence expression (e.g. `GPL-3.0-or-later OR Apache-2.0`) |
| Uncommentable files | An adjacent `<name>.license` file **or** a `REUSE.toml` annotation |
| Bulk directories | `REUSE.toml` (schema v1) with `path`, `precedence`, `SPDX-FileCopyrightText`, `SPDX-License-Identifier` |
| Non-SPDX licences | `LicenseRef-[idstring]` (the repo already uses `LicenseRef-Proprietary`) |
| Snippets | `SPDX-SnippetBegin` / `SPDX-SnippetCopyrightText` / `SPDX-SnippetEnd` |
| Tooling | `reuse lint` (Codeberg), `reuse annotate` |

REUSE is explicitly designed so that "the information is preserved when the file
is copied and reused by third parties" — the exact failure the repo's licence
boundary gate exists to prevent.

### 6.2 SPDX short form

SPDX defines the `SPDX-License-Identifier: <expression>` short form used by the
Linux kernel and most modern Rust and JS projects. For a licence not on the SPDX
list, `LicenseRef-` is the sanctioned escape hatch. This is already the repo's
convention for the proprietary trees.

### 6.3 Precedents

| Project | Convention |
|---|---|
| Linux kernel | `// SPDX-License-Identifier: GPL-2.0` on line 1, before everything |
| Rust crates | `// SPDX-License-Identifier: MIT OR Apache-2.0` then `//!` crate docs |
| Chromium / Google | Copyright block first, then `@fileoverview` |
| REUSE adopters | SPDX tags in the file's native comment syntax, no prescribed prose |

The common shape is: **licence first, purpose second, code third**. No project
puts the purpose before the licence, because a copied file must carry its licence
even if the prose is stripped.

### 6.4 Tooling landscape

| Tool | Use | Fit |
|---|---|---|
| `reuse lint` | Full REUSE compliance | Strong, but demands `LICENSES/` layout |
| `addlicense` (Google) | Insert headers from a template | Language-aware, no purpose prose |
| `license-eye` | Header check + fix | Config-driven, glob-based |
| `bin/check-license-boundary.sh` (this repo) | Direction-aware boundary | Already exists; PHP-only |

The repo's existing script is stricter than all three on *direction* (MIT may flow
into GPL, never back), which is why the proposal extends it rather than replacing
it with `reuse lint`.

### 6.5 Measured adoption in this repo (2026-11-26)

| Tree | Header coverage |
|---|---|
| `includes/` (PHP) | SPDX on every file (v1.2.19 wave) |
| `lib/core/src/` (PHP) | SPDX on every file |
| `addons/pro/includes/` (PHP) | SPDX on every file |
| First-party TS/JS | **0** |
| Rust | **0** |
| Manifests | at least 6 first-party `package.json` files have no `license` |

The PHP wave proved the mechanics; it simply never had a non-PHP glob.

---

## 7. Documentation genres — where each artefact belongs

Documentation mandates fail when every artefact is asked to do every job. The
[Diátaxis](https://diataxis.fr/) framework separates four genres:

| Genre | Answers | NV oOS artefact |
|---|---|---|
| Tutorial | "Teach me" | `docs/guides/` |
| How-to | "Help me do X" | `docs/guides/developer/` |
| **Reference** | "Tell me precisely" | **File headers, TSDoc, rustdoc** |
| **Explanation** | "Help me understand" | **Folder READMEs, proposals** |

This is why a file header must not become a tutorial and a folder README must not
become an API reference. It also justifies the Rule of Silence: a test file is
reference-grade only at the level of "what it pins".

Two supporting conventions:

- [Standard Readme](https://github.com/RichardLitt/standard-readme) — section
  ordering for README files. The existing P7 template already follows its spirit.
- ADR / [MADR](https://adr.github.io/madr/) — the decision-record shape the
  proposal's §7 uses (context → decision → consequences → alternatives).

Finally, *docs-as-code* supplies the only defence against drift: the document
lives beside the code and a gate fails when they disagree. The folder README
convention already embodies this; the proposal extends the same idea to headers.

---

## 8. Cross-language comparison

| | TypeScript / JS | Rust | PHP (existing) |
|---|---|---|---|
| Licence line | `// SPDX-License-Identifier: …` (line 1) | `// SPDX-License-Identifier: …` (line 1) | inside the file docblock |
| Purpose marker | `@fileoverview` JSDoc | `//!` module doc | docblock summary |
| Symbol docs | TSDoc `/** … */` | `///` | PHPDoc |
| Stability tags | `@public` / `@beta` / `@alpha` / `@internal` | `#[doc(hidden)]`, `pub(crate)` | `@since` |
| Failure sections | `@throws` | `# Errors` / `# Panics` / `# Safety` | `@throws` (rare) |
| Linter | `eslint-plugin-jsdoc`, `eslint-plugin-tsdoc` | `rustc` + rustdoc lints + clippy | PHPCS + custom sniffs |
| Doc generator | TypeDoc / API Extractor | rustdoc | — |
| Doc tests | none (examples are illustrative) | `cargo test` (doctests) | none |
| Manifest licence | `package.json#license` | `Cargo.toml#package.license` | `composer.json` / header |
| Folder docs | none standard | none standard | **P7 README** |

The empty cells are the reason a repo-specific contract is needed. Everything
else can be delegated to a native standard.

---

## 9. Constraints derived from the research

These become the proposal's design constraints.

| # | Constraint | Source |
|---|---|---|
| C1 | Licence must precede purpose, in the file's native comment syntax | REUSE 3.3 §Comment headers; kernel/Rust precedent |
| C2 | Do not invent a comment dialect — use `@fileoverview` for TS, `//!` for Rust | Google TS guide; rustdoc |
| C3 | The manifest is the licence authority; the header must agree with it | C-METADATA; npm `license`; existing boundary gate |
| C4 | Every rule needs a machine check, or it will decay | Q2 |
| C5 | A file-class taxonomy is mandatory, or tests and generated files get essays | Rule of Silence |
| C6 | Generated and vendored files are excluded by path, not by silence | `vendor/`, `dist/`, `.d.ts` |
| C7 | Adoption must be ratcheted, not big-bang (2,247 in-scope files) | Repo scale |
| C8 | Folder docs must reuse the existing P7 seven-section template | Existing convention |
| C9 | Headers must stay short; detail belongs in the folder README | Diátaxis; Rule of Parsimony |
| C10 | No JSDoc type annotations in TS | Google TS guide |
| C11 | Deprecation must name the replacement | Google TS guide |
| C12 | Doc examples must be executable where the language allows it (Rust) | C-EXAMPLE; doctests |

---

## 10. What is genuinely novel here

Three things are not covered by any surveyed standard, and are therefore the
repo's own contribution. They should be labelled as such in the proposal so
future readers do not assume an external citation exists:

1. **A minimum information bar for a purpose statement.** Every standard says a
   description is optional or "should add information"; none defines a floor.
   The proposal defines one (responsibility + invariant + explicit non-goal).
2. **A file taxonomy tied to documentation depth.** No standard says a test file
   deserves fewer words than an entry point.
3. **A polyglot, direction-aware licence boundary.** REUSE checks presence;
   the repo's boundary gate checks *which way* a licence may flow. Extending it
   across languages is novel.

Everything else in the proposal is adoption, not invention.

---

## 11. Sources

| Source | What it drove |
|---|---|
| [The Art of Unix Programming, ch. 1](http://www.catb.org/~esr/writings/taoup/html/ch01s06.html) | Rules of modularity, transparency, parsimony, silence |
| [Google TypeScript Style Guide](https://google.github.io/styleguide/tsguide.html) | File-root order, `@fileoverview`, export docs, no JSDoc types, deprecation |
| [TSDoc](https://tsdoc.org/) | Comment syntax, `@packageDocumentation`, release tags |
| [API Extractor — doc comment syntax](https://api-extractor.com/pages/tsdoc/doc_comment_syntax/) | `.api.md` public-surface gate |
| [`eslint-plugin-jsdoc` — require-file-overview](https://github.com/gajus/eslint-plugin-jsdoc/blob/main/docs/rules/require-file-overview.md) | File-overview enforcement, `initialCommentsOnly`, licence allow-list |
| [TypeDoc](https://typedoc.org/) | Doc generation and validation |
| [Rust API Guidelines — Documentation](https://rust-lang.github.io/api-guidelines/documentation.html) | C-CRATE-DOC, C-EXAMPLE, C-FAILURE, C-LINK, C-METADATA, C-HIDDEN |
| [RFC 1574](https://rust-lang.github.io/rfcs/1574-more-api-documentation-conventions.html) | `# Errors` / `# Panics` / `# Safety`, link conventions |
| [RFC 1687](https://rust-lang.github.io/rfcs/1687-crate-level-docs.html) | Crate-level docs |
| [rustdoc book — Lints](https://doc.rust-lang.org/rustdoc/lints.html) | `missing_docs`, `broken_intra_doc_links`, related lints |
| [REUSE Specification 3.3](https://reuse.software/spec-3.3/) | Cross-language licence headers, `REUSE.toml`, `.license` companions |
| [SPDX licence expressions](https://spdx.github.io/spdx-spec/v2.3/SPDX-license-expressions/) | Licence identifiers and `LicenseRef-` |
| [Diátaxis](https://diataxis.fr/) | Reference vs explanation separation |
| [Standard Readme](https://github.com/RichardLitt/standard-readme) | README section ordering |
| [MADR](https://adr.github.io/madr/) | Decision-record shape |

Two repo sources were also used and are authoritative for current state:
[`UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`](UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md)
and [`docs/developer/folder-readme-convention.md`](../../developer/folder-readme-convention.md).
