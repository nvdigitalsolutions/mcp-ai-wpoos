# Proposal 088 — Assistant Tool Picker: Registry-Backed Add/Remove in the Assistants Editor

- **Date:** 2026-10-25
- **Status:** Approved for implementation (research complete; see §2)
- **Surfaces:** `addons/pro/assets/spa-v2/src/features/assistants/` (new `ToolPicker.tsx`, `AssistantsPage.tsx` integration), `addons/pro/assets/spa-v2/src/api/tools.ts` (flags field), `addons/pro/assets/spa-v2/src/styles/main.css` (picker styles), `addons/pro/assets/spa-v2/src/__tests__/tool-picker.test.tsx` (new). Backend: `includes/class-wp-mcp-ai-rest.php` (additive `flags` field on the tools envelope) + `tests/rest-api/test-rest-tools-list-envelope.php`.
- **Tracks:** assistant management UX, spa-v2, NV oOS Complete Console (consumed via the `examples/nvoos-complete-velocity` host)
- **Follows:** Proposal 083 (Complete Console app), the spa-v2 assistants REST surface, the tool-registry catalogue served by `GET mcp-ai/v1/tools`

## 1. Problem Statement

The Assistants editor (`features/assistants/AssistantsPage.tsx` — the page mounted at
`#/assistants` by the NV oOS Complete Console) edits an assistant's tool set with a
**single comma-separated text input**:

- The user must already know exact tool slugs. The Full registry exposes
  ~1,692 tools; there is no discovery, search, description, grouping, or
  validation in the editor.
- Typos and invented slugs are saved silently; the server then resolves them
  to nothing (or to unintended tools after slug normalization), with no
  feedback at edit time.
- Tools that require a capability the editing user does not hold can be
  attached without any indication.
- The registry data needed for a real picker already exists server-side
  (`GET mcp-ai/v1/tools` returns slug, name, description,
  `required_capability`, input schema for every registered tool, in one
  envelope — verified in `WP_MCP_AI_REST::handle_tools_list()`, which does not
  server-paginate) and is already wrapped client-side by
  `ToolsClient.list()` / `useTools()`.

Goal: replace the raw text field with an accessible, registry-backed
**searchable multiselect tool picker** that keeps the existing REST payload
contract (`tools: string[]`) byte-compatible.

Non-goals: tool *ordering* semantics, per-tool parameter overrides, preset
management, and any new endpoints (the single backend touch is one additive
envelope field — see §3/§4).

## 2. Research & Industry Standards

### 2.1 Accessibility — the ARIA Authoring Practices combobox + grouped listbox

