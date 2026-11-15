# Changelog

All notable changes to this addon are documented here. Version bumps happen
in the monorepo (`mcp-ai-wpoos`) and are force-pushed to the mirror repo
`nvdigitalsolutions/nvoos-chatgpt-gateway-plugin` by
`sync-chatgpt-gateway-plugin.yml`. Never commit to the mirror directly.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The `version` fields in `plugin.json` and `.codex-plugin/plugin.json` MUST be
bumped in lockstep with every release.

## [0.1.0] — 2026-11-02

### Added

- Initial plugin package scaffold for the public-directory entry: portable
  `plugin.json` + `mcp.json` (Agent Plugins schema) and legacy
  `.codex-plugin/plugin.json` + `.mcp.json` compatibility overlay, both
  pointing at the fleet gateway (`https://mcp.nvoos.pro/mcp`) — the
  canonical origin this package is locked to.
- Three skills with `demo_`-namespaced tool references:
  `gateway-site-operations`, `content-studio`, `commerce-desk`.
- Full submission metadata per the live OpenAI field reference: `interface`,
  `onboardingSkill`, `review` test cases (5 positive + 3 negative),
  `commerce: false`, `publication.release_notes`.
- Submission assets (`assets/logo.png` + `assets/icon.png`, 512×512 brand
  mark), GitHub Pages scaffold (`docs/` — landing, privacy, terms),
  marketplace entry, `bin/validate.mjs` (canonical-URL gate) and
  `bin/package-plugin.mjs` (excludes `docs/`), dependency-free CI.
- GPL-3.0-or-later LICENSE (copied from the monorepo root).

### Notes

- Test-case `tools_triggered` values are drafted against the known demo
  surface and must be reconciled with the portal's Scan Tools list before
  submission (Proposal 101 §5 step E.5).
- `privacyPolicyURL`/`termsOfServiceURL` point at the mirror's GitHub Pages
  — enable Pages (source `main`, folder `/docs`) after the first sync.
