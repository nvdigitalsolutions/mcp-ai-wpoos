# Proposal 080 — Vertically Expansible Chat Composer & Window (spa-v2)

- **Date:** 2026-10-18
- **Status:** Draft (research complete, awaiting approval)
- **Surfaces:** `addons/pro/assets/spa-v2` (AgentPanel composer), the standalone Vite app (`examples/nvoos-pro-spa-vite`), and the plugin PHP loader page that mounts spa-v2
- **Tracks:** chat UX, mobile web

## 1. Problem Statement

The spa-v2 chat composer (`AgentPanel.tsx`) is a single-row `<textarea rows={1}>`
styled with `resize: none; min-height: 44px; max-height: 160px`. The
`max-height` shows auto-grow was intended, but no mechanism grows the box, so:

1. **Desktop:** long prompts scroll horizontally in a 1-line box; the user can
   never see more than one line of their own draft.
2. **Mobile:** the on-screen keyboard overlays the layout (the embedded page
   ships no `interactive-widget` viewport directive, and the layout uses a
   fixed-viewport height chain), so when the keyboard opens the conversation
   history above the composer is hidden. Combined with (1), a mobile user
   typically sees only their own single-line input and none of the context
   above it.
3. There is no way to enlarge the chat window itself (the whole chat region
   is a fixed flex column; the messages list simply gets whatever is left).

Goals:

- **G1.** Composer grows with content, 1 → 3 visible lines, then scrolls
  internally (never steals unbounded space).
- **G2.** On mobile, the page and the chat window reflow *above* the virtual
  keyboard so conversation context stays visible while typing.
- **G3.** (Optional, Zed-style) The user can drag a divider to resize the
  chat window vertically; the choice persists and is keyboard-accessible.

Non-goals: no new runtime dependencies; no change to message rendering or
the SSE pipeline; no redesign of the three-column shell; legacy `chat-spa`
addon (single-line `<input>`) is out of scope for v1 (see §12).

## 2. Research & Industry Standards

### 2.1 Native CSS auto-grow — `field-sizing: content`

