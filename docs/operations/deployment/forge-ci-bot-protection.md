# Forge CI — bot-protection 403 runbook (jayainc case study)

> Symptom: `forge-sites.yml` builds fail at the content layer with
> `WordPress rejected the request (403)`. The same requests succeed from a
> residential IP with any UA. The forge's browser-UA hardening (#7474) and
> the `WP_FETCH_PROXY` seam (#7475) are shipped — the UA is NOT the blocker
> here; the source site's bot protection blocks **GitHub's datacenter IP
> ranges** (Cloudways Anti-Bot Protection / Imunify360 WebShield).

## Why not allowlist GitHub's ranges

`https://api.github.com/meta` → `actions` = ~8,350 CIDR ranges today, and
they churn. Pasting them into a WAF allowlist is impractical and
over-permissive (everyone else's Actions traffic gets through too).

## The three workable options (pick one)

### 1. Self-hosted runner with a fixed IP (recommended — one IP, reusable for every client)

Stand up a GitHub Actions self-hosted runner on NV infra with a **static
IP** (any small VPS, or even this dev machine — its residential IP is
already allowed by jayainc, proven by the local builds):

1. Mint a token: `gh api -X POST repos/nvdigitalsolutions/mcp-ai-wpoos/actions/runners/registration-token --jq .token`
2. Install `actions-runner` (GitHub's official installer), label it e.g. `forge-builder`, run as a service.
3. `forge-sites.yml`: `runs-on: self-hosted` (+ the label).
4. Allowlist THAT single IP in the client's bot protection (or nothing at
   all when the runner's egress is already trusted).

### 2. Allowlisted proxy + `WP_FETCH_PROXY` (the shipped seam)

Any allowlisted fixed-IP endpoint (small VPS proxy, or an NV Cloudways
server — note: Cloudways-to-Cloudways may already bypass the block) set as
`WP_FETCH_PROXY=http://user:pass@host:port` in the forge-sites workflow
env. The seam routes the content layer AND the image service through it.

### 3. Cloudways-side adjustment (jayainc is on the NV account)

In the jayainc app's security settings: disable/tune the **Anti-Bot
Protection** (or Imunify360 WebShield) for `/wp-json/*` — or whitelist the
single runner/proxy IP from option 1/2. Drive it via the Cloudways MCP
tools (`cloudways-nvdigital`) from an assistant that has them attached.

## Verification after any fix

Re-run `forge-sites.yml` (workflow_dispatch, `site=jayainc`) — the build
should reach the mirror-push step (which skips gracefully until the
`NVOOS_FORGE_MIRROR_*` secrets exist). The WCAG lines in the log are
informational warnings, not failures.
