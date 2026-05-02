# Agent File Template — `.github/agents/<role>.agent.md`

> **Canonical slim template** for GitHub Custom Agent files (auto-discovered by GitHub Copilot Coding Agent and compatible runtimes).
>
> Follow the **layering rule** in [`AGENTS.md` §2](../../AGENTS.md): files under `.github/agents/` MUST contain only agent-specific metadata + behavior. They MUST NOT restate naming conventions, security rules, PHP-compat rules, tool patterns, or architecture — those live in `AGENTS.md`, `CLAUDE.md`, and `.context/`. Link, don't duplicate.
>
> See [`examples/agents/`](../../examples/agents/) for filled-in examples you can copy.

---

## Why this template exists

Without a shared template, each `*.agent.md` tends to drift into a fourth copy of the same naming/security/PHP-compat rules already maintained in `CLAUDE.md`, `AGENTS.md`, and `.github/copilot-instructions.md`. That breaks the GSD 30% context-budget rule and makes rules go stale in three places at once. Slim files that link to canonical sources solve both problems.

---

## File structure (what every `*.agent.md` should contain)

A correctly-layered agent file has exactly these sections, in this order, and nothing else:

1. **YAML frontmatter** — agent metadata only.
2. **Purpose** — one paragraph: what this agent owns and what it refuses.
3. **Required reading** — links to `AGENTS.md`, `CLAUDE.md`, and the relevant `.context/` files. Always-required first, subsystem-specific second.
4. **Scope** — bullets describing the in-scope paths/components and the out-of-scope ones.
5. **Triggers / when to invoke** — short list of situations where this agent is the right one.
6. **Refusals** — short list of requests this agent must decline (with where to redirect).
7. **Success criteria** — bullets the agent uses to self-check before handing back work.
8. *(Optional)* **Invocation example** — one short example prompt and one short example output.

Anything outside this skeleton (naming rules, security checklists, full architecture descriptions, repeated tool patterns, exhaustive build/test commands) belongs in `AGENTS.md` / `CLAUDE.md` / `.context/` instead. If you find yourself pasting more than ~5 lines of shared rules, stop and link instead.

---

## YAML frontmatter — required keys

| Key | Required | Description |
|-----|----------|-------------|
| `name` | yes | Short, kebab-case role name (e.g. `wp-rest-reviewer`). Must match the filename: `<name>.agent.md`. |
| `description` | yes | Single sentence — what the agent does. Shown in agent pickers, so be precise. |
| `tools` | yes | Comma-separated list of tools the agent may use (e.g. `read, grep, glob, view, edit, bash`). Keep minimal — least-privilege. |
| `model` | optional | Model override only when the role genuinely needs it. Omit otherwise so the runtime default applies. |
| `triggers` | optional | List of natural-language triggers. Useful when the agent should be auto-suggested. |

Do **not** invent custom keys that the GitHub runtime ignores. If you need richer metadata, store it in the body, not in frontmatter.

---

## Skeleton (copy this)

```markdown
---
name: <role-kebab-case>
description: <one-sentence purpose, ending with a period.>
tools: read, grep, glob, view, edit, bash
# model: claude-sonnet-4.5    # optional — omit unless the role needs a non-default model
---

# <Human-Readable Role Name>

## Purpose

<2–4 sentences. State what this agent owns, the typical task it's invoked for, and one sentence on what it explicitly does not do.>

## Required reading

Always load these first (GSD 30% rule):

- [`AGENTS.md`](../../AGENTS.md) — agent inventory, coordination, and the layering rule.
- [`CLAUDE.md`](../../CLAUDE.md) — naming conventions, PHP compatibility, security, tool patterns, architecture.
- [`.context/conventions.md`](../../.context/conventions.md)
- [`.context/security-checklist.md`](../../.context/security-checklist.md)

Subsystem-specific (load only when working in that area):

- [`.context/<relevant-subsystem>.md`](../../.context/<relevant-subsystem>.md) — <one-line reason>

## Scope

**In scope**

- `<path/or/component>` — <why>
- `<path/or/component>` — <why>

**Out of scope** (refuse and redirect)

- <thing> → defer to <other agent or canonical doc>
- <thing> → defer to <other agent or canonical doc>

## Triggers

Invoke this agent when:

- <trigger 1>
- <trigger 2>
- <trigger 3>

## Refusals

This agent must decline and redirect when asked to:

- <refusal 1> — redirect to <where>
- <refusal 2> — redirect to <where>

## Success criteria

Before handing work back, verify:

- [ ] All edits stay inside the in-scope paths above.
- [ ] No naming / security / PHP-compat rules were restated inline (linked instead).
- [ ] Relevant `Required reading` files were actually consulted.
- [ ] <role-specific check 1>
- [ ] <role-specific check 2>

## Invocation example *(optional)*

> "<example user request>"

Expected behavior: <one or two sentences describing what the agent should do, including which file(s) it would touch and which it would refuse>.
```

---

## Authoring rules (quick reference)

1. **Slim — link, don't duplicate.** If the same rule appears verbatim in `AGENTS.md` / `CLAUDE.md` / `.context/`, link instead.
2. **Least privilege.** List only the tools the agent actually needs in `tools`. A read-only reviewer should not have `bash` or `edit`.
3. **One role per file.** If a single agent's scope crosses two unrelated subsystems, split into two files.
4. **Filename matches `name`.** `wp-rest-reviewer.agent.md` ↔ `name: wp-rest-reviewer`.
5. **Update inventory in the same PR.** Per `AGENTS.md` §6, every new or changed `*.agent.md` requires a matching update to the §1 inventory table in the same PR.
6. **Refusals are mandatory.** Every agent must declare what it refuses, otherwise scope creep is inevitable.
7. **No build/test command dumps.** Build, lint, and test commands belong in `CLAUDE.md` and `MAINTAINER_MAP.md`. Reference them, don't paste them.
8. **PHP-compat reminders go via link.** If the agent touches `includes/`, link `CLAUDE.md`'s "PHP Compatibility — Critical" section. Do not restate "no enums, no readonly" inline.

---

## Maintenance

When this template changes:

- Update [`AGENTS.md` §2 layering rule](../../AGENTS.md) if the structure changes materially.
- Update the example files under [`examples/agents/`](../../examples/agents/) so they continue to match the template.
- Bump the "Last reviewed" date in `AGENTS.md`.
