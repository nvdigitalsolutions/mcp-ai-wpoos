# Changelog — NV oOS MCP Gateway

## 0.1.1 — 2026-10-06

- `initialize` negotiates the protocol version with the client
  (`2026-07-28` → `2024-11-05`; highest mutually supported, defaulting to
  `2024-11-05`) instead of hardcoding `2026-07-28` — strict clients (Zed,
  Claude Desktop) no longer abort with "Unsupported protocol version".
- Mirror sync no longer force-pushes: each sync snapshots the subtree as
  one commit on a linear mirror `main`, so hosting auto-deploys (Cloudways
  Velocity) fire on every sync instead of staying on stale clones.

## 0.1.0 — 2026-10-05

- Initial public gateway: bearer API-key auth, per-key rate limits,
  `<site-slug>.<tool>` tool namespacing, health endpoints.
