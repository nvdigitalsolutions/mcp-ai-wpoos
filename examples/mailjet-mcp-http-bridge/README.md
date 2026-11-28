# Mailjet MCP — Streamable HTTP bridge

A thin Streamable HTTP front end for
[`@mailjet/mailjet-mcp-server`](https://github.com/mailgun/mailjet-mcp-server),
which speaks **stdio only**.

NV oOS Remote Sites speaks HTTP MCP, so this bridge is what makes a
`mailjet_mcp` connection able to expose an MCP tool surface at all. It is
optional: a `mailjet_mcp` connection with no bridge URL works as a credential
source for the native Mailjet tools.

## Read-only, by design

Upstream registers tools **only for GET operations** in its bundled OpenAPI
spec:

```js
const supportedMethods = ["GET"]
```

There is no send, no contact edit, no settings change — the bridged surface can
read analytics, contacts, lists, campaigns and messages, and nothing else.
Sending stays on the native `send_mailjet_email` tool, which talks to
`POST /v3.1/send` directly.

Do not "fix" this by enabling more verbs. The upstream comment is explicit that
write methods are excluded for security and data-privacy reasons.

## Why a per-request server instance

`registerTool()` closes over the `userContext` passed to
`generateToolsFromOpenApi()`, and `makeMailjetRequest()` reads the key pair from
that closure. A shared `McpServer` would serve whichever credentials were
registered first to every subsequent caller — a cross-tenant credential leak.

So every request constructs its own `McpServer`. That is object construction
only (the OpenAPI spec is parsed once at module load), so it is cheap.

## Run

```sh
cp .env.example .env
# set BRIDGE_TOKEN
npm install
npm start
```

The bridge serves with no token by default, but logs a warning at boot: any
valid Mailjet key pair may then use it as a read-only proxy. Set `BRIDGE_TOKEN`
for any shared or production deployment.

## Contract

| Path | Method | Notes |
|---|---|---|
| `/health` | GET | `{ ok, token_configured, region, tools, read_only }`. No auth, no secrets. |
| `/mcp` | POST / GET / DELETE | Streamable HTTP MCP (stateless, JSON responses). |

`/mcp` accepts Mailjet credentials in either of two shapes:

| Shape | Headers | When to use |
|---|---|---|
| **Basic** (default, works with NV oOS `basic_auth`) | `Authorization: Basic base64(key:secret)` | Nothing else needed |
| Split | `Authorization: Bearer <BRIDGE_TOKEN>` + `X-Mailjet-Key` + `X-Mailjet-Secret` | Proxies that reserve `Authorization` for tenancy |

Optional, both shapes:

| Header | Purpose |
|---|---|
| `X-Bridge-Token` | Tenancy gate when `BRIDGE_TOKEN` is set and the caller uses Basic auth |
| `X-Mailjet-Region` | `us` or `eu`; rejected when it contradicts the process region |

## Region is process-level

Upstream resolves its hostname once at import:

```js
const API_HOSTNAME = `api.${API_REGION ? `${API_REGION}.` : ""}mailjet.com`;
```

`MAILJET_API_REGION` is therefore **not** a per-call option. One deployment
serves one region. A key minted in the EU region will not authenticate against
the US host, so run a second instance with `MAILJET_API_REGION=eu` if you need
both, and save its URL on a separate connection.

To make the failure legible, `/mcp` validates `X-Mailjet-Region` against the
process region and answers `400 wrong_region` instead of letting the client
report a confusing `401`.

## Wiring into NV oOS

Remote Sites → **Add Connection** → **Mailjet (Email API / MCP)**. Set the
**MCP Bridge URL** to `https://<bridge-host>/mcp` and paste the key pair. The
connection's `basic_auth` mapping sends `Authorization: Basic base64(key:secret)`,
which this bridge decodes directly — no header-mapping proxy is required.

If your deployment sets `BRIDGE_TOKEN`, put it in the connection's **Header
Name** / token fields using `custom_header` auth instead, or front the bridge
with a proxy that injects `X-Bridge-Token`.

## Deploying

Cloudways Velocity, Fly.io, or any container host. Requirements: TLS, Node 20+,
egress to `api.mailjet.com` (and `api.eu.mailjet.com` if you run an EU
instance). Health-check `/health`.

Run one instance per region. A US deployment cannot serve EU keys — the bridge
answers `400 wrong_region` rather than letting the caller see a bare 401.
