# Playground Blueprints — NV oOS Complete Demos

Dev-only blueprints for the full NV oOS plugin (base + Pro "Complete" bundle).
**This folder never ships in distribution ZIPs** — it is excluded from
`bin/build-plugin-zip.sh` (both rsync blocks) and from the root `.distignore`
(wp.org SVN deploy).

| File | Role |
|---|---|
| `ollama-demo.json` | **Generated.** One-click demo that pre-wires NV oOS Complete to the user's **local Ollama** and lands on a chat page that answers immediately (if Ollama is running). |
| `ollama-demo.php` | Seed snippet embedded into the blueprint's `runPHP` step: configures the Ollama provider (`enable_ollama`, endpoint `http://localhost:11434`, model `llama3.1:8b`, `default_provider`/`default_model`, priority list), creates the "Oma" demo assistant (`mcp_ai_assistant` CPT + meta) as `default_assistant`, creates the **Ollama Test Lab** page with `[ollama_status]` + the **legacy `[mcp_ai_chat]`**, and a secondary **Ollama Test Lab (Pro SPA)** page with `[nvoos_pro_spa … cron_monitor="0"]` when Pro is present. Idempotent (`nvoos_ollama_demo_seeded` option). |

The blueprint also writes `wp-content/mu-plugins/ollama-status.php` — a
self-diagnosing `[ollama_status]` shortcode that renders a green/amber/red
banner with the user's model list (source lives in
`bin/generate-ollama-blueprint.php`). The check runs **client-side** (async
fetch to `http://localhost:11434/api/tags` with an AbortController timeout):
a synchronous PHP-side `wp_remote_get()` to localhost on the render path
can hang the Playground worker when the browser's Private Network Access
policy blocks the request, which crashes the whole instance (duplicate
SQLite preload fatal).

The chat embed on the **main page** uses the legacy `[mcp_ai_chat]`
shortcode: it makes a stream request only when the user sends a message,
which keeps the cold Playground worker alive. The **secondary page** offers
the Pro SPA v2 embedded surface (`[nvoos_pro_spa]`, Pro — ships in the
Complete bundle): chat-first embedded mode with transcripts, drawers, tool
shortcuts, and the OKF drawer, mounted via
`[nvoos_pro_spa assistant_id="<id>" theme="dark" height="720px" show_sidebar="0" cron_monitor="0"]`.
The Pro SPA opens a blocking SSE cron-status stream on mount by default;
`cron_monitor="0"` (Pro v1.1.82+, PR #6665) disables that job stream plus
its REST poll fallback so the mount does not hold a worker slot. Older
bundles ignore the attribute — harmless, but the stream stays on and the
page may crash the instance; that is why the legacy chat remains the
default landing surface.

The banner's inline JS is deliberately pure ASCII (`\uXXXX` escapes for
emoji/em-dashes): multi-byte UTF-8 inside a streamed inline script can be
misdecoded with a non-UTF-8 fallback and throw "Invalid or unexpected
token" in the browser.

## Why localhost works here

Playground runs WordPress **in the browser**, so the plugin's
`http://localhost:11434` endpoint IS the user's machine. The plugin's SSRF
guard (`wp_mcp_ai_validate_ai_provider_url()`) explicitly allowlists
`localhost`/`127.0.0.1` for AI providers.

## Regenerating

```bash
php bin/generate-ollama-blueprint.php
```

The bundle URL is pinned to `build/nvdigital-open-operator-system-oos-complete-1.1.81.zip`
served via `raw.githubusercontent.com` (CORS-enabled). Bump the pin in the
generator when a new release ships.

## Trying it

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/<branch>/blueprints/ollama-demo.json
```

**The user needs a running local Ollama that allows this origin:**

- Windows: `ollama pull llama3.1:8b` then
  `setx OLLAMA_ORIGINS "https://playground.wordpress.net,http://localhost,http://127.0.0.1"`
  and restart Ollama (quit from the tray).
- macOS / Linux: `OLLAMA_ORIGINS="https://playground.wordpress.net,http://localhost,http://127.0.0.1" ollama serve`

**Browser caveats** (documented on the demo page itself):

- The `[ollama_status]` banner runs client-side: Firefox works out of the box
  once Ollama allows the origin; Chrome/Edge may block localhost requests
  (Private Network Access) until
  `chrome://flags/#block-insecure-private-network-requests` is disabled.
- The CHAT's model call is PHP-side (inside Playground's worker). Browsers
  sandbox that worker away from localhost (confirmed in both Chrome and
  Firefox), so the banner can be green while the chat cannot answer in the
  browser preview.
- Full chat experience: run the demo locally -
  `npx -y @wp-playground/cli@3.1.54 server --blueprint=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/blueprints/ollama-demo.json --login`
  and open the printed local URL (Node transport reaches local Ollama
  directly; validated end-to-end: `/api/chat` returned "Asteria Online").

## Validation (2026-09-18)

End-to-end via `@wp-playground/cli` on WP 7.1.1 / PHP 8.3 against a real
local Ollama 0.32.5 (llama3.1:8b):

- Plugin active; settings correctly pre-wired (`enable_ollama`, endpoint,
  model, `default_provider`, priority list starts with `ollama`)
- Demo assistant created (provider/model meta set) and marked
  `default_assistant`
- Test Lab page created with the legacy `[mcp_ai_chat]` (chat container
  rendered) + `[ollama_status]`; secondary "Ollama Test Lab (Pro SPA)"
  page created with `[nvoos_pro_spa … cron_monitor="0"]` (Pro SPA v2
  registered and rendering its embedded mount div); mu-plugin shortcode
  registered
- `[ollama_status]` renders the client-side checker container + inline
  script, and the mu-plugin performs **no PHP-side HTTP to localhost**
  (verified: `wp_remote_get` absent, `AbortController` present)
- Seed + banner output are **pure ASCII** (only comment-only em dashes
  remain in the embedded snippet); every rendered inline script passes
  `node --check` via the probe harness
  (`bin/make-probe-blueprint.php` + `@wp-playground/cli run-blueprint`)
- `/api/tags` → 200; `/api/chat` → 200 — the model answered
  **"Asteria Online"**
