# `examples/agents/` — Slim `*.agent.md` Examples

Copy-ready, filled-in examples of GitHub Custom Agent files for this repository. They demonstrate the layering rule from [`AGENTS.md` §2](../../AGENTS.md): each file holds **only** agent-specific metadata + behavior and links out to the canonical sources for shared rules.

The canonical (empty) template lives at [`.context/templates/agent-file-template.md`](../../.context/templates/agent-file-template.md). The files below are filled-in renderings of that template covering every major NV oOS subsystem.

---

## Files

The roster covers the full NV oOS surface: every major subsystem has a single-owner agent, split by mode (read-only reviewer vs writer). Pick the example closest to your role and copy it into `.github/agents/`.

### Read-only reviewers *(tools: `read, grep, glob, view`)*

| File | Subsystem owned | Why it's a good example |
|------|-----------------|-------------------------|
| [`wp-rest-reviewer.agent.md`](./wp-rest-reviewer.agent.md) | REST endpoints in `includes/class-wp-mcp-ai-rest.php` + `addons/pro/includes/rest/`. | Least-privilege `tools:`, tightly-scoped subsystem, links to `.context/rest-api.md` + `.context/security-checklist.md` rather than restating. |
| [`wp-security-reviewer.agent.md`](./wp-security-reviewer.agent.md) | Capability gates, nonces, sanitization, escaping, prepared SQL, SSRF, upload validation. | Read-only safety pass that produces structured findings without editing. |
| [`wp-org-compliance-auditor.agent.md`](./wp-org-compliance-auditor.agent.md) | WordPress.org plugin-review checklist (set_time_limit, attribution, do_shortcode wrap, External Services). | Encodes the recurring WP.org review feedback as enforceable success criteria. |
| [`php-compat-reviewer.agent.md`](./php-compat-reviewer.agent.md) | PHP 7.4 compat for the base plugin / non-Pro addons; PHP 8.1+ allowed under `addons/pro/`. | Catches `str_contains`, enums, `match`, `readonly`, etc. that would break the supported PHP floor. |

### Writers *(tools include `edit` and/or `bash`)*

| File | Subsystem owned | Why it's a good example |
|------|-----------------|-------------------------|
| [`tool-author.agent.md`](./tool-author.agent.md) | Tool classes under `includes/tools/` (Base) and `addons/pro/includes/tools/` (Pro). | Base-vs-Pro guard refusals; links `.context/tool-registry.md` + `.context/pro-vs-base.md`. |
| [`slash-command-author.agent.md`](./slash-command-author.agent.md) | Commands under `includes/slash-commands/commands/` and their tests. | Shows scope walls around the toolkit manager itself. |
| [`chat-ui-author.agent.md`](./chat-ui-author.agent.md) | Frontend chat (`assets/js/chat.js`, blocks, Elementor widget). | Honours `wp.i18n`, jQuery compat, SSE semantics, guest-token flow; refuses to touch server-side PHP. |
| [`phpunit-test-author.agent.md`](./phpunit-test-author.agent.md) | PHPUnit tests under `tests/` and `addons/pro/tests/`. | Refuses to edit production code even to make a test pass. |
| [`agent-skill-curator.agent.md`](./agent-skill-curator.agent.md) | Bundled `SKILL.md` files under `includes/bundled-skills/` and `addons/pro/includes/bundled-skills/`. | Mandatory `THIRD_PARTY_NOTICES.md` updates for curated upstream skills. |
| [`addon-maintainer.agent.md`](./addon-maintainer.agent.md) | One of `addons/{algorave,canvas,cornerstone3d,embedded,fantasy-football,graphify}` per session. | Per-addon scope wall — refuses to cross into other addons or the base plugin. |
| [`toolkit-spa-maintainer.agent.md`](./toolkit-spa-maintainer.agent.md) | One toolkit-SPA addon per session (`toolkit-shell` / `canvas-toolkit` / `document-editor` / `ohif-viewer` / `media-studio` / `video-studio`). | Same per-addon scope wall as `addon-maintainer`, but specialised for React-SPA addons that follow the [Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md) — version-bump rule, license gate, manifest discipline. |
| [`release-engineer.agent.md`](./release-engineer.agent.md) | Versions, `CHANGELOG.md`, `readme.txt` "Stable tag", `bin/build-addon-zips.sh`. | Refuses `git push`, `git tag`, `gh release create`, and SVN — hands artifacts to a maintainer. |
| [`docs-maintainer.agent.md`](./docs-maintainer.agent.md) | `docs/`, `README.md`, `readme.txt` (descriptive blocks), `CHANGELOG.md` narrative. | Refuses any change under `includes/`, `addons/`, or `assets/`. |
| [`acp.agent.md`](./acp.agent.md) | ACP (Agent Client Protocol) integration — JSON-RPC 2.0 server under `includes/acp/`, session management, `tool_call` mapping, federation discovery. | Bridges to the Tool Registry via two-gate sanitisation without duplicating LLM driver logic; maintains upstream spec compliance. |

The roster is deliberately split so each agent owns exactly one subsystem and refuses everything else — chain them via `handoffs:` (per the GitHub Copilot custom-agent spec, Oct 2025) for multi-step workflows.

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
- [`.zed/settings.json`](../../.zed/settings.json) + [`.zed/README.md`](../../.zed/README.md) — Zed editor mirror of this roster as native agent profiles. If you change a `tools:` line below, update the matching profile in `.zed/settings.json` in the same PR.
