# Hermes Operator Setup — Runbook

How to wire Nous Research's [Hermes Agent](https://hermes-agent.nousresearch.com)
as an external operator of NV oOS sites, using the Fleet Operator addon.

> Plan: [`docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md`](../../project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md)

## Prerequisites

- NV oOS **v1.1.52+** on the target site (v1.1.51 and earlier reject
  `ping`/`notifications/initialized` with HTTP 400).
- Fleet Operator addon activated on the target site.
- Hermes installed (desktop app or gateway) on the machine that will drive
  tool calls. Config lives at `~/.hermes/config.yaml` + `~/.hermes/.env`.

## 1. Create the operator credential (per site)

WP admin: **Settings → External Operators → Create operator credential**.

- **Label:** `Hermes` (or per-site, e.g. `Hermes-store-b`).
- **Act as user:** the human whose capabilities Hermes may use.
- **Mode:** `Read + write` (approval gates still apply) or `Read only`.
- **Allowed tools:** start small. Example:

  ```
  get_site_summary
  get_recent_posts
  search_content
  create_post
  group:content_publishing
  woo_products
  ```

- Copy the token **immediately** — it is shown once. If lost, revoke and
  recreate.

Or via WP-CLI:

```bash
wp mcp-ai operator create Hermes --user=1 --tools=get_site_summary,get_recent_posts,create_post --mode=readwrite
```

## 2. Add the generated config to Hermes

The admin page prints both fragments on creation. For CLI: `wp mcp-ai operator config <id> --token=<op_xxxx.SECRET>`.

`~/.hermes/.env`:

```
NVOOS_CONSOLE_TOKEN=op_xxxxxxxx.SECRET
```

`~/.hermes/config.yaml`:

```yaml
mcp_servers:
  console:
    url: "https://example.com/wp-json/mcp-ai/v1/mcp"
    headers:
      Authorization: "Bearer ${env:NVOOS_CONSOLE_TOKEN}"
    tools:
      include:
        - get_site_summary
        - get_recent_posts
        - create_post
    trust: untrusted  # approve every write-capable tool call
```

Then `hermes /reload-mcp` (or restart the desktop app).

## 3. Verify

Ask Hermes: "list the tools on the console site." It must show **only** the
allowlisted tools. Then run one read (`get_site_summary`) and one write
(`create_post` as draft). Check **Settings → External Operators → Last used**
and the base plugin's activity log for `operator_id` attribution.

## 4. Verify rejection paths (do this once per site)

- Call a tool outside the allowlist by name → must fail with
  "outside this operator credential's allowlist".
- Use a token from site A against site B → must fail with audience mismatch.
- Revoke the credential → next call fails immediately (kill switch).

## 5. Operate

- You talk to Hermes (WhatsApp/Signal/Telegram/CLI/desktop); Hermes calls the
  sites. Keep `trust: untrusted` until you trust it on a given site.
- Approvals appear in Hermes itself and in the site's **Agent Command Center
  → Approvals** for destructive-ops-gated tools.
- Review `docs/operations/fleet/` and the skills pack
  (`hermes skills tap add nvdigitalsolutions/nvoos-hermes-skills`).

## Troubleshooting

| Symptom | Fix |
|---|---|
| `initialize` rejected / version error | Update NV oOS to v1.1.52+ (protocol negotiation fallback landed in v1.1.51). |
| HTTP 400 on `ping` / `notifications/initialized` | Same — method enum fix shipped in v1.1.52. |
| 401 `operator_audience_mismatch` | Token minted on another URL (www vs non-www, http vs https). Recreate the credential on the canonical URL, or use the `wp_mcp_ai_operator_audience_url` filter for proxies. |
| 429 rate limited | Raise the per-operator rate limit or slow Hermes' tool churn. |
| Tool missing from `tools/list` | It is not in the allowlist — extend it in WP admin and re-run `/reload-mcp`. |

## Security notes

- Hermes stores secrets as plaintext files: keep `chmod 600 ~/.hermes/.env`.
- Run the Hermes gateway under systemd/Docker (unprivileged), never `nohup &`.
- Revoking a credential is your global kill switch — it takes effect on the
  next request.