The long-awaited native answer is now **standard-track and shipped**
([MDN BCD](https://raw.githubusercontent.com/mdn/browser-compat-data/main/css/properties/field-sizing.json)):

| Browser | Since |
|---|---|
| Chrome / Edge / Opera / WebView | 123 |
| Firefox | 152 |
| Safari / iOS Safari | 26.2 |

`textarea { field-sizing: content; max-height: 3lh; overflow-y: auto; }`
auto-grows the box and caps it at 3 lines (`lh` unit = line height). This is
the preferred mechanism now, with a JS fallback for older engines (below).

### 2.2 JS fallback — `scrollHeight` auto-grow

The canonical, dependency-free technique ([CSS-Tricks "The Cleanest Trick for
Autogrowing Textareas"](https://css-tricks.com/the-cleanest-trick-for-autogrowing-textareas/),
community consensus in the same thread): on input, set
`height = 'auto'`, then `height = scrollHeight + 'px'`, cap with `max-height`
and switch to `overflow-y: auto`. Known quirks collected from the thread:
subtract vertical padding from the pixel height (or use `box-sizing:
border-box` so `scrollHeight` includes padding); iOS Safari adds an inherent
1px margin on textareas; re-run on container width change (a `ResizeObserver`
on the textarea covers rotation, sidebar collapse, and window resize).
Avoid the older grid-mirror (`::after` data-attribute) trick in React — it
duplicates state into the DOM and fights the AI SDK's controlled input.

### 2.3 Mobile keyboard — `interactive-widget` + dynamic viewport units

The industry-standard fix for "keyboard covers my chat" is the viewport meta
directive `interactive-widget=resizes-content`, supported **Chrome 108+,
Firefox 132+**, and WebKit support landing in 2026
([Chrome Developers — viewport resize behavior](https://developer.chrome.com/blog/viewport-resize-behavior/),
[htmhell advent calendar](https://www.htmhell.dev/adventcalendar/2024/4/),
[Modern CSS Layouts — Virtual Keyboard and interactive-widget](https://www.modern-css-layouts.com/mastering-container-queries-responsive-layouts/viewport-units-and-mobile-layout/virtual-keyboard-and-interactive-widget/)):

> "With `interactive-widget=resizes-content`, supporting browsers shrink the
> layout viewport when the keyboard opens. `100dvh` follows … and the
> composer row sits directly on top of it — the same result a native
> messaging app gives."

Pairing rule: the layout must be sized with **`100dvh` (dynamic viewport
height)** and/or the Visual Viewport API, not `100vh`, or the resize does
nothing. The modern-css-layouts article describes exactly our
flex-column chat layout as its canonical use case. Safari fallback (until
WebKit ships): listen to `window.visualViewport.resize` and offset the
composer / re-pin the scroll anchor.

### 2.4 Resizable panels — drag-divider pattern

Native apps (VS Code, Zed) resize chat/panels with a drag divider. On the web
the robust pattern is a **pointer-events drag handle** (pointerdown/pointermove/
pointerup + `setPointerCapture`) writing a CSS custom property
(`--composer-height`) with min/max clamps, plus Arrow-key support while
focused. Native CSS `resize: vertical` is rejected: it only renders a
bottom-right corner handle (wrong affordance for a top divider), is
unpredictable on flex children, and is not keyboard-accessible.

### 2.5 How Zed does it (UX reference only)

Zed's assistant panel exhibits three behaviors worth porting, though Zed is
native (GPUI) so none of the *implementation* transfers — only the UX:

1. The **bottom panel is draggable-resizable** via its top divider.
2. The **inline prompt editor auto-grows** with content up to a maximum
   fraction of the panel, then scrolls internally (identical to G1).
3. The assistant panel has a **"Zoom In" full-screen mode** for deep focus
   (stretch goal, §7 Phase 3).

## 3. Options Considered

| Option | Verdict |
|---|---|
| A. Only auto-grow composer (JS) | Necessary but insufficient — fixes G1 only |
| B. `field-sizing: content` alone | Best default, but Firefox/Safari support is brand-new; needs fallback |
| C. `interactive-widget=resizes-content` + `100dvh` | Highest mobile impact; adopt unconditionally (it is inert where unsupported) |
| D. Draggable divider (Zed-style) | Adopt as opt-in phase; adds real value on both desktop and mobile |
| E. `react-textarea-autosize` dependency | Rejected — trivial to hand-roll, headless/no-new-dep rule |
| F. contentEditable composer | Rejected — a11y/form/IEM risks, breaks AI SDK controlled input |
| G. Native CSS `resize: vertical` | Rejected — wrong affordance, no keyboard a11y (§2.4) |

**Decision:** B with JS fallback (A) → C → D, in phases. C is independent and
ships first because it alone fixes the "can't see the conversation above"
mobile complaint.

## 4. Proposed Design

### Phase 0 — Viewport & height chain (mobile reflow)

1. Standalone app `examples/nvoos-pro-spa-vite/index.html`:
   `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content" />`
2. PHP loader page (the admin/embedded page that mounts spa-v2 — locate via
   the `NVOOS_PRO_SPA` localizer): output the same viewport meta in `admin_head`
   **only on that page**.
3. `shell.css` + spa-v2 layout: replace the `100vh` height chain with `100dvh`
   (keep `100vh` fallback via a preceding declaration).
4. Safari fallback hook (`useVisualViewport`): subscribe to
   `visualViewport.resize/scroll`, expose `offsetTop`; on iOS the messages
   container gets `padding-bottom: <visualViewport.offsetTop>` so the
   scroll-anchor stays above the keyboard. Guard with
   `'visualViewport' in window`.

### Phase 1 — Composer auto-grow (1 → 3 lines)

In `AgentPanel.tsx`, replace the static `rows={1}` with an
`useAutoGrowTextarea` hook (new file `src/hooks/useAutoGrowTextarea.ts`):

```ts
// Sketch — final code obeys repo lint/format gates.
export function useAutoGrowTextarea( ref, maxLines = 3 ): void {
	useLayoutEffect( () => {
		const el = ref.current;
		if ( ! el ) return;
		const resize = () => {
			const line = parseFloat( getComputedStyle( el ).lineHeight ) || 20;
			const max = line * maxLines;
			el.style.height = 'auto';
			el.style.height = Math.min( el.scrollHeight, max ) + 'px';
		};
		const ro = new ResizeObserver( resize ); // width changes (rotate, resize)
		ro.observe( el );
		resize();
		return () => ro.disconnect();
	}, [ ref, maxLines ] );
}
```

CSS (`main.css`, `.nvoos-pro-spa-agent-panel__composer-input`):

```css
field-sizing: content;                 /* native path (Chrome 123+/FF 152+/Safari 26.2+) */
max-height: calc( 1.5em * 3 + 20px ); /* 3 lines @ line-height 1.5 + vertical padding */
overflow-y: auto;                     /* scroll internally past 3 lines */
resize: none;                         /* keep */
min-height: 44px;                     /* keep */
box-sizing: border-box;               /* scrollHeight includes padding */
```

JS fallback is inert where `field-sizing` works (`@supports (field-sizing: content)`
gates hook execution) — no double-growth. Existing Enter-to-send /
Shift+Enter-newline handling in `handleKeyDown` is unchanged. The
`CommandAutocomplete` panel already positions against the composer; verify it
tracks the growing height (it reads `cursorPos`, position is CSS-based — re-test).

### Phase 2 — Drag-resize divider above the composer (Zed-style)

- New `ResizableComposerHandle` component between the messages list and the
  composer bar. Sets `--composer-height` (clamped `min 72px` … `max 60dvh`) on
  the AgentPanel root; the composer wrapper gets
  `height: var(--composer-height, auto)` (auto ⇒ grows with content only).
- Pointer events: `setPointerCapture`, `touch-action: none` on the handle,
  44px tall hit-area (iOS tap-target minimum), visible grip line + focus ring.
- Keyboard: handle is a `role="separator"` with `aria-orientation="vertical"`
  (wait: separator between stacked regions is horizontal — use
  `aria-orientation="horizontal"`, ArrowUp/Down adjusts by 24px, Home/End =
  min/max, `aria-valuenow`/`aria-valuemin`/`aria-valuemax`).
- Persist per-user in `localStorage` (`nvoos-pro-spa.composerHeight`), restore
  on mount, ignore on first load (no value ⇒ auto).
- Collapse affordance: double-click/double-tap the handle toggles between
  "auto" and the last manual height (mirrors IDE divider behavior).

### Phase 3 (stretch) — Zoom/full-screen composer

"Focus mode" button in the composer toolbar opens the composer as a
`100dvh` overlay with a large auto-growing textarea (cap ≈ 40% viewport,
internal scroll beyond) — Zed's Zoom-In equivalent. Reuses the Phase 1 hook
with `maxLines` prop; closes on send/Escape. Deferred until Phase 1–2 feedback.

## 5. Implementation Plan

| # | Change | File(s) | Est. |
|---|---|---|---|
| 0a | viewport meta (`interactive-widget`) | `examples/nvoos-pro-spa-vite/index.html`, PHP loader `admin_head` | S |
| 0b | `100dvh` height chain (+ `100vh` fallback) | spa-v2 `shell.css`/layout styles, `main.css` | S |
| 0c | `useVisualViewport` hook + scroll-anchor offset | new `src/hooks/useVisualViewport.ts`, `AgentPanel.tsx` | M |
| 1a | `useAutoGrowTextarea` hook | new `src/hooks/useAutoGrowTextarea.ts` | S |
| 1b | composer CSS (field-sizing + cap + overflow) | `src/styles/main.css` (§4 Phase 1) | S |
| 1c | wire hook in AgentPanel (drop `rows`, keep a11y attrs) | `AgentPanel.tsx` | S |
| 2 | `ResizableComposerHandle` + `--composer-height` + persistence | new `src/components/shared/ResizableComposerHandle.tsx`, `AgentPanel.tsx`, `main.css` | M |
| 3 | Focus-mode overlay (stretch) | new component + toolbar button | M |

Conventions: NDS design tokens (`var(--nds-space-*, <fallback>)`) for
spacing/hit-areas; strings via `@wordpress/i18n` `__()` text-domain
`nvoos-pro-spa`; no new deps; RTL-safe (arrow-key semantics mirror in RTL);
reduced-motion respected for the focus-mode transition.

## 6. Testing & Verification

- **Vitest (jsdom)** — follow existing `src/__tests__` patterns
  (`slash-commands-composer.test.tsx`):
  - `useAutoGrowTextarea`: mock `scrollHeight` (Object.defineProperty), assert
    height grows to cap and stops at 3-line max; `ResizeObserver` mocked in
    `test-setup.ts` (already polyfilled there).
  - Composer integration: type 4 wrapped lines → height ≤ cap, `overflowY`
    scroll; Enter still submits; Shift+Enter inserts newline and grows.
  - `ResizableComposerHandle`: keyboard Arrow adjustments clamp; localStorage
    round-trip; double-click toggle.
- **Manual matrix:** Chrome desktop, Chrome Android (keyboard resizes content),
  Firefox, Safari iOS (visualViewport fallback path), iPad split view, 400px
  and 782px breakpoints, dark theme, RTL.
- **Standalone app:** rebuild `examples/nvoos-pro-spa-vite` and smoke-test the
  connection flow on a phone; verify `100dvh` in the standalone shell.
- **Plugin bundle:** `npm run build` in `addons/pro/assets/spa-v2`
  (esbuild IIFE — lazy-page inlining caveat from the spa-ui skill does not
  apply here; report bundle delta in the PR).

## 7. Risks & Edge Cases

- **iOS Safari quirks:** 1px textarea margin (CSS-Tricks thread), `visualViewport`
  event storms — debounce via rAF; `interactive-widget` unsupported until WebKit
  ships → Phase 0c covers it.
- **AI SDK controlled value:** programmatic sets (media insert, slash commands,
  edit-message, transcription) use the native value setter + input event —
  the auto-grow hook must run on `value` change (ResizeObserver + effect on
  `input`), not only on keystrokes.
- **Autocomplete anchoring:** `CommandAutocomplete` positions under the
  composer — re-verify after Phase 1 (growth shifts it down; desired).
- **Scroll anchor:** growing composer shrinks the messages viewport; keep the
  bottom-pinned scroll behavior (existing `scroll-padding-bottom` / anchor).
- **Streaming vs resize:** a mid-stream resize must not steal scroll
  position — only re-anchor when already pinned to bottom (existing behavior).
- **localStorage disabled/private mode:** wrap persistence in try/catch
  (repo pattern).

## 8. Rollout & Follow-ups

1. Ship Phase 0 first (pure CSS/meta, low risk, immediate mobile win).
2. Ship Phase 1 next (self-contained, testable).
3. Phase 2 behind a feature flag or as a follow-up PR; measure usage.
4. Follow-ups: port the same auto-grow to the legacy `addons/chat-spa`
   composer (currently a single-line `<input>`), and to the Memory drawer
   textarea if desired.

## 9. References

- MDN BCD `field-sizing` — https://github.com/mdn/browser-compat-data (css/properties/field-sizing.json)
- CSS-Tricks, *The Cleanest Trick for Autogrowing Textareas* — https://css-tricks.com/the-cleanest-trick-for-autogrowing-textareas/
- Chrome Developers, *Prepare for viewport resize behavior changes* — https://developer.chrome.com/blog/viewport-resize-behavior/
- htmhell advent calendar 2024/4 — *Control the Viewport Resize Behavior* — https://www.htmhell.dev/adventcalendar/2024/4/
- Modern CSS Layouts — *Virtual Keyboard and interactive-widget* — https://www.modern-css-layouts.com/mastering-container-queries-responsive-layouts/viewport-units-and-mobile-layout/virtual-keyboard-and-interactive-widget/
- Repo skill: `mcp-ai-wpoos-spa-ui` (spa-v2 build/test conventions) — `.agents/skills/mcp-ai-wpoos-spa-ui/SKILL.md`

## 10. Implementation Status (2026-10-18 — implemented)

All four phases shipped:

| Phase | Delivered |
|---|---|
| 0 — viewport & height chain | `interactive-widget=resizes-content` viewport meta on the SPA admin page (`class-wp-mcp-ai-pro-spa-loader.php` → `print_viewport_meta()`, hooked to `admin_head-<hook>`) and in `examples/nvoos-pro-spa-vite/index.html`; `dvh` fallbacks after every `vh` usage in `main.css` (#wpwrap, memory/sidebar/tasks drawers, 80vh modals) and `shell.css`; new `useVisualViewport` hook pads the messages list above the iOS keyboard and re-pins the scroll anchor |
| 1 — composer auto-grow | New `useAutoGrowTextarea` hook (native `field-sizing: content` path via `CSS.supports`, `scrollHeight` + `ResizeObserver` fallback); CSS owns the 3-line cap (`max-height: calc(1.5em * 3 + 20px + 2px)`, `overflow-y: auto`, `box-sizing: border-box`) |
| 2 — drag-resize divider | New `ResizableComposerHandle` (pointer drag with capture, Arrow/Home/End/Escape keys, double-click auto/manual toggle, 44px touch target) + `useComposerHeight` (clamp 72px…min(600px, 60% viewport), localStorage persistence); height applied via `--nvoos-composer-height` |
| 3 — focus (expanded) composer | Expand button in the composer actions opens a full-screen dialog with a large auto-growing textarea (cap 40dvh), Enter/Escape/backdrop handling, keyboard-inset padding |

**Deviations from the proposal** (all forced by the repo's own gates):

1. The divider is a native `<button>` without `role="separator"` — `eslint-plugin-jsx-a11y` rejects `separator` on a button and rejects non-interactive roles + `tabIndex` on a div; the pixel height is folded into a dynamic `aria-label` instead of `aria-valuenow`. ARIA `separator` semantics can be revisited if the lint config is relaxed.
2. `useAutoGrowTextarea` takes no `maxLines` — the CSS `max-height` owns the cap so the native and JS paths share one source of truth.
3. The front-end shortcode surface does not inject the viewport meta (themes own the front-end `<head>`); the admin page and the standalone app do.

**Validation:** `npm run typecheck` ✓ · `npm test` ✓ 200/200 (11 new tests in `composer-autogrow.test.tsx`) · `npm run lint:a11y` ✓ for all touched files (3 pre-existing failures remain in foreign files: `MediaList.tsx:80`, `MessageView.tsx:589`, `useMediaLibrary.ts:173`) · `php -l` ✓ on the loader · `npm run build` ✓ (dist rebuilt).

**Left to the release track:** CHANGELOG entry, plugin/addon version bump, POT regeneration (new translatable strings), and the manual mobile device matrix (Chrome Android, iOS Safari, Firefox) from §6.
