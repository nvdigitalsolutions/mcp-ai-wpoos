# `.codex/` — OpenAI Codex sandbox bootstrap

> **First, read [`AGENTS.md`](../AGENTS.md) and [`CLAUDE.md`](../CLAUDE.md).** They describe the
> agent inventory, layering rule, naming conventions, PHP-compat floors (PHP 7.4+ base,
> PHP 8.1+ Pro), and the security expectations that apply to every coding agent — including
> OpenAI Codex running inside its ephemeral sandbox.

This folder configures the [OpenAI Codex](https://openai.com/codex/) sandbox
environment used when Codex-based agents run automated tasks against this
repository. It is the Codex counterpart of [`.devcontainer/`](../.devcontainer)
(local + Codespaces), [`.zed/`](../.zed) (Zed Agent profiles), and the
GitHub Custom Agents under [`.github/agents/`](../.github/agents).

## Files

| File | Purpose |
|------|---------|
| [`startup.sh`](startup.sh) | Idempotent sandbox bootstrap — ensures `phpcs` and `phpunit` are installed via Composer global, then links them into `/usr/local/bin` so they are on `PATH` for the rest of the session. Closes with a pointer block to the canonical agent docs. |

## How it fits into the agent ecosystem

Codex sandboxes are **ephemeral** — they spin up fresh for each task and
discard state at the end. To keep that bootstrap fast and offline-friendly,
`startup.sh`:

1. Short-circuits with `exit 0` if `phpcs` **and** `phpunit` are both already
   on `PATH` (the common case when the image already bakes them in).
2. Resolves `COMPOSER_HOME` the same way Composer does, so the global vendor
   bin directory is found regardless of the base image.
3. Installs `squizlabs/php_codesniffer:^3.7` and `phpunit/phpunit:^9.6`
   globally only when missing — matching the versions pinned in
   [`composer.json`](../composer.json).
4. Links (or copies) `phpcs` and `phpunit` into `/usr/local/bin` when writable,
   falling back to a `PATH` hint message otherwise.

The trailing `echo` block prints the canonical "next reads" pointers
(`AGENTS.md`, `CLAUDE.md`, `.context/conventions.md`,
`.context/security-checklist.md`) so the Codex agent's very first context
already includes the layering rule and the always-required `.context/` files.

## Common Codex-side tasks

Once the bootstrap is done, Codex tasks typically run the same commands as a
local contributor — see [`MAINTAINER_MAP.md`](../MAINTAINER_MAP.md) §
"Build & Test" for the canonical list. The two most common entrypoints:

```bash
# Lint (base plugin only; -w8 silences the two custom WPMCPAI sniffs)
composer run lint:base

# Full PHPUnit suite (requires composer run test:install once per sandbox)
composer run test
```

## When to edit this folder

- **Tooling version bumps** — if `composer.json` raises `phpcs` or `phpunit`
  to a new major, update the version constraints in `startup.sh` to match.
- **New required global binary** — add a parallel `need_<tool>` block following
  the existing `need_phpcs` / `need_phpunit` pattern. Keep the script
  idempotent (it must be safe to re-run).
- **Pointer block** — when the canonical-docs list in [`AGENTS.md`](../AGENTS.md)
  § 2 ("Context-Loading Strategy") changes, mirror the change in the trailing
  `echo` lines so Codex agents see the same canonical reading list.

Do **not** put PHP rules, naming conventions, or security guidance here —
those live in [`AGENTS.md`](../AGENTS.md), [`CLAUDE.md`](../CLAUDE.md), and
[`.context/`](../.context) per the layering rule in `AGENTS.md` § 2.
