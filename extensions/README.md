# Extensions — Hermes-side companions to NV oOS

One home for all Hermes-side extension code. **Nothing in this tree ships with
the WordPress plugin** — `extensions` is excluded from the wp.org build via
`.distignore`.

## Index

| Extension | Runtime target | What it does | Status |
|---|---|---|---|
| [`nv-oos-fleet/`](nv-oos-fleet/) | `hermes dashboard` plugin system (`~/.hermes/plugins/`) | Fleet console: register NV oOS WordPress sites, monitor (health, logs, jobs w/ live SSE, analytics, security), gated control actions, MCP config apply | complete; functional test suite |
| [`backup-download/`](backup-download/) | Hermes WebUI manifest-bundle (`HERMES_WEBUI_EXTENSION_DIR`) | Floating 💾 Backup button wrapping `hermes backup` via a loopback sidecar | **assets pending** — manifest references `assets/backup-download.js` / `.css` not yet present |
| [`external-app-tab/`](external-app-tab/) | Hermes WebUI manifest-bundle | Pin a self-hosted web app (Grafana, Vaultwarden, …) as an iframe tab in the WebUI | complete |
| [`mcp-tool-shortcuts/`](mcp-tool-shortcuts/) | Hermes WebUI manifest-bundle | Pin MCP tools; one click drafts a review-before-send prompt into the composer | **assets pending** — manifest references `assets/mcp-tool-shortcuts.js` / `.css` not yet present |

Two different extension systems live side by side:

- **`nv-oos-fleet`** targets the **Hermes web dashboard plugin system**
  (`~/.hermes/plugins/<name>/dashboard/manifest.json` + IIFE bundle +
  FastAPI `plugin_api.py`; see
  [Hermes docs](https://hermes-agent.nousresearch.com/docs/user-guide/features/extending-the-dashboard)).
- **`backup-download`, `external-app-tab`, `mcp-tool-shortcuts`** target the
  **Hermes WebUI extension system** (`extension.json` + `manifest.json`
  manifest-bundle format, installed via `HERMES_WEBUI_EXTENSION_DIR` /
  served under `/extensions/assets/`). See each folder's README for its
  install command, permissions model, and verification steps.

## nv-oos-fleet — install

```bash
bash extensions/install.sh                  # copies plugin + theme into ~/.hermes
hermes dashboard restart                     # backend routes mount at startup

curl http://127.0.0.1:9119/api/dashboard/plugins
curl http://127.0.0.1:9119/api/plugins/nv-oos-fleet/meta
```

Then pick the **NV oOS** theme and add sites under the **NV oOS Fleet** tab.
Sites need an operator token issued on the WordPress side
(`Settings → External Operators`, or
`wp mcp-ai operator create --label "hermes-dashboard" --read-only`).

See [`nv-oos-fleet/` docs and plans](../docs/developer/integration/hermes-dashboard-extensions-plan.md)
for the full feature set and the load-offload rationale.

## Security (all extensions)

- `nv-oos-fleet` stores site credentials only in
  `~/.hermes/plugins/nv-oos-fleet/dashboard/sites.yaml` (0600); no endpoint
  ever returns tokens. Write paths require the site entry's `write: true`
  flag **and** the site-side operator allowlist.
- The WebUI extensions are trusted local code: each folder's README declares
  its permissions (storage keys, sidecar, iframe/CSP needs) and its own
  safety contract (e.g. mcp-tool-shortcuts never calls `/api/mcp/call`).
- Never expose the dashboard/WebUI with `--host 0.0.0.0` while extensions
  that hold credentials are installed.
- `mcp-config/apply` backs up `~/.hermes/config.yaml` before editing and
  writes `.env` entries with 0600.

## Development

Static checks:

```bash
python3 -m py_compile extensions/nv-oos-fleet/dashboard/plugin_api.py
node --check extensions/nv-oos-fleet/dashboard/dist/index.js
node --check extensions/external-app-tab/assets/external-app-tab.js
bash -n extensions/install.sh
python3 -m json.tool extensions/*/extension.json
python3 -m json.tool extensions/*/manifest.json
```

Functional backend test (mocked upstream WP site; throwaway venv):

```bash
cd extensions
python3 -m venv .venv && .venv/bin/python -m pip install fastapi httpx pyyaml
.venv/bin/python tests/backend_smoke.py
```

UI changes are picked up by `curl …/api/dashboard/plugins/rescan`; backend
(`plugin_api.py`) changes require a dashboard restart.