- The canonical pattern for a searchable multiselect is the W3C ARIA APG
  [Combobox](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/) driving a
  [Listbox](https://www.w3.org/WAI/ARIA/apg/patterns/listbox/): an input with
  `role="combobox"`, `aria-autocomplete="list"`,
  `aria-activedescendant`, and `aria-controls`; a popup
  `role="listbox"` with `aria-multiselectable="true"`; per-option
  `aria-selected`; and grouped options via `role="group"` with accessible
  names ([grouped-listbox example](https://www.w3.org/WAI/ARIA/apg/patterns/listbox/examples/listbox-grouped/)).
- Keyboard contract: ↑/↓ move the active option, Enter toggles, Escape
  closes, Tab closes, and typed characters filter.

### 2.2 Multiselect UX for large option sets

- Inline search becomes necessary around ~15 options and mandatory at our
  scale (Setproduct,
  [dropdown UI design guide](https://www.setproduct.com/blog/dropdown-ui-design);
  Eleken, [dropdown menu best practices](https://www.eleken.co/blog-posts/dropdown-menu-ui)).
- Selected items must be visible as **chips/pills with per-chip remove and a
  clear-all**, and the open list must show checked state
  ([UX Patterns — multi-select input](https://uxpatterns.dev/patterns/forms/multi-select-input);
  [Baslon Digital](https://www.baslondigital.com/post/multiple-selection-in-dropdown)).
- For long lists, **group options under bold headers** so users can scan
  groups first (Tripaneer,
  [multi-selecting from a long list](https://medium.com/tripaneer-techblog/improving-the-usability-of-multi-selecting-from-a-long-list-63e1a67aab35)).
- Dual-list / toggle-list layouts keep "what is selected" constantly visible
  (UX StackExchange,
  [multi-selection dropdown implementation](https://ux.stackexchange.com/questions/74996/multi-selection-dropdown-list-implementation-best-practice)).

### 2.3 Agent-platform tool configuration conventions

- The dominant pattern across agent platforms — OpenAI's Assistants
  playground, AnythingLLM's **Agent Skills** (per-agent enable/disable lists),
  n8n's **Tools Agent** configuration — is a **category-grouped, per-agent
  toggle list with name + description as secondary text**, not free-text
  slug entry.
- "Creatable" token inputs (react-select-style) retain typed entry for values
  outside the option list — the right hybrid for slugs not yet in the
  registry (forward compatibility).

### 2.4 Tool count is a quality lever, not just a preference

- Every tool granted to an assistant is injected into the model's context and
  competes for selection accuracy; lean tool sets measurably improve agent
  reliability (apxml,
  [LLM agent tool selection](https://apxml.com/courses/building-advanced-llm-agent-tools/chapter-3-llm-tool-selection-orchestration/agent-tool-selection)).
  The picker should therefore **surface the selected count** and make
  pruning easy (per-chip remove, clear-all, bulk add of visible matches).

### 2.5 Capability awareness in admin surfaces

- The registry carries `required_capability` per tool, and the console already
  knows the current user's capabilities (`runtime.user.capabilities` via
  `wp/v2/users/me?context=edit`). Admin surfaces should grey out options the
  user cannot grant (standard RBAC-gated admin UX), while still allowing
  deselection of already-attached tools.

### 2.6 Severity colour coding — reuse the in-house flag palette

- The product already colour-codes assistant tool chips by **capability
  flags** in the legacy assistant editor
  (`includes/helpers/class-wp-mcp-ai-tool-presets-helper.php::getChipColours()`):
  danger red for `data-destruction`/`irreversible`/`financial-impact`/
  `access-control-change`, orange for `write`/`state-changing`, yellow for
  `external-communication`, green for `read-only`, indigo default. Flags come
  from the canonical `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Colour-coded destructive/state-changing affordances are standard admin-UX
  practice (warnings near dangerous actions), and the same vocabulary already
  drives enforcement (read-only chat profiles, `Destructive_Ops_Gate`,
  `Action_Safety_Profile`). The picker must reuse it so the two editors tell
  the same story.

## 3. Design

New component `features/assistants/ToolPicker.tsx` (controlled, presentational):

```
AssistantsPage ── selected: string[] ──► ToolPicker ── onChange(next) ──► AssistantsPage
        │                                     ▲
        │ registry (useTools, no assistant_id — full catalogue) + capabilities
        └─────────────────────────────────────┘
```

- **Registry source:** `useTools()` without `assistant_id` — the server scopes
  the list to the assistant's *allowed* tools when `assistant_id` is passed;
  the picker needs the full catalogue. The route returns the complete registry
  in one envelope (verified §1), so no pagination work is required;
  `per_page=200` in `ToolsClient` is inert for this route.
- **Structure (ARIA APG):** chips region (selected slugs, name-resolved from
  the registry, per-chip remove) → combobox input → popup with grouped
  `role="listbox"` (`aria-multiselectable`), groups by the `category` already
  derived by `ToolsClient` (`deriveToolCategory`), plus a popup footer
  (outside the listbox) with `Add "…"` (creatable entry), `Add all matching`,
  and `Clear all`.
- **Search** filters across name, description, and slug — the same predicate
  the Tools page uses.
- **Capability gating:** options whose `required_capability` the user lacks
  are `aria-disabled` and unselectable — *unless already selected*, in which
  case they remain removable. Empty capability list = unknown = fail open
  (repo convention).
- **Unknown slugs:** slugs already stored on an assistant that are not in the
  registry are kept in the payload, rendered as flagged chips, and counted in
  a warning summary — never silently dropped.
- **Payload contract unchanged:** `handleSave` continues to send
  `tools: string[]`; only the *form state* changes from a comma string to an
  array (the legacy `parseTools` string path is superseded by the picker's
  `parseToolSlugs`, which still handles pasted `"a, b, c"` entry).
- **Severity colour coding:** chips and option rows are tinted by the tool's
  capability flags using the exact legacy palette and precedence
  (§2.6), via a new `toolSeverity()` helper. This requires the tools envelope
  to expose `flags` — added server-side from
  `get_capability_flags()` (one additive field, `[]` when the tool does not
  implement the flags interface), mirrored as `ToolDefinition.flags`.

## 4. Implementation

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-rest.php` | `handle_tools_list()` entries gain `'flags' => $flags` (from `WP_MCP_AI_Tool_Capability_Flags_Interface::get_capability_flags()`, `[]` otherwise); `flags` added to the `_fields` allowlist |
| `tests/rest-api/test-rest-tools-list-envelope.php` | Assert the `flags` key + array type on entries |
| `src/api/tools.ts` | `ToolDefinition.flags?: string[]`; `list()` maps it from the envelope |
| `src/features/assistants/ToolPicker.tsx` | New presentational component (combobox + grouped listbox + chips + footer actions + `parseToolSlugs` + `toolSeverity`) |
| `src/features/assistants/AssistantsPage.tsx` | `AssistantFormFields.tools` becomes `string[]`; text input replaced by `<ToolPicker>` wired to `useTools` + `runtime.user.capabilities` |
| `src/styles/main.css` | `.nvoos-pro-spa-tool-picker*` block appended after the assistants-form styles (legacy severity palette + existing CSS-var tokens) |
| `src/__tests__/tool-picker.test.tsx` | vitest + testing-library coverage (§5) |

## 5. Testing & Validation

- Unit tests for `parseToolSlugs` (commas, newlines, whitespace, empties),
  `toolSeverity` (precedence, neutral fallback), and the legacy-palette
  mapping.
- Component tests (jsdom): chip rendering + name resolution, severity chip
  colour classes, unknown-slug flag, search filtering, click toggle on/off,
  capability-gated options (`aria-disabled`, no toggle), Enter-to-add-raw-slug,
  comma-paste add, clear-all, add-all-matching.
- Gates: `npm run typecheck`, `npm test` (targeted file), `npm run lint:a11y`
  scoped to the changed files — run from `addons/pro/assets/spa-v2`.
- Backend: PHPUnit on `tests/rest-api/test-rest-tools-list-envelope.php`
  (plus the tools-route suites) in the Docker test environment; `phpcs` on
  `includes/class-wp-mcp-ai-rest.php`.

## 6. Open Questions & Risks

- **Payload size:** the registry envelope is large (~1.7k tools with input
  schemas) and is fetched on form open. Accepted for now (the Tools page
  already does the same); the added `flags` array is small (short strings),
  and a follow-up can slim the picker's fetch with `_fields=name,description,flags`
  (all three now in the server allowlist).
- **Tool ordering** (some engines weight tool order) is not addressed — the
  picker preserves insertion order only.
- **Preset assistants** may carry slugs unknown to the current registry;
  handled by the unknown-slug flag, not removed.
- **Cache shape drift:** `WP_MCP_AI_REST_Cache` may briefly serve pre-deploy
  tool lists without `flags` until entries expire; the client tolerates the
  absence (`flags: []`).
