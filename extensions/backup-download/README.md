# Backup Download — Hermes WebUI extension

Creates a downloadable Hermes backup from a floating **💾 Backup** button in the
WebUI. Wraps the native `hermes backup` command through a loopback sidecar.

## What it does

- **Create full backup** — config, skills, sessions, data (the native full zip)
- **Quick backup** — critical state files only. `hermes backup -q` creates a
  state snapshot (in `~/.hermes/state-snapshots/`, restorable via
  `/snapshot restore <name>`) instead of a zip; the sidecar zips that snapshot
  so it can be downloaded the same way
- **List** recent backups with size and date
- **Download** a backup in the browser

## Architecture (why the sidecar)

The WebUI extension proxy (`/api/extensions/<id>/sidecar/<path>`) caps proxied
responses at **512 KB** and times out upstream requests at 10s. A backup zip is
far larger than that, so:

- Control plane (health / start / status / list) → small JSON through the proxy
- Backup creation runs **asynchronously** in the sidecar (10s proxy timeout
  would kill a synchronous run)
- The zip is fetched in **448 KB chunks** through the authenticated proxy and
  assembled into one browser download (`Blob`)

The sidecar binds `127.0.0.1` only. It generates an in-memory token exposed via
`/health` (reached through the authenticated proxy); all other endpoints
require it (`X-Backup-Download-Token` header, or token in the path for chunk
fetches). No CORS headers are ever sent, so cross-origin pages cannot read
sidecar responses.

## Files

| File | Purpose |
|------|---------|
| `sidecar.py` | Loopback HTTP control plane (stdlib only) |
| `run-sidecar.sh` | start / stop / status launcher |
| `manifest.json` | Loader-facing extension entry |
| `extension.json` | Published extension spec |
| `assets/backup-download.js` | WebUI widget |
| `assets/backup-download.css` | Widget styles |

## Start / stop the sidecar

```sh
~/.hermes/webui/extensions/backup-download/run-sidecar.sh start
~/.hermes/webui/extensions/backup-download/run-sidecar.sh status
~/.hermes/webui/extensions/backup-download/run-sidecar.sh stop
```

Environment overrides: `HERMES_BACKUP_SIDECAR_PORT` (default 19001),
`HERMES_BACKUP_DIR` (default `~/.hermes/backups`).

To survive reboots, add a systemd user unit:

```ini
# ~/.config/systemd/user/hermes-backup-sidecar.service
[Unit]
Description=Hermes WebUI backup-download sidecar

[Service]
ExecStart=/usr/bin/python3 %h/.hermes/webui/extensions/backup-download/sidecar.py
Restart=on-failure

[Install]
WantedBy=default.target
```

```sh
systemctl --user daemon-reload
systemctl --user enable --now hermes-backup-sidecar
```

## First use

1. Start the sidecar (above).
2. Open the WebUI, click **💾 Backup**, then **Enable sidecar access** when
   prompted (one-time approval; revocable in Settings → Extensions).

## Security notes

- The backup zips contain Hermes state **including secrets** (config bearer
  tokens, auth). Downloads only happen through the authenticated WebUI proxy;
  treat downloaded archives accordingly.
- The sidecar never accepts arbitrary paths — filenames must match
  `hermes-backup-{full|quick}-<UTC timestamp>.zip` and resolve inside the
  backups directory.
- If the WebUI is reachable from other machines, the sidecar still binds to
  `127.0.0.1` on the server, so only the WebUI can reach it.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| "Sidecar not reachable" | `run-sidecar.sh start` on the server, then reopen the panel |
| "Approval required" | Click **Enable sidecar access** (or Settings → Extensions) |
| "Too large for browser download" | Fetch the file directly on the server from `~/.hermes/backups/` |
| Backup errors | See `sidecar.log` next to this README |
