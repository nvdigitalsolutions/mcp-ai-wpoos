# MCP Prompts Scoped to the Authenticated Assistant — Fix Details

## Problem Description

- `prompts/list` returned a prompt entry for **every published assistant** on
  the site regardless of the caller's credentials. Zed-style clients render
  one slash command per prompt, so a site with many assistants flooded the
  client's command palette.
- `prompts/get` resolved any assistant's system prompt by slug with no scope
  check, letting one connection read another assistant's system prompt.

## Root Cause

Both methods iterated all published `mcp_ai_assistant` posts (or looked them
up by slug) without applying the assistant scoping that `tools/list` already
enforced for token-bound credentials.

## Solution Implemented

File: `includes/class-wp-mcp-ai-rest-mcp-methods.php` (methods
`mcp_prompts_list()` / `mcp_prompts_get()`)

1. Both methods resolve the assistant via `resolve_assistant_id()` and apply
   `apply_token_assistant_scope()` — the same path used by `tools/list`.
2. Token-bound credentials see only their own assistant's prompt; unscoped
   authentication (OAuth, mesh) falls back to the site's default assistant.
3. `prompts/get` returns a not-found error for out-of-scope slugs instead of
   the assistant's system prompt.
4. With no resolvable assistant, `prompts/list` returns an empty prompt list
   rather than surfacing every assistant on the site.
5. `prompts/list` exposes published assistants only, matching the
   `prompts/get` lookup.

## Test Coverage

Covered by the existing MCP prompt tests (`tests/test-mcp-prompts-get.php`)
and the MCP endpoint suite (`tests/test-mcp-endpoint.php`,
`tests/test-mcp-client-compatibility.php`). Scoping follows the `tools/list`
pattern, which is exercised by the Fleet Operator scoping tests.

## Related

- [PR #5880](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5880)
- [`.context/rest-api.md`](../../../.context/rest-api.md) — rule 6 (MCP prompt scoping)
