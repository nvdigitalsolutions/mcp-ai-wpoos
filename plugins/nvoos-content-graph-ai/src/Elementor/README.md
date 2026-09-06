# Elementor — chat-family widgets

**Wave D-UI-3.** Ecosystem Elementor integration for the chat-family
surfaces. Everything here is additive and optional: the hub registers
its hooks unconditionally, but every widget class file bails out early
when `\Elementor\Widget_Base` is unavailable, so the addon never
hard-depends on Elementor.

## Public surface

| File | Purpose |
|---|---|
| `ElementorHub.php` | Registers the `nvoos-content-graph-ai` Elementor panel category (`elementor/elements/categories_registered`) and the widgets (`elementor/widgets/register`). Safe without Elementor. |
| `ChatWidget.php` | `nvoos_cg_chat` — thin wrapper rendering `[nvoos_content_graph_chat]` with controls: assistant, allow_guests, provider, model, height, show_cost, placeholder. |
| `ChatBubbleWidget.php` | `nvoos_cg_chat_bubble` — floating trigger + panel shell embedding `[nvoos_content_graph_chat]`; controls: position/size/animation/tooltip, panel title/width/height, auto-open/remember-state/badge, bubble/header colours. Markup mirrors `Blocks\ChatBubbleBlock::render()` (`nvoos-cg-bubble*` classes, `--nvoos-cg-bubble-*` CSS variables). |

## Neighbours

- `src/Blocks/` — the Gutenberg chat + chat-bubble blocks (D-UI-2) share
  the bubble script/style handles and the bubble markup shape.
- `src/Frontend/ChatShortcode.php` — the widget render target
  (`[nvoos_content_graph_chat]`, D-UI-1b); its script/style handles are
  declared as the widgets' `get_*_depends()` handles.

## Context files

Load alongside this folder: `.context/tool-registry.md` (tool patterns
are not used here) and the folder convention note in
`docs/developer/folder-readme-convention.md`.

## Documented deviations (aligned port, not byte-port)

- The base's main chat widget surface (voice/WebRTC/Pro services/template
  selector) is not ported — the CG widget vocabulary is the shortcode's
  attribute set.
- The base bubble widget's extra controls (bubble icon, hover/badge
  colours, panel background/radius) are deferred until the CG bubble
  styles grow counterparts.
- The bubble is rendered inline (no wp_footer promotion): the companion
  `content-graph-ai-chat-bubble.js` (D-UI-2) initialises eagerly and the
  bubble styles use `position: fixed`.
- The base widget defers chat initialisation until first open; the CG
  widget is small enough to initialise eagerly (same simplification as
  the D-UI-2 block).
