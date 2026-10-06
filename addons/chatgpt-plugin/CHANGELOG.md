# Changelog

All notable changes to this addon are documented here. Version bumps happen
in the monorepo (`mcp-ai-wpoos`) and are force-pushed to the mirror repo
`nvdigitalsolutions/nvoos-chatgpt-plugin` by `sync-chatgpt-plugin.yml`.
Never commit to the mirror directly.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The `version` fields in `plugin.json` and `.codex-plugin/plugin.json` MUST be
bumped in lockstep with every release.

## [0.1.0] — 2026-10-03

### Added

- Initial plugin package scaffold: portable `plugin.json` + `mcp.json`
  (Agent Plugins schema) and legacy `.codex-plugin/plugin.json` + `.mcp.json`
  compatibility overlay, both pointing at the NV oOS site MCP bridge
  (`POST /wp-json/mcp-ai/v1/mcp`).
- Three skills: `site-operations`, `content-studio`, `commerce-desk`.
- Local marketplace entry (`marketplace.json`), `bin/stamp-site.mjs`,
  `bin/package-plugin.mjs`, `bin/validate.mjs`, and dependency-free CI
  (`.github/workflows/ci.yml`).
- GPL-3.0-or-later LICENSE (copied from the monorepo root).
