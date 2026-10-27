# Cloudways sweep log — 2026-10-27

Per-day log. Previous day: [`cloudways-sweep-log-2026-10-26.md`](cloudways-sweep-log-2026-10-26.md)
(prior classifications stand unless new evidence — see "UA-first classification"
in `SKILL.md`).

Canonical repo copy: `mcp-ai-wpoos/cloudways-sweep-log-2026-10-27.md`
Skill copy: `~/.agents/skills/nv-digital-cloudways-wpcli/reference/cloudways-sweep-log-2026-10-27.md`

Sync rule: after any session that touches a site, append to the repo copy, then
copy it over the skill's `reference/` copy and verify by hash.

---

## 2026-10-27 — MCP server: Console-app overload diagnosis

### Trigger

User reported the "NVOOS - Console" app (6473540, `xyhekevyjr`, MCP server
1536372) overloaded the server after the Complete Console (Velocity) update.
Same day: PR #7252 rebuilt the console dist (merged 06:20 UTC); the sync
workflow pushed the mirror repo at **06:37:18 UTC**; the user's manual update
made the new bundle live ~07:05–07:10.

### Verdict: the sync did NOT touch the backend — MariaDB restart + Imunify did

Access-log window 06:15–06:55 UTC (70 lines):

| Minute | Requests | Notes |
|---|---|---|
| 06:28–06:35 | 9, **48**, 2, 2, 2, 2, 4, 1 | Admin browsing (`174.138.83.104` DO egress) on the OLD bundle |
| 06:36–07:00 | **0** | nothing — the 06:37:18 sync generated zero backend traffic, no GH Actions IPs |

The "overload" was:

- **MariaDB restart at 07:01 UTC** (fresh `mariadbd` process) — every console
  API call 500'd in the same second (07:01:53–54: `cron-status`,
  `chat-transcripts`, `approvals`, `vector-store-preload`, `slash-commands`,
  `mcp_ai_profession`). Root cause #12 signature, first time confirmed on MCP.
- **Imunify reload** alongside (imunify-resident 07:02, im360.run 07:10).
- 15-min load avg 1.93 on 4 vCPU (~48%) at peak; back to 0.42 by 07:16.

### Elementor MCP loop — RESPAWNED on 1.2.12, then silent

- 4× 401 at 06:29:08–11 (UA `NV-oOS-MCP-App-Client/1.2.12`) — broken BEFORE the
  sync; the 1.2.12 Complete upgrade re-triggered root cause #9.
- 2× **500** at 07:01:54 (DB-restart window), 4× 401 at 07:02:43–44, then
  **silent through 07:16+** (23 total hits in the current log). Treat as fixed
  by the user's ~07:00 update unless it resumes; re-check next sweep.

### Scanner

- `130.12.180.167` — single `GET /.env` 06:42:28 → **403** (static log, spoofed
  Chrome/147 UA). Already blocked; single hit; WATCH only.

### New-bundle confirmation from logs (useful tell)

The old console bundle's RecordsPage requests
`/wp-json/wp/v2/<cpt>?per_page=50&context=edit&_fields=…` with **no `page=`
param**. The new paginated bundle adds `page=N` (seen `page=1..4` at 07:10+).
This log signature dates exactly when the new bundle went live.

### Box facts + recommendations delivered

- MCP server: 4 vCPU, 8 GB RAM (5.2 GB free), 14 apps, **16 php-fpm workers
  total** (Console pool `xyhekevyjr-82` ×2), `max_connections 150`.
- Recommended: `max_connections` 150 → **300** (already prescribed in the
  skill; now confirmed applicable to MCP); Buffer Pool → 1 GB if still at the
  128 MB default; OPcache 64 → 128 MB.
- SSH: publickey denied from the coding machine (confirmed again 2026-10-27);
  the user's own SSH session has **no sudo** — all diagnosis via read-only
  paste blocks under `~/applications/{sys_user}/logs/` (layout documented in
  SKILL.md). No mysql log in the app logs dir — server-level restart reasons
  need sudo or the Cloudways console.
- Do-not-block confirmed again: `174.138.83.104` (admin via DO) was the top IP
  — normal browsing, not an attack.

### Open items

- Confirm the Elementor loop stays silent (next sweep).
- Watch for a second MariaDB restart; if it recurs, pull the MySQL error log
  (Cloudways console) and apply the `max_connections` raise first.
