# AI-Assisted Development — Methodology & Transparency

> **Purpose:** Full disclosure of how this plugin was developed using AI agents, what the human review process looks like, and what this means for code reviewers.
> **Last Updated:** June 2, 2026

---

## TL;DR

This plugin was developed with heavy AI assistance using a structured multi-agent pipeline. Every change — whether written by AI or by hand — goes through human review before merging. The agent coordination rules, coding standards, and security requirements are all documented in machine-readable format so the AI agents follow them consistently.

This document exists so external reviewers understand the development process and can focus their attention where it matters most.

---

## Development Pipeline

### AI Agents Used

| Agent | Role | Configuration |
|---|---|---|
| **Claude Code** | Primary coding agent. Handles feature implementation, bug fixes, refactoring. | [CLAUDE.md](../CLAUDE.md) |
| **GitHub Copilot** | Secondary agent for inline suggestions and code completion. | [.github/copilot-instructions.md](../.github/copilot-instructions.md) |
| **OpenAI Codex** | Tertiary agent for prototyping and exploration. | `.github/agents/*.agent.md` |
| **BMAD Agents** | Internal workflow agents that run inside NV oOS assistants (not coding-time agents). | `.bmad/agents/*.yaml` |

### How Changes Are Made

```
Human writes task description
         │
         ▼
AI agent proposes changes (PR description + code)
         │
         ▼
Human reviews changes:
  • Are the right files changed?
  • Does the logic make sense?
  • Are security rules followed?
  • Do tests pass?
         │
         ▼
Human approves and merges (or sends back for revision)
         │
         ▼
CI runs: PHPCS, PHPUnit, composer audit, npm audit, wp plugin-check
```

### Agent Guardrails

Every AI agent operates under constraints defined in:

1. **[CLAUDE.md](../CLAUDE.md)** — Non-negotiable rules for the primary coding agent:
   - PHP 7.4 compatibility (no typed properties, no match expressions, no named arguments)
   - WordPress naming conventions (`WP_MCP_AI_` prefix, snake_case)
   - Security rules (sanitize input, escape output, check capabilities)
   - Tool implementation patterns (canonical return envelope, two-gate sanitisation)
   - Surgical change discipline (minimal diffs, no unrelated refactoring)

2. **[AGENTS.md](../AGENTS.md)** — Multi-agent coordination:
   - Which agent owns which scope
   - Context-loading strategy (which docs each agent must read)
   - Inter-agent handoff protocol
   - Avoiding duplication between agents

3. **[.context/](../.context/)** — Subsystem-specific context files loaded on-demand:
   - `security-checklist.md` — The security checklist every agent must follow
   - `rest-api.md` — REST API conventions
   - `tool-registry.md` — Tool registration and execution rules
   - `chat-ui.md` — Frontend conventions

4. **Custom PHPCS sniffs** — Two custom sniffs enforce tool-specific rules at severity 5:
   - `WPMCPAI.Tools.CanonicalReturnEnvelope` — Tools must return success array or `WP_Error`, never `array('success' => false, ...)`
   - `WPMCPAI.Tools.SanitizeAtEntry` — Tool `execute()` must sanitize `$arguments[...]` at entry

---

## What This Means for Code Review

### Where AI code is strongest (less scrutiny needed)
- **Boilerplate** — Class scaffolding, PHPDoc blocks, WordPress hook registrations
- **Repetitive patterns** — Tool classes following the established template (there are ~815 of them)
- **Standardized operations** — REST endpoint registration, settings page rendering, CPT/CCT setup
- **Documentation** — Most of the `docs/` folder was AI-drafted, then human-reviewed

### Where AI code needs more scrutiny
- **Novel logic** — Anything that isn't following an existing pattern in the codebase
- **Security boundaries** — Permission checks, input/output handling, authentication flow
- **State management** — Anything involving WordPress options, transients, or post meta with race conditions
- **Error handling** — Edge cases, failure modes, and error recovery paths
- **Integration code** — WooCommerce, JetEngine, third-party API clients (may have untested code paths)
- **Concurrency** — SSE streams, async job queues, cron jobs that may overlap

### Specific red flags to look for
Based on known AI coding patterns:
- **Over-confident sanitization** — AI may sanitize but miss the right sanitizer for the context (e.g., `sanitize_text_field` on a URL)
- **Incomplete nonce checking** — AI may check nonces but forget to verify user capabilities
- **Assumed plugin state** — AI may assume a dependency is active without checking (e.g., WooCommerce, JetEngine)
- **Inconsistent patterns** — Two tools doing the same thing differently because different AI sessions wrote them
- **Commented-out code** — AI may leave debugging code or alternative implementations in comments
- **Overly optimistic error handling** — `try/catch` blocks that swallow errors without logging

