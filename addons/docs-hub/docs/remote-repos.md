# Remote Repositories

NV oOS Docs Hub indexes Markdown documentation from public (or token-authenticated) GitHub repositories. As of v0.3.0, **remote-first is the default** — fresh installs ship with `sources = ['remote']` and the local filesystem sources are off.

## Adding a repository

1. In WordPress admin, open **Settings → NV oOS Docs Hub**.
2. Scroll to **Remote Repositories** and click **+ Add Repository**.
3. Fill in:
   - **Owner** *(required)* — GitHub user or organisation, e.g. `nvdigitalsolutions`.
   - **Repository** *(required)* — repo name, e.g. `mcp-ai-wpoos`.
   - **Branch / Tag** — branch name, tag, or commit SHA. Default `HEAD` (latest commit on the default branch).
   - **Label** — human-readable name shown in the docs sidebar.
   - **Path prefix** — optional repo-relative subdirectory (e.g. `docs`).
   - **Access Token** — optional GitHub Personal Access Token. Required for private repos; raises the rate limit from 60 → 5 000 req/hr for public repos.
4. Choose a **File selection** mode (see below).
5. Save settings, then click **Rebuild Documentation Index**.

## File selection modes

Each repository row has a **File selection** radio group:

| Mode | What it indexes |
|------|-----------------|
| **All Markdown / .txt files** | Every `.md` / `.txt` blob in the repo (or below the path prefix), minus the default exclusions (`vendor/`, `node_modules/`, `.git/`, build outputs, `LICENSE.md`, `CODE_OF_CONDUCT.md`, etc.) and minus your **Excluded paths**. |
| **Path prefix only** | Only files inside the **Path prefix** field above. |
| **Selected files / folders only** | Only paths listed in **Selected paths**. Use the **Browse files** picker to populate this. |

Path lists (Selected / Excluded) accept one repo-relative path per line:

- `docs/intro.md` — exact file match.
- `guides/` — directory; everything beneath is included (or excluded).
- `README.md` — top-level file.

Paths must use forward slashes, must not start with `/`, and must not contain `..`.

## Browsing files (the tree picker)

Click **Browse files in repo…** on a repo row. The plugin calls the admin-only REST endpoint:

```
GET /wp-json/nvoos-docs/v1/remote/tree?owner=…&repo=…&ref=…&path=…
```

…and renders the resulting list of `.md` / `.txt` files as checkboxes. Toggling a checkbox keeps **Selected paths** in sync.

The tree is cached server-side for **10 minutes** per `owner/repo/ref/path`. Click **Refresh** to bypass the cache and re-fetch from GitHub.

## Caching

- **Tree listing:** 10-minute transient (admin picker only).
- **File contents:** 24-hour filesystem cache under `wp-content/uploads/nvoos-docs-hub/remote/`. Invalidated by **Rebuild Documentation Index** with the `force` flag, or by the per-repo `force` config in `fetch_entries()`.
- **Manifest / search index:** rebuilt by the rebuild job; see the main README.

## Security

The remote fetcher reuses the same SSRF-safe HTTPS GET pipeline as the rest of NV oOS:

- HTTPS only.
- Domain allowlist: `api.github.com`, `raw.githubusercontent.com`.
- Hostnames resolved server-side; private / loopback / reserved IPs rejected.
- DNS-rebind defence via pinned `CURLOPT_RESOLVE`.
- Redirects disabled.
- 4 MB response size cap.
- Owner / repo strings are validated against `^[A-Za-z0-9_.\-]+$`.
- Selected / Excluded paths validated against `^[A-Za-z0-9_./\-]+/?$` with no `..` segments and no leading slash.

Tokens are stored in WordPress options. They are never echoed back to the browser; the password field shows `(saved — enter new value to change)` when a token is on file. The `/remote/tree` endpoint resolves the saved token server-side via the optional `index` parameter so the browser never has to round-trip the secret.

## Migration from earlier versions

- **Existing installs** keep their saved `sources` array. Nothing is auto-migrated.
- **Fresh installs** (no saved option) default to `sources = ['remote']`.
- A one-time, dismissible admin notice appears for installs that have all three legacy local sources enabled (`base` + `addons` + `root`) and zero remote repos configured, pointing them to the new picker.

## Out of scope

- GitLab / Bitbucket / arbitrary Git hosts (the SSRF allowlist is GitHub-only by design).
- Background pre-fetch of the tree on save (current model fetches lazily during rebuild).
