<!-- Split out of ../SKILL.md on 2026-11-17. Content unchanged; moved here
     so the main skill stays under Zed's ~100 KB load limit. -->

## Proposal 123 — chrome typography pass (2026-11-06)

Header/footer fidelity round: the chrome was rebuild-blind to the source's
nav/call/footer type facts and its strip colors. All facts verified with
headless-chromium computed styles against the live source.

- **The shared cascade (`resolveElementRules`):** every chrome fact resolves
  through the CSS with an own-match-first ladder — `!important` (per
  DECLARATION, not per rule) > own-token match > context match, spec =
  `own * 1e6 + context * 1e8 + ruleIndex`. A rule matching NONE of the
  element's own tokens can only INHERIT — it must lose (the header's
  `.aux-header-light { color: #fff }` must not paint the call button; its
  real ink comes from its own classes). Pseudo-states AND state CLASSES
  skip (`:hover` + `aux-hover` — jayainc's nav had an `!important`
  `.aux-hover` rule that out-cascaded the resting white). `terminalFill`
  gates the element-level FILL to rules whose selector ENDS on an own
  token (the call button's gradient must never leak onto the header).
  `bgForCss` keeps rgba()/hsla() alpha raw — `rgba(8,26,67,0.9)` and
  `rgba(255,255,255,0.7)` ARE the facts, hex-flattening loses the look.
- **The fetch must see the whole sheet set:** MAX_STYLESHEETS 6 silently
  dropped jayainc's CUSTOMIZER file (`uploads/phlox-pro/custom.css` — the
  file with every real body/header/footer override). Raised to 40 with a
  3MB total cap that breaks BETWEEN files; the per-file cap went 512KB →
  2MB — a 512KB cut landed mid-`@media` in the 886KB main.css and the
  dangling brace swallowed EVERY stylesheet after it. Inline `<style>`
  blocks and `<link>` sheets now interleave in DOCUMENT order (WP prints
  the inline blocks after the links — the inline 16px nav rule must
  cascade AFTER the linked 0.875em rule).
- **`topLevelRules` (the splitter):** the flat RULE_RE desyncs on `@media`
  blocks and on braces/quotes inside `url(data:…)` payloads — one svg data
  URI ate every later `body { font-family }` rule. The splitter is a
  char scanner: comments skipped, quoted strings inert-but-accumulated,
  quotes only open strings OUTSIDE `url(...)` parens, braces inside parens
  are value bytes. Media bodies stay inert blobs (no element tokens match
  a media selector).
- **Typography harvest:** the body font comes from the LAST `body`-selector
  rule (frequency ranking miscounts Elementor's unused token-layer
  families — jayainc's body rendered Rubik while the tokens said Roboto);
  the DISPLAY font from the `h1..h6` rule; `bodyWeight` token (300) from
  the body rule; `font` emits even when body === display (a single-family
  source renders BOTH roles in it). @font-face blocks are stripped before
  family ranking (load evidence, not usage).
- **New chrome facts (all in `design.chrome`):** `topbarBg/Ink`,
  `headerBg/Ink`, `navStyle` (weight/size/transform/letterSpacing),
  `navInk`, `call.style` (padding/radius/weight/size/transform — jayainc:
  `14px 43px`, `100em` pill, 400, 16px, uppercase), `footerBarBg/Ink` (the
  dark copyright strip — the bar INK resolves from the COPYRIGHT node, not
  the footer element), `footerHeadingStyle` (500/29px/capitalize),
  `chromeSize` (16px — the body rule's `100%` resolves against the html
  ROOT rule, never the rebuilt 13px content body).
- **Template wiring:** the facts ride as CSS vars on `<body>`
  (`--forge-chrome-size`, `--forge-header-bg`, `--forge-nav-transform`,
  `--forge-call-pad` → falls back to `--forge-cta-pad` → template
  default, …); `.site-header--themed`/`.site-footer--themed` classes drop
  the default hairline when a harvested bg exists; the footer splits into
  two full-width strips (light subfooter + dark bar — the source's rhythm,
  the inner containers re-measure); **`.site-header--sticky` must chain
  `var(--forge-header-bg, var(--forge-surface))`** — the sticky variant
  painted surface over the navy (caught in headless verification).
- **Verification:** headless chromium + a tiny in-process static server
  (the MCP browser backends cannot reach this machine's localhost) — dump
  computed styles per chrome element and diff against the live source.
  Re-scaffold (not manual CSS copies) after template edits — the
  `FORGE:tokens` rewrite only runs in the scaffold pipeline.
- **The top strip's STRUCTURE is measured** (`measureTopbar`, 2026-11-12):
  items (icon + text, the address's own line break), icon-only socials,
  alignment, height and the icon/item rhythm — `analyzeChrome` only reads its
  text lines. Contract + the `tsString` newline gotcha:
  [`measured-band-facts-and-sticky-header.md`](measured-band-facts-and-sticky-header.md) §11.

### The mobile nav disclosure (`chrome.navToggle`)

The chrome pass captured nav LINKS but nothing about the control that hides
them; a rebuild without it wrapped six links over three rows at 390px.
Presence + accessible name are markup facts (`analyzeChrome`); WHERE it appears
is a browser fact (`measureNavToggle`) — never guess a breakpoint, since Brando
collapses at 991px and Bootstrap at 768px. The template renders an APG
disclosure button (Escape closes + returns focus, focus-out closes, resize
re-syncs) and hides the nav **only** via `data-collapsed`, which JS alone sets,
so JS-off leaves it inline at every width. A chrome fact needs THREE edits
(`ForgeChrome`, the emitted interface, the emitted value) or it silently
disappears — detail: [`mobile-nav-disclosure.md`](mobile-nav-disclosure.md).

**The OPENED panel is a measured SURFACE** (`chrome.mobileNav`), and
`verify --chrome` diffs it (plus the header BOX — `chrome.geometry` — the footer
brand ROW — `chrome.footerBrand`: logo/social positions per viewport — and
colour-region resolution) —
[`chrome-gates.md`](chrome-gates.md).
