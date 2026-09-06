---
type: Skill
name: karpathy-coding-principles
description: Apply Karpathy-inspired coding behavior guidelines — think before coding, prefer simplicity, make surgical changes, and execute toward verifiable goals. Reduces wrong assumptions, overengineering, and unintended side-effects.
license: MIT
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI, NV oOS
---

# Karpathy Coding Principles

Four behavioral guidelines that directly address the most common LLM coding pitfalls identified by Andrej Karpathy. Apply these when writing, reviewing, or modifying code.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing anything:

- State your assumptions explicitly. If uncertain, ask rather than guess.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

**Why this matters:** LLMs often pick an interpretation silently and run with it, leading to implementations that solve the wrong problem.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

**The test:** Would a senior engineer say this is overcomplicated? If yes, simplify.

**Why this matters:** LLMs tend to over-engineer — bloating abstractions, adding configurability nobody asked for, and implementing 1,000 lines when 100 would do.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:

- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

**The test:** Every changed line should trace directly to the user's request.

**Why this matters:** LLMs sometimes change or remove code they don't sufficiently understand as side-effects, even when it's orthogonal to the actual task.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform imperative tasks into verifiable goals:

| Instead of... | Transform to... |
|--------------|-----------------|
| "Add validation" | "Write tests for invalid inputs, then make them pass" |
| "Fix the bug" | "Write a test that reproduces it, then make it pass" |
| "Refactor X" | "Ensure tests pass before and after" |

For multi-step tasks, state a brief plan:

```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let the AI loop independently. Weak criteria ("make it work") require constant clarification.

**Why this matters:** LLMs are exceptionally good at looping until they meet specific goals. Give them success criteria, not just instructions.

---

**These guidelines are working if you see:**

- Fewer unnecessary changes in diffs — only requested changes appear.
- Fewer rewrites due to overcomplication — code is simple the first time.
- Clarifying questions come before implementation, not after mistakes.
- Clean, minimal PRs — no drive-by refactoring or unsolicited "improvements".
