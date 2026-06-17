# Folder README Convention

> Phase P7 of the [Unix Theory Compliance Enhancement Proposal](../../project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md).
> Last reviewed: June 2026.

---

## TL;DR

Every immediate subdirectory of `includes/` (Base) and `addons/pro/includes/` (Pro) that contains PHP must ship a `README.md` that follows [`.context/templates/folder-readme-template.md`](../../../.context/templates/folder-readme-template.md). The check is enforced by `composer run docs:check-folder-readmes`, which runs as part of `composer run ci:all`.

## Why

The plugin already practices Unix theory at the **tool** level (one tool, one responsibility — see the [Unix Theory Compliance proposal](../../project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md)). Folder READMEs apply the same disciplines at the **directory** level:

| Unix rule | What a folder README delivers |
|---|---|
| Rule of transparency | The folder explains itself — humans and AI agents don't have to read every file to know what's inside |
| Rule of representation | The "Public Surface" section makes the folder's external contract explicit |
| Rule of composition | The "Inputs / Outputs / Neighbors" section documents seams between folders |
| Rule of modularity | The "Tier" section declares Base vs Pro and the PHP target |
| Do one thing well | The "Purpose" section is one sentence — if you need two, the folder is doing two things |

Compared to a single mega-doc at the root, per-folder READMEs:

- Stay close to the code they describe (lower drift rate)
- Reduce AI agent context window usage (load only the README for the folder being edited)
- Make onboarding for new contributors faster
- Are discoverable from GitHub's directory view (every folder gets a rendered preview)

## What is *not* in a folder README

This is the layering rule (mirrors `AGENTS.md` §2). Folder READMEs **must not** restate:

| Cross-cutting concern | Canonical source |
|---|---|
| Naming conventions (`WP_MCP_AI_{Feature}_{Component}`) | [`.context/conventions.md`](../../../.context/conventions.md) |
| Sanitiser / escaper list | [`.context/security-checklist.md`](../../../.context/security-checklist.md) |
| PHP 7.4+ vs 8.1+ rule details | [`CLAUDE.md`](../../../CLAUDE.md) |
| Tool return envelope | [`.context/tool-registry.md`](../../../.context/tool-registry.md) |
| BMAD phase gates | [`AGENTS.md`](../../../AGENTS.md) |

A folder README **links to** these sources from its "Also Load" section. If it restates them, the `docs:check-folder-readmes` script will flag a drift warning.

## What *is* in a folder README

The template enforces seven required H2 sections:

1. **Purpose** — one sentence, single responsibility
2. **Tier** — Base / Pro / Both, PHP target, optional deps
3. **Public Surface** — classes / interfaces / functions other folders may depend on
4. **Inputs / Outputs / Neighbors** — the composability section
5. **Conventions** — folder-specific deltas only (must not restate cross-cutting rules)
6. **Tests** — where they live + how to run this slice
7. **Also Load** — pointers to canonical `.context/*` files relevant to this folder

Optional sections you may add: `See Also`, `Related ADRs`, `Migration Notes`, `Known Limitations`.

## Authoring workflow

```bash
# 1. Copy the template into your folder
cp .context/templates/folder-readme-template.md includes/your-folder/README.md

# 2. Fill in the seven required sections (delete the HTML help comment)
$EDITOR includes/your-folder/README.md

# 3. Verify
composer run docs:check-folder-readmes

# 4. Verify strict (drift warnings become fatal)
composer run docs:check-folder-readmes:strict
```

## Enforcement

| Command | Scope | Drift handling |
|---|---|---|
| `composer run docs:check-folder-readmes` | Base only | Warnings non-fatal |
| `composer run docs:check-folder-readmes:all` | Base + Pro | Warnings non-fatal |
| `composer run docs:check-folder-readmes:strict` | Base only | Warnings fatal |
| `composer run ci:all` | Base only | Warnings non-fatal — included between lint and tests |

Direct invocation also supports `--json` for machine-readable output and `--scope=base|pro|all`.

Exit codes from `bin/check-folder-readmes.php`:

| Code | Meaning |
|---|---|
| 0 | Compliant |
| 1 | Missing README or missing required H2 section (error) |
| 2 | Drift warning + `--strict` flag (warning only) |

## Adding or removing a folder

When you add a new subdirectory under `includes/`:

1. Create the folder.
2. Add at least one PHP file (else the check skips the folder).
3. Copy the template into `includes/your-folder/README.md` and fill it in.
4. Run `composer run docs:check-folder-readmes` to confirm.

When you remove a folder, the README disappears with it — no extra step needed.

## Relationship to other AI-agent files

| File pattern | What it is | Required? |
|---|---|---|
| `includes/**/README.md` | **Folder context** — this convention | ✅ Yes, for every PHP-bearing subdir |
| `.context/*.md` | Subsystem context, loaded by AI agents | Optional per-task |
| `.github/agents/*.agent.md` | Per-role agent metadata | Optional per role |
| `CLAUDE.md`, `AGENTS.md` | Repo-wide agent context (root) | Single instance each |
| `.context/active/*.md` | Per-feature working notes | Only for in-progress features |

Folder READMEs slot in between `.context/*.md` (subsystem-level) and `.context/active/*.md` (feature-level). They are the **persistent, code-co-located, structural** layer of the context-engineering pyramid.

## See Also

- [`.context/templates/folder-readme-template.md`](../../../.context/templates/folder-readme-template.md) — the canonical template
- [`bin/check-folder-readmes.php`](../../../bin/check-folder-readmes.php) — enforcement script
- [`AGENTS.md` §2](../../../AGENTS.md) — context-loading strategy + layering rule
- [`docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`](../../project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md) — full Unix-theory mapping
