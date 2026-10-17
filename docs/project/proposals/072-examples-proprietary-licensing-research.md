# Research — Marking the `examples/` folders as Proprietary NV Digital Solutions Apps

**Status:** Research / decision needed — **applied** (see "Result")
**Date:** 2026-10-13
**Related:** `examples/nvoos-dictate` (and its skill `.agents/skills/mcp-ai-wpoos-dictation-app`), `examples/nvoos-pro-spa-vite`, `examples/nvoos-vite-demo`, root `LICENSE` (GPL-3.0)

## 1. Context

The root repository is licensed **GPL-3.0** (`LICENSE` at the repo root). The
`examples/` tree contains standalone companion applications — most notably
`examples/nvoos-dictate` (the Tauri v2 dictation desktop app) — which the user
wants to explicitly mark as **proprietary NV Digital Solutions applications**,
distinct from the GPL-3.0 plugin code around them.

This is a legitimate and common arrangement (e.g. samples/demos/apps under a
different license than the library they accompany), but it must be made
**machine-readable and unambiguous**, because GPL-3.0 is the repo default and
any unmarked file is presumed GPL-3.0.

## 2. Current-state facts (verified)

| Artifact | Current value | Problem |
|---|---|---|
| Repo root `LICENSE` | GPL-3.0 | Applies as the default to everything under it absent a more specific notice |
| `examples/nvoos-dictate/src-tauri/Cargo.toml` | `license = "MIT"` | **Conflicts** with the skill's "Proprietary" claim |
| `examples/nvoos-dictate/package.json` | `"license": "MIT"` | Same conflict |
| `examples/nvoos-dictate/LICENSE*` | **absent** | The skill says `Proprietary. See LICENSE.txt` — that file does not exist |
| `examples/nvoos-vite-demo/package.json` | no `license` field | Inherits repo GPL-3.0 by default |
| `examples/nvoos-pro-spa-vite/package.json` | no `license` field | Same |
| `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` frontmatter | `license: Proprietary. See LICENSE.txt` | Points at a nonexistent file; contradicts Cargo/package MIT |

**The core issue:** the dictation app currently *self-describes as MIT* in its
package metadata while the operational skill *claims proprietary*. Both cannot
be true. This must be reconciled before/while adding the proprietary marking.

## 3. Industry standards & best practices (research)

### 3.1 SPDX identifier for proprietary code

SPDX does not list a "Proprietary" license on the SPDX License List, so the
correct expression is a custom `LicenseRef-` identifier:

