# `examples/agents/` — Slim `*.agent.md` Examples

Copy-ready, filled-in examples of GitHub Custom Agent files for this repository. They demonstrate the layering rule from [`AGENTS.md` §2](../../AGENTS.md): each file holds **only** agent-specific metadata + behavior and links out to the canonical sources for shared rules.

The canonical (empty) template lives at [`.context/templates/agent-file-template.md`](../../.context/templates/agent-file-template.md). These files are filled-in renderings of that template for two real, contrasting NV oOS roles.

---

## Files

| File | Role | Tools | Why it's a good example |
|------|------|-------|-------------------------|
| [`wp-rest-reviewer.agent.md`](./wp-rest-reviewer.agent.md) | Read-only reviewer for REST endpoints in `includes/class-wp-mcp-ai-rest.php` and `addons/pro/includes/rest/`. | read-only | Shows least-privilege tool list, a tightly-scoped subsystem, and how to link to `.context/rest-api.md` + `.context/security-checklist.md` instead of restating them. |
| [`tool-author.agent.md`](./tool-author.agent.md) | Writer that scaffolds new tool classes under `includes/tools/` (Base) or `addons/pro/includes/tools/` (Pro). | read + edit + bash | Shows a writer agent that links to `.context/tool-registry.md` and `.context/pro-vs-base.md`, declares Base-vs-Pro guard refusals, and keeps the PHP-compat rule out of the file body. |

These two roles are intentionally chosen to span the read-only / write-allowed and Base / Pro axes, so contributors creating new agent files have one nearby example for whichever quadrant they're in.

---

## How to use these

1. Pick the example closest to your role (read-only vs writer; Base-only vs Pro-aware).
2. Copy it to `.github/agents/<your-role-kebab-case>.agent.md`.
3. Edit the `name`, `description`, `tools`, `Purpose`, `Scope`, `Triggers`, `Refusals`, and `Success criteria` to match your role.
4. **Update [`AGENTS.md` §1 inventory table](../../AGENTS.md) in the same PR** — required by `AGENTS.md` §6 and by the `CONTRIBUTING.md` Per-Story Gate.
5. Confirm: every shared rule (naming, PHP-compat, security, tool patterns, architecture, build/test commands) is **linked**, not restated. If you're pasting more than ~5 lines that already exist in `CLAUDE.md` / `AGENTS.md` / `.context/`, replace that paste with a link.

---

## Authoring rules — quick checklist

Before opening a PR that adds or edits a `*.agent.md` file, verify:

- [ ] Filename matches frontmatter `name` (`wp-rest-reviewer.agent.md` ↔ `name: wp-rest-reviewer`).
- [ ] `tools:` is least-privilege (read-only reviewers don't get `edit` or `bash`).
- [ ] `Required reading` includes `AGENTS.md`, `CLAUDE.md`, `.context/conventions.md`, `.context/security-checklist.md` — plus only the subsystem `.context/` files actually relevant to the role.
- [ ] `Refusals` section exists and lists at least one out-of-scope concern with a redirect target.
- [ ] No naming/security/PHP-compat/architecture rules are restated inline.
- [ ] `AGENTS.md` §1 inventory updated in the same PR.

---

## See also

- [`AGENTS.md`](../../AGENTS.md) — agent inventory, coordination, and the layering rule (§2).
- [`CLAUDE.md`](../../CLAUDE.md) — Claude Code per-turn context (PHP compat, naming, security, tool patterns, architecture).
- [`.github/copilot-instructions.md`](../../.github/copilot-instructions.md) — Copilot repo-level context, including the Multi-Agent Awareness block.
- [`.context/templates/agent-file-template.md`](../../.context/templates/agent-file-template.md) — empty canonical template.
