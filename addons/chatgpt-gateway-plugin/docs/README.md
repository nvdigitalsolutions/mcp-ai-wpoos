# GitHub Pages (publisher listing)

Static pages served by GitHub Pages on the mirror repo
`nvdigitalsolutions/nvoos-chatgpt-gateway-plugin` at
`https://nvdigitalsolutions.github.io/nvoos-chatgpt-gateway-plugin/`:

| File | URL |
|---|---|
| `index.html` | `/` — plugin listing landing page |
| `privacy.html` | `/privacy` — Privacy Policy (required listing URL) |
| `terms.html` | `/terms` — Terms of Service (required listing URL) |

`plugin.json`'s `privacyPolicyURL` / `termsOfServiceURL` point at these URLs.

## How this works

- The subtree sync (`sync-chatgpt-gateway-plugin.yml`) ships this folder to
  the mirror repo root, so `docs/` here becomes `docs/` on the mirror.
- **Manual step (one-time, GitHub admin):** enable GitHub Pages on the mirror
  repo — Settings → Pages → Source: **Deploy from a branch**, branch `main`,
  folder `/docs`. Nothing else is needed; plain HTML, no build step.
- OpenAI requires listing URLs to be public, HTTPS, and identify the same
  publisher as the submission — the pages declare NV Digital Solutions as
  the publisher.
- The pages are excluded from the plugin ZIP (`bin/package-plugin.mjs`) —
  they are publisher pages, not plugin content. Keep that exclusion when
  editing the packaging script.

**Review before submitting:** the privacy and terms copy is drafted
publisher-side; have it legally reviewed and dated before the public
submission goes up.