---

## Scale of AI Involvement

| Component | AI Involvement | Human Review Level |
|---|---|---|
| Base plugin infrastructure (`class-wp-mcp-ai-plugin.php`, REST, registry) | ~70% AI | Heavy — core architecture reviewed carefully |
| Base tool classes (~231) | ~90% AI | Per-tool review — pattern compliance checked |
| Pro tool classes (~584) | ~95% AI | Lighter review — higher volume, pattern-based |
| Admin UI (settings, dashboards) | ~80% AI | Moderate review |
| REST controllers | ~70% AI | Heavy review — security-critical |
| JavaScript (chat UI, SPAs) | ~60% AI | Moderate review — behavior tested manually |
| Documentation | ~85% AI | Light review — accuracy spot-checked |
| Tests | ~90% AI | Light review — test correctness spot-checked |
| Compliance docs | ~95% AI | Moderate review — claims verified against code |

---

## The Multi-Agent Challenge

The biggest development challenge has been coordinating multiple AI agents so they don't:
- Duplicate each other's work
- Introduce conflicting patterns
- Violate security rules

The `AGENTS.md` file is the single source of truth for agent coordination. If you're reviewing the codebase and find inconsistent patterns, this is likely where the breakdown occurred.

---

## Human Review Isn't Perfect

Be aware of these human-review limitations:

1. **Volume** — With ~815 tool classes and ~3,000 PHP files, some files get more scrutiny than others. The Pro addon's 584 tool classes received lighter review than the base plugin's infrastructure.

2. **Security fatigue** — A human reviewing the 500th tool's permission callback is less vigilant than reviewing the 5th.

3. **AI trust** — Over time, humans may accept AI output more readily if it "looks right" and passes CI, without deeply analyzing the logic.

4. **Documentation drift** — Compliance documentation may claim a fix is in place that was only partially implemented or has since regressed.

---

## Recommendations for Reviewers

1. **Start with the infrastructure** — The base plugin's core classes (`Plugin`, `REST`, `Tool_Registry`, `Tool_Base`) were more carefully reviewed than individual tool classes.

2. **Sample tools, don't audit all 815** — Random-sample 20-30 tools across base and Pro. If patterns hold, the rest likely follow.

3. **Pay extra attention to Pro** — Lower human-review volume per file, higher AI generation ratio.

4. **Verify compliance claims against live code** — Don't trust the compliance docs at face value. Spot-check 3-5 claimed fixes against the actual PHP files.

5. **Check the security boundaries** — REST permission callbacks, tool capability checks, input sanitization, output escaping. These are the areas where AI most commonly makes mistakes.

6. **Review `AGENTS.md` and `CLAUDE.md`** — Understanding the agent rules helps you understand what the AI was instructed to do (and where it might have gone off-script).

---

## Improvements Since Initial Development

The plugin has evolved significantly from its AI-heavy early days:

- **v1.1.3+**: Custom PHPCS sniffs now enforce tool-specific patterns at the CI level
- **v1.1.7+**: 13 capability flags corrected (AI had mislabeled tools)
- **v1.1.8+**: Full re-audit of all 13 WP.org guidelines
- **v1.1.11+**: Canonical return envelope enforcement
- **v1.1.21+**: Security audit with 50 findings, systematic remediation
- **v1.1.24+**: Folder README convention (every PHP subdirectory documents its purpose)
- **April 2026**: External security audit completed. 5 High findings identified, 3 fixed, 2 partially fixed.
- **May–June 2026 (v1.1.22–v1.1.27)**: Pre-submission code review — 6-agent parallel audit, 1 Critical + 5 Warnings resolved. Addons PHPCS 93% reduction. Gate 2 output escaping hardened. 18 files fixed across 3 audit passes. v1.1.26: Cross-platform extraction engine (Phases 0–2), site-builder node-graph pipeline, SPA a11y hardening, screenshot & docs overhaul. v1.1.27: Real-time SSE streaming for OpenAI/DeepSeek, 35 new OOS core tools, 8 JFB submission tool fixes, Extended Cognition vision recognition, June 2026 model pricing update.

The project has moved from "AI wrote everything" to "AI writes, human reviews, CI enforces." The code quality and security posture have improved with each release.

---

**Related documents:** [FOR_REVIEWERS.md](../project/FOR_REVIEWERS.md) · [SECURITY_POSTURE.md](../operations/security/SECURITY_POSTURE.md) · [ADDON_INVENTORY.md](../project/ADDON_INVENTORY.md) · [AGENTS.md](../../AGENTS.md) · [CLAUDE.md](../../CLAUDE.md)