- **`LicenseRef-Proprietary`** is the de-facto community-standard value,
  recognized by ScanCode and referenced in PEP 639 / the Python packaging
  license-expression spec
  ([PEP 639](https://peps.python.org/pep-0639/),
  [packaging spec](https://packaging.python.org/en/latest/specifications/license-expression/),
  [ScanCode #2333](https://github.com/aboutcode-org/scancode-toolkit/issues/2333)).
- The general rule (SPDX spec §10.1): any license not on the SPDX list uses
  `LicenseRef-<idstring>`, with the referenced text provided locally. A more
  specific name (e.g. `LicenseRef-NV-Digital-Solutions-Proprietary`) is allowed
  and preferable when the owner wants a self-documenting identifier, provided
  the text ships in the repo.

### 3.2 REUSE specification (FSFE) — the gold standard for per-file marking

[REUSE 2.0](https://reuse.software/spec-2.0/) prescribes:

1. **Exact license text for every license used**, in a top-level `LICENSES/`
   directory (or `LICENSE.txt`) — including the proprietary text, never altered.
2. **Per-file copyright + license headers** with two tags:
   - `SPDX-FileCopyrightText: <year> NV Digital Solutions`
   - `SPDX-License-Identifier: LicenseRef-Proprietary` (or the custom ref)
3. **`.license` sidecar files** for formats that can't carry a header (binaries,
   JSON without comment support — though most package manifests do support a
   `license` field).
4. A **README / policy** explaining the practice ("NV oOS example apps are
   proprietary; the plugin core is GPL-3.0").

### 3.3 Per-file license identifiers in source headers

The Linux Foundation and SPDX tutorials both recommend an
`SPDX-License-Identifier:` tag in a comment near the top of **each source file**
([LF best practices](https://www.linuxfoundation.org/licensebestpractices),
[SPDX tutorial](https://github.com/david-a-wheeler/spdx-tutorial)). For a
proprietary file this is `SPDX-License-Identifier: LicenseRef-Proprietary`.

### 3.4 Package-manager metadata

- **Rust/Cargo:** `license` accepts `"proprietary"` (a literal string Cargo
  understands) or an SPDX expression. Convention: set `license = "proprietary"`
  so `cargo` tooling does not misclassify it, OR use `license = "LicenseRef-…"`
  with the text shipped. Note: Cargo does not interpret `LicenseRef-*` as "open",
  so either signals non-OSI.
- **npm/package.json:** `"license": "UNLICENSED"` is the npm-standard value for
  proprietary/private packages (documented by npm), rather than a fake MIT.
- **Tauri `tauri.conf.json`** has **no** license field — the product's license
  lives in the Cargo/package manifests + a `LICENSE` file, not the Tauri config.

### 3.5 The GPL boundary

Because the repo default is GPL-3.0, the proprietary marking must be
**opt-out per subtree**, not just a single top-line claim. Without a per-file/
per-directory notice, a copier of the repo reasonably treats `examples/*` as
GPL-3.0. Mitigations:
- A `examples/nvoos-dictate/LICENSE.txt` with the proprietary terms + a
  prominent `NOTICE`/`README` stating the subtree is proprietary and excluded
  from the repo's GPL-3.0.
- An explicit line in the repo root `LICENSE`'s "additional permissions" or a
  top-level `exceptions`/`MODULES` note (optional but clarifies intent).
- Ensure the dictation app does **not** import GPL-3.0 code from the plugin
  core in a way that would taint it (it currently keeps the plugin at arm's
  length via REST — see `examples/nvoos-dictate/src-tauri/src/nvoos/`), so the
  proprietary boundary is technically defensible.

## 4. Recommended marking (the fix)

### 4.1 Resolve the MIT vs Proprietary contradiction (decision required)

Pick one:

- **Option A (assumed intent): make the app proprietary.** Change
  `Cargo.toml` `license = "MIT"` → `"proprietary"` (or `"LicenseRef-Proprietary"`),
  `package.json` `"license": "MIT"` → `"UNLICENSED"`, and ship a proprietary
  `LICENSE.txt`. This matches the skill's existing "Proprietary" claim.
- **Option B: it is genuinely MIT.** Then remove/fix the skill's "Proprietary"
  metadata and keep `MIT`. (Less likely given the request, but must be called out.)

This plan assumes **Option A**.

### 4.2 Concrete file changes

1. **Add `examples/nvoos-dictate/LICENSE.txt`** — the proprietary terms (all
   rights reserved, NV Digital Solutions, no redistribution without license),
   with an SPDX header block so REUSE can read it:
   ```
   Valid-License-Identifier: LicenseRef-Proprietary
   License-Text:
   …proprietary text…
   ```
2. **`examples/nvoos-dictate/src-tauri/Cargo.toml`** — `license = "proprietary"`.
3. **`examples/nvoos-dictate/package.json`** — `"license": "UNLICENSED"`.
4. **Add `SPDX-FileCopyrightText` + `SPDX-License-Identifier: LicenseRef-Proprietary`**
   headers to Rust (`.rs`) and TS/JS (`.ts`/`.tsx`) source files in the app —
   or, to keep the diff small, add a single `examples/nvoos-dictate/.reuse/dep5`
   (DEP-5 `copyright` file) that globs the whole subtree under
   `LicenseRef-Proprietary` + `SPDX-FileCopyrightText: NV Digital Solutions`.
   The DEP-5 route is the pragmatic choice for a large existing tree.
5. **Add a `NOTICE.md`** (or extend `examples/README.md`) stating clearly:
   *"The example applications in `examples/` (`nvoos-dictate`,
   `nvoos-pro-spa-vite`, `nvoos-vite-demo`) are proprietary NV Digital
   Solutions products and are NOT covered by the repository's GPL-3.0 LICENSE.
   See each app's LICENSE.txt."*
6. **Same treatment for `nvoos-pro-spa-vite` and `nvoos-vite-demo`** if they are
   also intended proprietary (currently no license field → GPL by default).
7. **Fix the skill metadata** `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md`
   to point at the real `LICENSE.txt` path (`license: Proprietary. See
   examples/nvoos-dictate/LICENSE.txt`) once it exists.

### Result (applied)

The proprietary `LICENSE.txt` text was aligned to the canonical template
already used by the repository's addons (e.g. `addons/canvas/LICENSE`):
numbered §1 Reservation of Rights / §2 Permitted Use / §3 Redistribution
Prohibited / §4 Third-Party Components / §5 No Warranty / §6 Limitation of
Liability / §7 Contact, with the repository-root-vs-directory precedence
clause and the `licensing@nvdigitalsolutions.com` contact. Two deliberate
adaptations:

- §2 "Permitted Use" is kept **generic** (no product-specific function) — it
  references the feature area (dictation/media transcription, SPA mounting,
  package demonstration) rather than hard-coding one function.
- The **patent notice is omitted** from the example apps: the
  "Patent Pending (Application #19/410,504)" line in `mcp-ai-wpoos-pro.php`
  and the addon headers is specific to the orchestration layer, not the
  desktop/spa example apps.

Each app also gained a `License: Proprietary` + license URI + contact in its
`NOTICE.md`.

## 5. What NOT to do

- ❌ Keep `"license": "MIT"` while claiming proprietary — a legally material
  misstatement (MIT grants redistribution rights).
- ❌ Invent an SPDX id like `Proprietary` without the `LicenseRef-` prefix.
- ❌ Put only a single claim in one manifest without a `LICENSE.txt` the claim
  can actually point to.
- ❌ Delete or edit the root GPL-3.0 `LICENSE` — the plugin core stays GPL.

## 6. Open questions for the owner

1. Confirm **Option A** (proprietary) vs **Option B** (MIT) for `nvoos-dictate`.
2. Are `nvoos-pro-spa-vite` and `nvoos-vite-demo` also to be marked proprietary?
3. Preferred identifier: generic `LicenseRef-Proprietary` vs
   `LicenseRef-NV-Digital-Solutions-Proprietary`?
4. Per-file header comments vs a single DEP-5 `copyright` file for the subtree?
5. Is there existing external legal text (EULA / terms) to paste into
   `LICENSE.txt`, or should I draft placeholder "All rights reserved" text?

---

*The technical mark-up is fully specified above and is safe to apply once the
owner answers Q1–Q5; the only thing I will not guess is the actual license
grant wording and the MIT-vs-proprietary decision itself.*
