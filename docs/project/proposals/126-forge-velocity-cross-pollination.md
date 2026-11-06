# Proposal 126 — Forge × Velocity cross-pollination (industry-standards pass)

Status: **shipping in clusters** · Date: 2026-11-07 · Owner: forge track

## Summary

The two Astro apps — `examples/nvoos-astro-forge` (the WP→Astro generator)
and `examples/nvoos-astro-velocity` (the headless storefront) — evolved
side-by-side and each solved problems the other still has. This proposal
records the research-backed findings (web standards + industry practice)
and the adoption plan in both directions.

## Research findings (with citations)

| Topic | Standard / source | Decision |
|---|---|---|
| Responsive images | [web.dev — Serve responsive images](https://web.dev/articles/serve-responsive-images) (2–4× data waste on mobile, 3–5 sizes), [Astro Images guide](https://docs.astro.build/en/guides/images/) (`<Image>`/`<Picture>`, `inferSize`, `image.domains`, CLS-safe width/height, mandatory `alt`) | Forged media rebuilds through Astro Image; same-host-only optimization with plain-`<img>` passthrough |
| Scroll-driven animations | [MDN — `animation-timeline`](https://developer.mozilla.org/en-US/docs/Web/CSS/animation-timeline) (Baseline 2026; **reset-only inside the `animation` shorthand** — the minifier-merge trap) | CSS-native reveals/parallax, `@supports`-gated, esbuild minifier pinned (both apps) |
| Design tokens | [DTCG 2025.10](https://tr.designtokens.org/format/) (`$type`/`$value`, groups, `{ref}` aliases, composite typography/shadow/gradient) | Forge emits a DTCG 2025.10 interchange doc beside the flat CSS vars; velocity's ADS already implements the same shape |
| Automated a11y | [axe-core](https://github.com/dequelabs/axe-core) (industry engine, ~57% of WCAG issues automatically, zero-false-positive philosophy; **`color-contrast` does not work in JSDOM** → browser-based runs) | Browser (Playwright) axe gates; forge's contrast audit already runs pure WCAG math at scaffold time (Proposal 114) |
| Accessibility standard | [WCAG 2.2](https://www.w3.org/WAI/standards-guidelines/wcag/) (current ISO/IEC 40500:2025; AA target; SC 1.4.3 = 4.5:1 / 3:1) | Forge contrast gate (existing) + axe smoke; velocity axe gates (existing) |
| Islands architecture | [Astro islands](https://docs.astro.build/en/concepts/islands/) (selective hydration, `client:*` priorities, zero-JS default) | `data-hydrated` marker + `client:load`/`client:visible` discipline when forge gains islands |

## Adoption plan

### Cluster A — Forge (this PR, shipped)

- **Image pipeline (done):** the design-band components (Hero media grid,
  FeatureGrid, IconList, Gallery, TextBlock) now render through the
  existing `SmartImage` (Astro Image, same-host-only, `inferSize`, WebP +
  `srcset`); `SmartImage` gained an `eager` prop (`fetchpriority="high"`)
  for the LCP image. The collage photos and logo marks stay plain `<img>`
  (absolute offsets / exact SVG sizing — fidelity over optimization).
  The hermetic fixture now serves absolute About-page media URLs so the
  e2e pins the processed output (`/_astro/*.webp` + `srcset`).
- **DTCG interchange doc (done):** `renderTokensDtcg` emits
  `public/tokens.dtcg.json` (2025.10 core→semantic groups, typed tokens,
  `{ref}` aliases) beside the flat CSS vars — the NDS importer and ADS
  tooling can consume a forged site's design contract. Non-hex token
  values stay CSS-only (the var remains authoritative).

### Cluster B — Forge follow-ups (queued)

- **Axe e2e gate:** serve `dist/` and run `@axe-core/playwright` on `/` +
  one post page (wcag2a/wcag2aa/wcag21aa tags), mirroring velocity's spec.
  Needs playwright + chromium in the forge package and CI.
- **Content blocks:** port velocity's Portable Text blocks (FAQ accordion,
  tabs) into the forge post-body path so forged blogs get accessible
  interactive content.
- **Generated-artifact drift gate:** forge's `design.generated.ts` /
  `sections.generated.ts` are pinned by unit + e2e outcomes today; a
  velocity-style `check:generated` (regenerate → diff → fail) requires
  vendoring the generator into the template — evaluated in Cluster B.

### Cluster C — Velocity

- **CSS-native reveals:** adopt forge's `animation-timeline` scroll/view
  reveals on the landing page, with the esbuild minifier pin (the
  lightningcss shorthand-merge trap documented in MDN's reset-only
  semantics) and `prefers-reduced-motion` gating.
- **Sticky-header anchor UX:** `scroll-padding-top` + smooth scroll for
  the ClientRouter one-pager navigation.
- **Webfont harvesting:** capture the merchant's brand font into the ADS
  theme (forge's Google Fonts harvest logic is the starting point).

### Cluster D — Shared core (proposal-level)

- **Shared design-import package:** forge `lib/design.ts` and velocity
  `src/lib/design-import/` both do SSRF-safe fetch + deterministic design
  extraction (palette roles, typography, spacing). Extract a shared
  `@nvdigitalsolutions/design-import` core (the `nvoos-mcp-bridge`
  byte-sync precedent) with forge's band facts and velocity's
  re-extraction diff + WCAG audit as per-app extensions.
- **Theme-policy ladder package:** the mirrored canonical copy in the
  forge template vs `src/lib/theme/policy.ts` — extract to kill the
  manual keep-in-sync rule.

## Non-goals

Forge stays build-time/static (no BFF, no route cache, no MCP surface);
velocity keeps its SSR + BFF + licensing split. Patterns that don't cross
are documented as such in both ops skills.
