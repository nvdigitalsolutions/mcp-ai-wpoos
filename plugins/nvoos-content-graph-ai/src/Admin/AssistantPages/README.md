# AssistantPages — assistant builder/admin pages

**Wave D-UI-4.** Ported assistant admin pages: the four-tab Build
Assistant page, the template-driven Create Assistant page, and their
AJAX creation flows. Registered standalone-only — the base plugin owns
the same pages in monolith installs.

## Public surface

| File | Purpose |
|---|---|
| `BuildAssistantPage.php` | `nvoos-cg-build-assistant` — Manual / Prompt / Configuration / Advanced tabs, profession/region option lists, provider list, stats, create-assistant AJAX (`nvoos_cg_ai_create_assistant`). |
| `AddAssistantPage.php` | `nvoos-cg-add-assistant` — profession template grid + create modal, create-from-template AJAX (`nvoos_cg_ai_create_from_professional`) with byte-identical meta keys. |

## Neighbours

- `src/Admin/AssistantPostType.php` — the `mcp_ai_assistant` CPT and the
  meta-key constants the pages write.
- `src/Admin/AssistantPages.php` — the hub that registers both pages and
  routes their AJAX actions.
- `src/Frontend/ChatShortcode.php` — the chat widget the Prompt-tab
  modal embeds.
- `assets/js/admin-build-assistant.js` + `assets/js/admin-add-assistant.js`
  and their CSS counterparts — page behaviours.

## Context files

Load alongside this folder: `.context/tool-registry.md` (not used here),
`docs/developer/folder-readme-convention.md`, and the ecosystem port
tracker `docs/project/ecosystem-port-tracker.md` (Wave D-UI-4).

## Documented deviations (aligned port, not byte-port)

- Page slugs, asset handles, and CSS class names use the ecosystem's
  `nvoos-cg-*` vocabulary so both plugins' pages can coexist in
  monolith installs.
- The Prompt tab's Tools Grid / Knowledge Base block components and the
  base's full prompt construction stay with the base until their owning
  waves land; the background-create flag answers `async_unavailable`
  until the queue wave (E2) ports the async creator.
- The Test Assistant page ports in the next D-UI-4 slice (needs the
  admin chat-tester asset surface).
