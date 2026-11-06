# nvoos-design-import — shared design-language primitives

Proposal 126 (forge × velocity cross-pollination), the shared-core
foundation. Two pure modules that both Astro apps consume:

- `contrast.ts` — WCAG 2.2 contrast math (relative luminance, ratio,
  AA/AAA ladders, the rendered-pair audit). Canonical copy vendored as
  `examples/nvoos-astro-forge/lib/contrast.ts` (shipped into every forged
  site via `FORGE_LIB_FILES`).
- `webfonts.ts` — harvested webfont filtering + Google Fonts URL building.
  Canonical copy in `examples/nvoos-astro-velocity/src/lib/webfonts.ts`.

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
