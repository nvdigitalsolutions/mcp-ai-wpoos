# Upstream

This folder is a **tracked copy** (not a submodule, not a subtree) of an
upstream open-source project, adapted for NV use per Proposal 113
(`docs/project/proposals/113-quickbooks-desktop-mcp-proposal.md`).

| Field | Value |
|---|---|
| Upstream | <https://github.com/Compolaga/quickbooks-desktop-mcp> |
| Lineage | Fork of `lilfroog/qbbridge` (MIT) |
| License | MIT — see `LICENSE` (independent program; not derived from this repo's GPL code) |
| Fork point | `fee09147af5471ebe33c871c0abd066a265df188` (2026-10-25, upstream `main`) |
| Forked into | `examples/qb-desktop-mcp/` on 2026-11-05 |
| Upstream tags | none — sync is by commit SHA |

## Sync policy

- Upstream fixes are pulled **manually**: `git fetch https://github.com/Compolaga/quickbooks-desktop-mcp.git main`, diff against the fork point, and cherry-pick/port into this folder. No upstream history lives here.
- Any local change that is not an NV adaptation should be offered back upstream.
- NV adaptations are marked with a `# Proposal 113` comment (see
  `src/qbbridge_mcp/server.py`, `pyproject.toml`) and listed here:

| Adaptation | Why |
|---|---|
| `QBBRIDGE_WRITE_MODE` (readonly / safe / full, default **safe**) in `server.py` | Proposal 113 write tier: only additive list adds (customer/item/vendor) + revert by default; transactional writes need operator sign-off (`full`) |
| Server identity `nvoos-qb-desktop-mcp` (`FastMCP` name, was `qbbridge`) | NV branding in client UIs; `QBBRIDGE_*` env vars and the `qbbridge-mcp` console script keep upstream names |
| `[tool.uv] dev-dependencies` in `pyproject.toml` | This repo pins uv 0.12, which reads the legacy block instead of PEP 735 `dependency-groups`; `uv sync` then installs the test deps for CI |
| `tests/test_offline.py` gate tests + autouse full-mode fixture | Upstream tests exercise every write tool; they run under `WRITE_MODE=full`, the gate tests pin the shipped default |
| `docs/zed-runbook.md`, `README.nv.md`, `UPSTREAM.md`, `.gitignore` additions | NV operational layer (Zed wiring, first-connect authorization, no-live-data rule) — none of it touches QuickBooks semantics |

## Rules for this folder

1. **No live client data** — no company names, amounts, ref numbers, or
   customer/vendor names in any file here, in issues, or in commits.
   Counts and patterns only. Fixtures are synthetic.
2. `xsd/` (Intuit schemas) must never be committed — Intuit licence.
3. Company-file copies (`*.qbw`, `*.qbb`, `*.qbm`) must never be committed.
4. Python/uv project — the repo's PHP/WP conventions do not apply here.
