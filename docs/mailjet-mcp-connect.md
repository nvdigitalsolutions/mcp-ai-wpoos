# Connecting Mailjet (API + MCP)

Mailjet appears in Remote Sites as a single connection type, `mailjet_mcp`, with
two shapes selected by whether an **MCP Bridge URL** is set.

| Shape | Bridge URL | What you get |
|---|---|---|
| **Credential source** | blank | Per-account credentials for `send_mailjet_email`, `get_mailjet_statistics`, `manage_mailjet_contacts` |
| **MCP** | set | The above, plus a read-only MCP tool surface referenceable from assistant MCP Apps |

## Why there is no default endpoint

The official server —
[`mailgun/mailjet-mcp-server`](https://github.com/mailgun/mailjet-mcp-server) —
is **stdio only**. It constructs a `StdioServerTransport` and nothing else, so
neither WordPress nor the Laravel runtime can call it directly. Unlike
QuickBooks, which publishes a hosted endpoint, Mailjet has no HTTP MCP endpoint
to point at.

Two further facts shape this integration:

- **It is read-only.** Upstream registers tools only for the GET operations in
  its bundled OpenAPI spec (`const supportedMethods = ["GET"]`). It can read
  analytics, contacts, lists, campaigns and messages; it cannot send or change
  anything. Sending stays on `send_mailjet_email`.
- **It is built to be embedded.** It exports `parsedOpenApiSpec`,
  `generateToolsFromOpenApi(spec, server, userContext)` and
  `makeMailjetRequest(...)`, so a bridge is a supported path rather than a fork.

## Shape 1 — credential source (no bridge)

This is the shape to use first; it needs no extra infrastructure.

1. **NV oOS Pro → Remote Sites → Add Connection**
2. Connection Type: **Mailjet (Email API / MCP)**
3. Fill in:

| Field | Notes |
|---|---|
| Mailjet API Key | Mailjet → Account → API Key Management |
| Mailjet API Secret | Encrypted at rest; never sent back to the browser |
| API Region | `US (api.mailjet.com)` or `EU (api.eu.mailjet.com)` |
| Default From Address | Optional; overrides the site-wide sender for this account |
| Default From Name | Optional |
| MCP Bridge URL | **leave blank** |

4. **Test** runs `GET /v3/REST/user` against the selected region. A 401 is
   reported as *rejected in the &lt;region&gt; region* — the distinction matters,
   because a key minted in the EU region will not authenticate against the US
   host.

### Assigning it to an assistant

Two independent surfaces, and a connection can use both:

- **Remote Site Connections** (assistant editor, sidebar) — the allowlist. A
  connection that is not checked here cannot be used by that assistant.
- **Tools** — enable `send_mailjet_email`, `get_mailjet_statistics`,
  `manage_mailjet_contacts`, `list_mailjet_connections`.

Leaving the allowlist untouched means *unrestricted* (every connection), which
is the pre-existing behaviour. Checking even one box restricts the assistant to
exactly those, plus the synthetic **NV oOS site-wide settings** row if you tick
it.

### Which account does a call use?

The tools resolve in this order:

1. An explicit `connection_id` argument.
2. The single enabled `mailjet_mcp` connection assigned to the assistant.
3. The site-wide `mailjet_api_key` / `mailjet_api_secret` settings — only when
   the assistant is unrestricted, or has the **site-wide settings** row ticked.

With **two** connections assigned and no `connection_id`, the tool returns an
error naming both and telling the model to call `list_mailjet_connections`. It
does not guess. That is deliberate: a Mailjet API key is effectively a
[subaccount](https://documentation.mailjet.com/hc/en-us/articles/360042561854-Understanding-subaccounts-and-their-benefits),
sender addresses are validated **per key**, and statistics are accounted **per
key** — so the wrong pick reports the wrong numbers or fails the send.

## Shape 2 — MCP bridge

Only do this if you want the read-only MCP tool surface (analytics/contacts
reads through MCP Apps rather than through the native tools).

Deploy the bridge in
[`examples/mailjet-mcp-http-bridge`](../examples/mailjet-mcp-http-bridge/README.md),
then save its `/mcp` URL in **MCP Bridge URL**.

Prerequisites and caveats:

- **One bridge per region.** Upstream resolves its hostname once, at module
  load, from `MAILJET_API_REGION`. A US bridge cannot serve EU keys.
- **Set `BRIDGE_TOKEN`** in any shared deployment; without it, anyone holding a
  valid Mailjet key pair can use the bridge as a read-only proxy.
- The bridge accepts `Authorization: Basic base64(key:secret)` directly, which
  is exactly what the connection's `basic_auth` mapping sends — no header
  mapping proxy required.

### Assigning the bridge to an assistant

Once a bridge URL is saved, the connection appears in the assistant editor's
**MCP Apps** metabox under **Add from Remote Sites**. That creates a
`connection_ref` entry (no credentials on the assistant); the token and key
pair stay in the central store and rotate in one place.

A connection **without** a bridge URL is deliberately hidden from that
dropdown — it has no endpoint to handshake with, so offering it would put a row
on screen that can only fail.

Bridged tools appear as `mcp_app_mailjet_<tool>` and are appended to the chat
payload at call time. Limits: 10 apps per assistant, 5-minute discovery cache,
60-second negative cache, 2 MB response cap.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "Mailjet rejected these credentials (HTTP 401) in the EU region" | Key minted in the other region, or secret copied wrong | Check the region on the connection; re-copy both halves |
| "A Mailjet API key and secret are required" on save | One half is blank | Both are required — the API uses HTTP Basic over the pair |
| "Several Mailjet connections are available…" | Two candidates assigned | Pass `connection_id`, or call `list_mailjet_connections` |
| "…is not assigned to this assistant" | Connection exists but is unchecked in the assistant's Remote Site Connections | Tick it |
| "…is not allowed to use the site-wide Mailjet settings" | Assistant is restricted and the settings row is unchecked | Tick the site-wide settings row, or assign a connection |
| Mailjet connection missing from **Add from Remote Sites** | Credential-only shape | Expected — save a bridge URL first |
| Bridge returns `400 wrong_region` | Header/region mismatch | Deploy a bridge for that region |
| Test says the MCP server rejected the check | Bridge up but Mailjet keys wrong, or the bridge is not reachable | `curl https://<bridge>/health` first |

## Security notes

- Both halves of the key pair are credentials and are sealed (AES-256-CBC), the
  same scheme as every other remote-site secret. Neither is ever returned by a
  list or edit read; both fields are blank on load and blank-submit keeps the
  stored value.
- Assistant export writes `connection_ref` as a non-secret pointer. Credentials
  never ride in an assistant bundle.
- Deleting the connection does **not** revoke the Mailjet key. Revoke it in
  Mailjet → API Key Management.
- The bridged surface is read-only by construction. Do not enable write verbs
  in the bridge; upstream excludes them deliberately.

## The Laravel runtime

The Laravel runtime serves the same connection type through
`/api/v1/pro/remote-sites`, so the console wizard and the `/admin/{estate}/remote-sites`
panel both offer **Mailjet** with the same field set.

Two differences worth knowing:

- **The permission model is the estate allowlist, not the connection ID.**
  `remote_connections` is a per-assistant `advanced` key (a list of connection
  IDs) rendered in the assistant editor's **Advanced → Action & access** tab.
  Empty means unrestricted; add `settings` to grant the site-wide credential
  fallback. On WordPress the same list is the **Remote Site Connections**
  metabox.
- **`RemoteSiteVerifier` probes `GET /v3/REST/user` directly**, using the
  region to pick the host. A credential-only connection therefore tests
  correctly even though it has no MCP endpoint — the "no URL to test" guard is
  evaluated per type, not before it.

The native Mailjet tools live only in the WordPress runtime; the Laravel side
serves the connection, its credentials and the read-only MCP surface.
