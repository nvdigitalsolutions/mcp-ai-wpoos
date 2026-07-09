# Pro-SPA: Tool Shortcuts & Slash Commands Migration

> **Status:** In Progress | **PRs:** TBD | **Branch:** `feature/pro-spa-tool-shortcuts-commands`

## Summary

Migrate the legacy chat's tool shortcut buttons and slash command system into the Pro-SPA v2 as two additional toolbar drawer buttons (matching the existing Memory drawer pattern).

## Current State

### Legacy Tool Shortcuts
- Rendered as collapsible horizontal pill buttons above the textarea
- Data from `WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts($assistant_id)`
- Merges custom shortcuts (post meta) + prebuilt shortcuts (`WP_MCP_AI_Tool_Shortcuts_Interface`) + auto-fallbacks
- Shortcut shape: `{ label, payload, tool, description }`
- Click inserts payload text into textarea

### Legacy Slash Commands
- Server-side `WP_MCP_AI_Slash_Command_Handler` — registers, routes, executes `/command`
- Used by Telegram/Discord bots, WP-CLI, admin pages
- NOT surfaced in the legacy chat UI (only typeable in textarea)

## Design Decisions

| Decision | Rationale |
|---|---|
| **Drawer pattern** (not inline pills) | Scales to 50+ tools; supports search/filter/categories/favorites |
| **Two separate drawers** | Tools and slash commands are semantically different; separate UIs avoid confusion |
| **Right-side panel** (matching Memory) | Consistent UX; chat stays live while browsing |
| **Controlled component** (props: `isOpen`, `onClose`) | Parent owns one-open-at-a-time semantics |
| **REST-backed** (not preloaded JSON) | Keeps config payload small; data is per-assistant and changes rarely |
| **SWR for caching** | Already in the pro-spa; handles loading/error/revalidation |

## Implementation Plan

### Phase 1: REST Endpoints (Backend)

**File: `addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-tool-shortcuts.php`**
- `GET /mcp-ai-pro/v1/tool-shortcuts?assistant_id=N`
- Returns categorized shortcut list

**File: `addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-slash-commands.php`**
- `GET /mcp-ai-pro/v1/slash-commands`
- Returns all registered slash commands

**File: `addons/pro/includes/class-wp-mcp-ai-pro-spa-loader.php`** (edit)
- Add `shortcuts` and `slashCommands` to `endpoints` array

### Phase 2: API Clients (Frontend)

**File: `src/api/toolShortcuts.ts`**
- `ToolShortcutsClient` class with `list()` method

**File: `src/api/slashCommands.ts`**
- `SlashCommandsClient` class with `list()` method

### Phase 3: React Components (Frontend)

**File: `src/features/chat/ToolShortcutsDrawer.tsx`**
- Drawer with: search input, categorized list, favorites, recent
- Props: `isOpen`, `onClose`, `endpoint`, `nonce`, `assistantId`, `onInsertPayload`, `toggleRef`

**File: `src/features/chat/SlashCommandsDrawer.tsx`**
- Drawer with: search input, categorized command list
- Props: same pattern as ToolShortcutsDrawer

**File: `src/features/chat/ChatPage.tsx`** (edit)
- Add two toolbar buttons + state + drawer components

**File: `src/features/chat/AgentPanel.tsx`** (edit, optional)
- May need to expose `sendMessage` for drawer callbacks

### Phase 4: Types

**File: `src/types/toolShortcuts.ts`**
```ts
interface ToolShortcut {
  id: string;
  label: string;
  payload: string;
  tool: string;
  description: string;
  category: string;
  icon: string;
}
```

**File: `src/types/slashCommands.ts`**
```ts
interface SlashCommand {
  command: string;
  description: string;
  usage: string;
  parameters: string[];
  category: string;
}
```

## Edge Cases

| State | Tools Drawer | Commands Drawer |
|---|---|---|
| **Loading** | Skeleton cards | Skeleton list |
| **Empty** | "No tool shortcuts configured" + admin link | "No slash commands available" |
| **Error** | Retry button | Retry button |
| **No assistant** | "Select an assistant to browse tools" | N/A (global) |
| **Filtered empty** | "No tools match your search" | "No commands match your search" |
| **Mobile** | Full-width bottom sheet | Full-width bottom sheet |

## Files Changed

| File | Change | Purpose |
|---|---|---|
| `addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-tool-shortcuts.php` | **NEW** | Tool shortcuts REST endpoint |
| `addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-slash-commands.php` | **NEW** | Slash commands REST endpoint |
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-loader.php` | **EDIT** | Add endpoint URLs to config |
| `addons/pro/assets/spa-v2/src/api/toolShortcuts.ts` | **NEW** | API client |
| `addons/pro/assets/spa-v2/src/api/slashCommands.ts` | **NEW** | API client |
| `addons/pro/assets/spa-v2/src/features/chat/ToolShortcutsDrawer.tsx` | **NEW** | React drawer component |
| `addons/pro/assets/spa-v2/src/features/chat/SlashCommandsDrawer.tsx` | **NEW** | React drawer component |
| `addons/pro/assets/spa-v2/src/features/chat/ChatPage.tsx` | **EDIT** | Toolbar buttons + state |
| `addons/pro/assets/spa-v2/src/types/toolShortcuts.ts` | **NEW** | TypeScript types |
| `addons/pro/assets/spa-v2/src/types/slashCommands.ts` | **NEW** | TypeScript types |
