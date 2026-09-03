# Hermes Extensions — NV oOS Fleet

Hermes-side companion to the NV oOS WordPress plugin. These extensions run in
the **Hermes Agent web dashboard** (`hermes dashboard`), not in WordPress —
they move fleet monitoring and orchestration load off the WordPress installs
while tool execution stays where the data lives.

See the plans:

- Proposal (why, what moves off WordPress, phases):
  [`docs/developer/integration/hermes-dashboard-extensions-plan.md`](../docs/developer/integration/hermes-dashboard-extensions-plan.md)
- Implementation plan (contracts, forward specs, validation):
  [`docs/developer/integration/hermes-dashboard-extensions-implementation-plan.md`](../docs/developer/integration/hermes-dashboard-extensions-implementation-plan.md)

## Contents

| Path | What it is |
|---|---|
| `nv-oos-fleet/dashboard/` | Dashboard plugin: `manifest.json`, IIFE UI bundle, styles, FastAPI backend (`plugin_api.py`), registry template |
| `nv-oos-fleet/theme/nv-oos.yaml` | NV oOS brand theme for the dashboard theme switcher |
| `install.sh` | Copies the plugin + theme into `~/.hermes/` |

## Prerequisites

- Hermes Agent with the web dashboard (`hermes dashboard`), Python 3.11+.
- One or more NV oOS WordPress sites (v1.1.x) with the Fleet Operator addon
  (`addons/fleet-operator/`) enabled, or at least an assistant credential.

## Install

```bash
bash hermes-extensions/install.sh            # or: HERMES_HOME=/path bash ...
hermes dashboard restart                      # backend routes mount at startup
```

Then verify:

```bash
curl http://127.0.0.1:9119/api/dashboard/plugins
curl http://127.0.0.1:9119/api/plugins/nv-oos-fleet/meta
```

Open the dashboard, choose the **NV oOS** theme, and add sites under the
**NV oOS Fleet** tab. Each site needs an operator token issued on the
WordPress side (`Settings → External Operators`, or
`wp mcp-ai operator create --label "hermes-dashboard" --read-only`).

## Security

- Site credentials live only in `~/.hermes/plugins/nv-oos-fleet/dashboard/sites.yaml`
  (0600). No endpoint returns tokens — responses carry a redacted `token_hint`.
- All plugin routes sit behind the dashboard's auth gate.
- https-only URLs unless a site entry opts into `allow_insecure`.
- **Do not** expose the dashboard with `--host 0.0.0.0` while this plugin is
  installed (official Hermes guidance for plugins that hold credentials).
- Write-path features (later phases) require a per-site `write: true` flag
  plus write-scoped operator tokens.

## Development

The tree under `nv-oos-fleet/` mirrors the Hermes runtime layout, so edits are
testable by re-running `install.sh`. Static checks (no Hermes install needed):

```bash
python3 -m py_compile nv-oos-fleet/dashboard/plugin_api.py
node --check nv-oos-fleet/dashboard/dist/index.js
bash -n install.sh
```

UI changes are picked up by `curl …/api/dashboard/plugins/rescan`; backend
(`plugin_api.py`) changes require a dashboard restart.
