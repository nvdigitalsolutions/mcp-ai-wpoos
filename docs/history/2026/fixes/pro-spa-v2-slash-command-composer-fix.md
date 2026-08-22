# Pro SPA v2 Composer Slash-Command Routing Fix

**Date:** August 22, 2026
**Status:** ✅ Complete
**Issue:** Slash commands typed into the Pro SPA v2 composer (e.g. `/workflow`) were sent to the LLM as plain chat messages instead of being executed server-side — "nothing happens". The legacy chat client (assets/js/slash-commands.js) already intercepted `/`-prefixed submissions correctly.

## Problem Statement

1. **No composer intercept in the SPA v2** — `AgentPanel` passed `/`-prefixed input straight to `useChat.handleSubmit()`, so the text went to the model. The `/execute` REST endpoint was only reachable from the Commands drawer.
2. **Stale-closure message appends** — `ChatPage.handleExecuteSlashCommand` appended the user echo and then the result via `setMessages( [ ...messages, msg ] )`. Because both calls captured the same `messages` snapshot, the second call overwrote the first and the user's command bubble vanished.
3. **Drawer executed placeholder usage verbatim** — clicking `/workflow` in the drawer sent `/workflow <name>` to the server, which always failed validation (400 "Please specify a workflow name…").
4. **Drawer "insert" wired to send** — `onInsertPayload` was bound to `sendMessage`, so Shift+Click submitted the raw command to the LLM instead of inserting it into the composer.
5. **Legacy error paths lost transcript context** — the user's command bubble was only echoed on success, not on errors.

## Solution

### 1. Composer slash-command routing (`AgentPanel.tsx`)

Added `onSubmitSlashCommand` / `isBusy` props and a `submitComposer` router used by both Enter (`handleKeyDown`) and form submit:

- Input starting with `/` → clear the composer and call `onSubmitSlashCommand( rawInput )` (server-side execution).
- Anything else → existing `handleSubmit()` (LLM chat) flow.
- `isBusy` disables the composer and shows an "Executing…" send button while a command runs.

### 2. Fixed executor + busy state (`ChatPage.tsx`)

- `handleExecuteSlashCommand` now uses functional `setMessages( ( prev ) => [ ...prev, msg ] )` updates so the user echo and the result/error can never clobber each other.
- Added `normalizeSlashResult()` so string/array/object results render safely through `MessageView`.
- Added `slashBusy` state + `handleComposerSubmit` wrapper that swallows the rejection (the error is already appended to the transcript as a `❌` message).
- Wired `onSubmitSlashCommand` / `isBusy` into `AgentPanel`.

### 3. Drawer enhancements (`SlashCommandsDrawer.tsx`, `ChatPage.tsx`)

- Commands whose `usage` contains `<placeholder>` tokens (e.g. `/workflow <name>`) now **insert into the composer** instead of executing, so the user fills the required argument first. Tooltip reflects the behavior.
- Shift+Click also inserts (unchanged) and closes the drawer.
- `onInsertPayload` in `ChatPage` now inserts the text into the composer (`handleInputChange` + focus) instead of sending it via `sendMessage`.

### 4. Legacy parity (`assets/js/slash-commands.js`)

- Error paths now echo the user's command bubble (`displayUserCommand`) before the error, matching the success path.
- Bare `/` submissions are ignored (input cleared) instead of firing a doomed unknown-command request.

## Response semantics

The 400 the user observed for `/workflow` (and `/staus`) in the legacy client is correct server behavior: `WP_MCP_AI_REST_Slash_Command_Controller` returns HTTP 400 with the handler's `WP_Error` message for validation failures and unknown commands. The SPA now surfaces that same message in the chat transcript as `❌ <message>`.

## Testing

- New `src/__tests__/slash-commands-composer.test.tsx`: 12 tests covering composer routing (Enter + form submit), input trimming, no-executor fallback, busy label, and `normalizeSlashResult`.
- Full SPA suite: 117 tests passing.
- `npm run typecheck` and ESLint on changed files: clean.
- Production bundle rebuilt (`assets/dist/pro-spa.js`).

## Files Changed

1. `addons/pro/assets/spa-v2/src/features/chat/AgentPanel.tsx` — composer submit routing + busy state.
2. `addons/pro/assets/spa-v2/src/features/chat/ChatPage.tsx` — executor rewrite, `normalizeSlashResult`, `slashBusy`, drawer insert wiring.
3. `addons/pro/assets/spa-v2/src/features/chat/SlashCommandsDrawer.tsx` — insert-instead-of-execute for commands needing arguments.
4. `addons/pro/assets/spa-v2/src/hooks/useChatSpoke.ts` — widened `setMessages` type to support functional updates.
5. `addons/pro/assets/spa-v2/src/__tests__/slash-commands-composer.test.tsx` — new tests.
6. `assets/js/slash-commands.js` — legacy error-path echo + bare-slash guard.
7. `addons/pro/assets/spa-v2/assets/dist/pro-spa.js` — rebuilt bundle.

## Related Documentation

- [Slash Commands Guide](../../features/slash-commands-guide.md)
- [Slash Command URL Duplication Fix](slash-command-url-duplication-fix.md)
- [Slash Commands Chat Integration Fix](slash-commands-chat-integration-fix.md)
