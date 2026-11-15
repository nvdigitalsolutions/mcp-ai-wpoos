# QuickBooks Online MCP Connection

Connect NV oOS Pro assistants to QuickBooks Online accounting data through a
**Streamable HTTP MCP server** using the **QuickBooks Online (MCP)** connection
type in **NV oOS Pro → Remote Sites**. Connections created here appear in the
assistant **MCP Apps** metabox as one-click reference entries, with the OAuth
web login attached.

## Which QuickBooks MCP server?

| Option | Endpoint | Notes |
|---|---|---|
| **Intuit official hosted server** (default) | `https://ai-inc.quickbooks.intuit.com/v1/mcp` | Publisher-listed remote MCP connector. Authentication is **not verified by the directory** — run **Test Connection** and complete the web login to confirm. |
| **Intuit stdio reference server** | local process (`node dist/index.js`) | [intuit/quickbooks-online-mcp-server](https://github.com/intuit/quickbooks-online-mcp-server). Runs on a developer machine over stdio, so WordPress cannot dial it directly. |
| **Self-hosted Streamable HTTP wrapper** | your URL | The upstream HTTP transport (PR #74, `/mcp` route) or community builds (e.g. Cloudflare Workers) expose the same tool surface over HTTP. Save your URL on the connection. |

> WordPress sites cannot spawn a stdio subprocess. Use the hosted endpoint or
> an HTTP wrapper — the connection type always speaks Streamable HTTP JSON-RPC.

## 1. Prepare the Intuit app

1. Create an app at the [Intuit Developer Portal](https://developer.intuit.com).
2. Under **Settings → Redirect URIs**, add the NV oOS OAuth callback:
   `https://YOUR-SITE.com/wp-json/mcp-ai/v1/mcp-apps/oauth/callback`
   (intentional, byte-for-byte — protocol, host, path).
3. Copy the **Client ID** and **Client Secret** from **Keys & Credentials**.
4. Decide **Sandbox** vs **Production**. Sandbox accepts `http://localhost`
   callbacks; production requires the public HTTPS callback above. Start in
   sandbox and validate reports against the real books before switching.

Scopes: the accounting scope (`com.intuit.quickbooks.accounting`) covers
customers, invoices, payments, bills, vendors, items and reports. Request only
what the assistant needs — least privilege is the industry baseline for
accounting integrations.

## 2. Create the Remote Sites connection

**NV oOS Pro → Remote Sites → Add New Connection**

| Field | Value |
|---|---|
| **Connection Type** | `QuickBooks Online (MCP)` |
| **Site URL** | The MCP endpoint. Defaults to the official hosted endpoint; paste your self-hosted wrapper URL to override. The URL is stored **exactly** (no trailing slash appended). |
| **Authentication Type** | `OAuth 2.0 (MCP Authorization)` for the hosted endpoint / OAuth-capable wrappers; `Bearer Token` or `Custom Header` for wrappers that take a token directly; `None` only on a private network. |
| **Intuit Client ID / Secret** | Optional. Stored encrypted (AES-256-CBC). Used when the token endpoint requires a **pre-registered** client (`client_secret_basic` per RFC 6749 §2.3.1) instead of dynamic client registration. |
| **Company ID (Realm ID)** | Optional. Stored for diagnostics and for wrappers that scope by realm (`X-QB-Realm-Id`). Tokens are realm-specific. |
| **Intuit Environment** | `Sandbox` or `Production` (default). |
| **Timeout / Verify SSL** | Defaults 30 s / on. Only disable SSL verification for a self-signed staging wrapper. |

Save, then click **Test Connection**. OAuth connections without tokens yet
return a saved-credentials acknowledgement; **Discover Tools** runs after the
login completes.

## 3. Attach it to an assistant

1. Open the assistant editor → **MCP Apps** metabox.
2. Choose the connection from **Add from Remote Sites** — it is labelled
   `Name (QuickBooks MCP — <endpoint>)`.
3. Click **Connect via Web Login** and finish the Intuit sign-in.
4. Run **Test Connection** from the Remote Sites connection to confirm the
   handshake. Bridged tools are discovered and registered automatically when
   the assistant runs a chat request, and are exposed as
   `mcp_app_<label>_<tool>`.

Reference entries store **no credentials in assistant meta**. Tokens live in
the central connection's encrypted `mcp_oauth` blob and are decrypted per
request. Rotating the Intuit secret or re-authenticating updates every
referencing assistant at once.

### OAuth discovery and pre-registered clients

The web login follows the MCP Authorization discovery chain (RFC 9728
protected-resource metadata → RFC 8414 authorization-server metadata → 401
`WWW-Authenticate` probe → OIDC fallback) and uses PKCE S256.

When the connection carries an Intuit Client ID **and** Secret, NV oOS skips
dynamic client registration and authenticates token/refresh requests with
HTTP Basic. If discovery fails, the metabox shows every probe it attempted.
In that case, use the **Bearer Token** auth type with a token your server
documents (for example, one obtained through the Intuit OAuth playground or
your wrapper's own login) — that path needs no discovery.

## 4. Token lifecycle

Intuit access tokens expire after ~60 minutes; refresh tokens last up to 100
days and rotate (a new refresh token is issued, often daily). NV oOS:

- stores access **and** refresh tokens encrypted at rest;
- refreshes automatically before a call when the access token is expired;
- persists the **rotated refresh token** back to the central connection, so the
  100-day window keeps moving;
- logs `mcp_oauth_refresh` activity events with metadata only — never token
  material.

If the refresh token is allowed to lapse, re-run the web login
(**Re-authenticate**).

## 5. Read-only and write controls

Intuit's reference server groups tools by verb prefix and can suppress
categories at the server process:

| Flag | Suppresses |
|---|---|
| `QUICKBOOKS_DISABLE_WRITE=true` | `create_*` |
| `QUICKBOOKS_DISABLE_UPDATE=true` | `update_*` |
| `QUICKBOOKS_DISABLE_DELETE=true` | `delete_*` |
| *(none)* | `get_*`, `search_*`, `read_*` are always available |

Apply these on the **server** (self-hosted wrapper). On the NV oOS side,
bridged tools still pass the per-tool capability gate (`edit_posts` by
default) and are only exposed to assistants you attach the app to.

For finance workflows, prefer a dedicated Intuit user with the minimum role,
and start with write/delete categories disabled until reports reconcile.

## 6. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `HTTP 404` on Test Connection | Endpoint path wrong, or a trailing slash was added by a proxy. The connection stores the URL verbatim — re-paste it. |
| `HTTP 401` after login | Token belongs to another realm/environment, or the wrapper expects its own issuer. Confirm sandbox vs production and the realm ID. |
| OAuth discovery fails | The gateway does not publish AS/PRM metadata. Use a bearer token, or point at an OAuth-capable wrapper. The failure alert lists each probe URL and status. |
| Tools found but calls denied | The Intuit user behind the token lacks the entity/report permission. Fix the role in QuickBooks. |
| Refresh stops after ~100 days | The rotating refresh token was not persisted (e.g. read-only host). Ensure the site can write the connection option, or re-authenticate. |
| Host rejected for MCP Apps | Add the host under **Settings → Security Center → Network & Headers → MCP App Allowed Hosts** (one host per line). |
| Remote Sites rejects a private/loopback URL | `quickbooks_mcp` enforces the private-range guard. Self-hosted wrappers must be publicly reachable, or use the assistant-level inline MCP App for a same-site endpoint. |

## 7. Security checklist

- Encrypt at rest: client secret, bearer/custom-header token, and the
  `mcp_oauth` blob are all AES-256-CBC encrypted by the Remote Sites store.
- Never paste tokens into chat, issues, or commits; store durable copies in the
  Vault.
- Revoke the Intuit app's access and rotate the client secret when a connection
  is decommissioned — deleting the NV oOS connection does **not** revoke the
  remote grant.
- Keep the MCP App allowed-hosts list configured; the URL guard is permissive
  only while the list is empty.
- Prefer read-only tool sets on internet-reachable endpoints.

## References

- Intuit reference server: <https://github.com/intuit/quickbooks-online-mcp-server>
- Upstream HTTP transport PR: <https://github.com/intuit/quickbooks-online-mcp-server/pull/74>
- Intuit OAuth 2.0: <https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0>
- MCP Authorization specification: <https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization>
- RFC 9728 (protected resource metadata), RFC 8414 (authorization server
  metadata), RFC 7636 (PKCE), RFC 8707 (resource indicators), RFC 6749 §2.3.1
  (client authentication).
