# nvoos-design-import — shared design-language primitives

Proposal 126 (forge × velocity cross-pollination), the shared-core
foundation. Two pure modules that both Astro apps consume:

- `contrast.ts` — WCAG 2.2 contrast math (relative luminance, ratio,
  AA/AAA ladders, the rendered-pair audit). Canonical copy vendored as
  `examples/nvoos-astro-forge/lib/contrast.ts` (shipped into every forged
  site via `FORGE_LIB_FILES`).
- `webfonts.ts` — harvested webfont filtering + Google Fonts URL building.
  Canonical copy in `examples/nvoos-astro-velocity/src/lib/webfonts.ts`.
- `color.ts` — the shared color-conversion core (D2): `expandHex`,
  `clampByte`, `rgbToHex`, `hslToHex`, `hexToRgb`. Canonical copies in
  `examples/nvoos-astro-forge/lib/color.ts` and
  `examples/nvoos-astro-velocity/src/lib/design-import/color.ts`.

## Unification notes (D2 — the honest overlap)

The two analyzers share ONLY the color-conversion core. Everything else is
**deliberately divergent** and stays per-app:

| Helper | Forge | Velocity | Unified? |
|---|---|---|---|
| `lightnessOf` | WCAG relative luminance (band-darkness classification) | HSL lightness (palette role heuristics) | No — different tuned semantics |
| `hueOf` / `saturationOf` | HSV-form saturation | HSL-form saturation | No — different formulas |
| `normalizeColor` / palette roles | Forge's rebuild pipeline | Velocity's `analyzeDesignLanguage` | No — different pipelines |

Forcing either implementation onto the other would silently change tuned
classification thresholds — the shared core is the provably-identical set
only.

## Byte-sync discipline (the nvoos-mcp-bridge precedent)

The copies inside the apps are byte-identical to these canonical sources,
pinned by drift tests in each consumer:

- forge: `examples/nvoos-astro-forge/tests/shared-libs.test.ts`
- velocity: `examples/nvoos-astro-velocity/tests/unit/webfonts-drift.test.ts`

Change the canonical file here, copy it into the consumers (never edit the
consumer copy first), and both drift gates re-pin the identity.

## Status

`private` for now — the npm publish track (the
`npm-publish-nvoos-mcp-bridge.yml` pattern) is the follow-up once a
consumer needs registry resolution; in-repo TS imports work today.
