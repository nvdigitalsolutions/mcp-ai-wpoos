# File Header Convention

> Phases P8–P12 of the [Cross-Language Documentation Contract](../project/proposals/166-cross-language-documentation-contract-proposal.md).
> Last reviewed: 2026-11-26.

---

## TL;DR

Every first-party source file declares **two things at the top**:

1. **Licence** — one `SPDX-License-Identifier` line, equal to the nearest
   manifest's `license` field.
2. **Purpose** — a short block that says what the file is responsible for, what
   must stay true, and what it deliberately does not do.

The licence is mechanical and enforced everywhere. The purpose statement is
sized to the file's class, enforced for new files, and ratcheted for the legacy
tree.

```bash
composer run docs:check-file          # report
composer run docs:check-file:strict   # fail on over-budget scopes
composer run docs:budget              # record the current counts
php bin/check-file-docs.php --new-files-only --strict   # what CI enforces on a PR
php bin/check-file-docs.php --self-test                 # header-inserter fixtures
```

---

## The three roots

| Root | Artefact | Answers |
|---|---|---|
| File | the comment block above the first import/declaration | What is this file for, and what must stay true? |
| Folder | `README.md` beside the sources | What does this directory own, and how do I use it? |
| Tree | `package.json` / `Cargo.toml` | What licence and entry points does this package have? |

They must agree. The **manifest wins on licence**; the **folder README wins on
responsibility**; the file header is corrected, not the other two.

---

## Licence line

The identifier is the SPDX expression of the nearest manifest. `UNLICENSED`,
`proprietary` and `SEE LICENSE IN …` normalise to `LicenseRef-Proprietary`.

| Language | Line 1 (or line 2 after `#!`) |
|---|---|
| TS / TSX / JS / JSX / MJS / CJS | `// SPDX-License-Identifier: GPL-3.0-or-later` |
| Rust | `// SPDX-License-Identifier: LicenseRef-Proprietary` |
| Astro | inside the frontmatter fence: `// SPDX-License-Identifier: MIT` |
| PHP | inside the file docblock (`@license` + `SPDX-License-Identifier`) |

`bin/add-spdx-headers.php` inserts or normalises the line. It never writes prose
and is idempotent. Generated bundles (`*.min.js`, `*.min.css`), vendored trees
and build output are excluded by `.github/docs-scopes.json`.

---

## Purpose statement

A purpose statement is **1–5 lines** and answers three questions:

| # | Question | Prevents |
|---|---|---|
| 1 | What is the one thing this file does? | "utils.ts" with no scope |
| 2 | What must remain true? | a refactor that drops validation |
| 3 | What does this file deliberately not do? | duplicated logic creeping in |

It must not restate the filename, the type signature, or language mechanics.

### Good

```ts
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * @fileoverview Rejects unsafe checkout handoff URLs before a redirect.
 *
 * Invariant: the returned URL is same-site and on the allowlist; userinfo and
 * non-standard ports are rejected. Never performs the redirect or a network
 * call — the caller owns that.
 */
```

### Bad

```ts
/** @fileoverview Checkout helpers. */
```

The first is reviewable; the second is noise. The checker rejects the empty,
filename-restating and "this file contains helper functions" cases, but the real
bar is review.

---

## File classes and minimum bars

| Class | Detected by | Purpose block | Symbol docs |
|---|---|---|---|
| `entrypoint` | `index.ts`, `main.ts`, `lib.rs`, `main.rs`, `server.js` | responsibility + public surface + start-up order | exports / public items |
| `module` | any other `.ts`, `.tsx`, `.rs`, `.js` | responsibility + invariant + non-goal | exports / public items |
| `component` | `.tsx`, `.astro` | purpose + props contract | props interface + component |
| `test` | `*.test.*`, `*.spec.*`, `tests/**` | what it pins + fixtures (1–3 lines) | none |
| `config` | `*.config.*`, `build.rs`, `vite.config.*` | what it affects (1–3 lines) | none |
| `generated` | `*.d.ts`, `gen/**`, `@generated` | none; name the generator | none |
| `vendored` | excluded by path | none | none |

A file may only claim a class if its path matches the table. There are no silent
exemptions: every exclusion is a committed pattern in `.github/docs-scopes.json`.

### Rust

```rust
// SPDX-License-Identifier: LicenseRef-Proprietary
//! Storage for the meeting transcript buffer.
//!
//! Invariant: a flush writes the whole buffer or nothing; a partial write is
//! treated as corruption and the buffer is retained. Never deletes the previous
//! transcript — retention is the caller's policy.
```

Rust module directories are documented by `//!`; they do **not** get a folder
README as well. READMEs are for directories that are not modules (crate root,
`examples/`, `tests/`).

### Astro

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

---

## Public surface

### TypeScript

| Item | Requirement |
|---|---|
| Every export | TSDoc block, validated by `eslint-plugin-tsdoc` |
| Package entry point | `@packageDocumentation` listing the exported surface |
| Stability | `@public`, `@beta`, `@alpha`, `@internal` |
| Deprecation | `@deprecated` **names the replacement** |
| Failure | `@throws` on functions with documented failure modes |
| Types | never in JSDoc — TypeScript is the type source |

### Rust

| Item | Requirement |
|---|---|
| Crate root | `//!` with a worked example |
| Public item | `///` (enforced by `missing_docs`) |
| Fallible fn | `# Errors` |
| Panicking fn | `# Panics` |
| `unsafe` fn | `# Safety` |
| Internal public item | `#[doc(hidden)]` or `pub(crate)` |

---

## How enforcement works

| Gate | Scope | Command |
|---|---|---|
| Self-test | inserter fixtures | `php bin/check-file-docs.php --self-test` |
| Licence | every in-scope file | `php bin/check-file-docs.php --licence-only` |
| New files | files added on a branch | `php bin/check-file-docs.php --new-files-only --strict` |
| Budget | whole tree, per scope | `php bin/check-file-docs.php --strict` |
| Folder docs | non-PHP source trees | `php bin/check-folder-readmes.php --scope=all` |

The budget (`.github/docs-budget.json`) mirrors the PHPCS ratchet:

1. No scope may exceed a recorded count.
2. No stale slack — an improvement must be recorded in the same PR.
3. New files are always in scope and must be clean.
4. Named scopes carry their own budgets.
5. `--update` refuses to raise a count without `--allow-increase --reason=…`.

Adoption is per scope. A tree enters the strict gate when its budget reaches
zero; `assets/js/**` is deliberately excluded until it gets its own decision.

---

## Adding a file

1. Copy the shape for the file's class from
   [`.context/templates/file-header-examples.md`](../../.context/templates/file-header-examples.md).
2. Use the licence of the nearest manifest. When in doubt:
   `php bin/check-file-docs.php --explain=<path>`.
3. Write the purpose statement: responsibility, invariant, non-goal.
4. Run `php bin/check-file-docs.php --new-files-only --strict`.

## Adding a scope or a product

1. Add the scope or product to `.github/docs-scopes.json`.
2. Run `php bin/check-file-docs.php --audit-scopes` — it must report zero
   unscoped, unresolved and overlapping files.
3. Run `php bin/check-file-docs.php --update` to record the baseline.
4. A scope change is a code review, not a config toggle: it changes what CI
   enforces.

---

## See also

- [Proposal 166](../project/proposals/166-cross-language-documentation-contract-proposal.md)
- [Research](../project/proposals/166-cross-language-documentation-contract-research.md)
- [Implementation plan](../project/proposals/166-cross-language-documentation-contract-implementation-plan.md)
- [Folder README Convention](folder-readme-convention.md)
