# Test NV oOS on Your PC — Playground Demo

Want to try the full NV oOS Complete bundle **without installing a WordPress
site**? This guide covers two ways to run the demo, which executes WordPress
and the plugin via [WordPress Playground](https://developer.wordpress.org/playground/)
and wires the chat to **your local Ollama** — no prompts ever leave your
machine.

- **Run it on your PC (primary, most reliable)** — one `npx` command; the
  PHP worker reaches local Ollama directly, so the banner turns green and
  the chat answers with zero browser policy friction.
- **Quick preview in the browser** — a one-click link; great for a look,
  but browsers sandbox Playground's worker away from your localhost, so the
  chat cannot answer there.

## What the demo gives you

- A real WordPress site (WP 7.1) with the **NV oOS Complete bundle** active.
- The **"Oma" demo assistant**, pre-configured for Ollama (`llama3.1:8b`).
- The **Ollama Test Lab** page with a live connectivity banner and a chat
  that answers from your local models.
- A secondary **Pro SPA** page (`/ollama-test-lab-pro/`) with the full
  toolkit chat surface.
- Full wp-admin access (user `admin`, password `password`) so you can poke
  at assistants, providers, tools, and settings.

## Before you run: get Ollama ready

The demo assumes you have [Ollama](https://ollama.com/download) installed
and at least one model pulled:

```bash
ollama pull llama3.1:8b
```

Ollama blocks cross-origin requests by default, so allow the local origins:

- **Windows:**
  ```bat
  setx OLLAMA_ORIGINS "https://playground.wordpress.net,http://localhost,http://127.0.0.1"
  ```
  Then quit Ollama from the system tray and relaunch it.
  > **Gotcha:** the Ollama Desktop app silently relaunches its server
  > process *without* the environment variable. If the banner below stays
  > red, kill both `ollama app.exe` and `ollama.exe` in Task Manager, then
  > start Ollama again (or run
  > `OLLAMA_ORIGINS="https://playground.wordpress.net,http://localhost,http://127.0.0.1" ollama serve`
  > from a terminal).
- **macOS / Linux:**
  ```bash
  OLLAMA_ORIGINS="https://playground.wordpress.net,http://localhost,http://127.0.0.1" ollama serve
  ```

## Run it on your PC (primary, most reliable)

Requires [Node.js](https://nodejs.org/) 20+. This boots the exact same demo
on your machine — Playground's PHP worker then reaches local Ollama
**directly**, so the banner turns green and the chat answers with zero
browser policy friction:

```bash
npx -y @wp-playground/cli@3.1.54 server --blueprint=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/blueprints/ollama-demo.json --login
```

Open the printed local URL (e.g. `http://127.0.0.1:9400/ollama-test-lab/`).
The first boot takes **a few minutes** while it downloads and installs the
whole stack (the boot runs lazily inside the first page load — if you see a
"WordPress is not ready yet" placeholder, just wait and refresh). After
that:

1. The **Ollama Test Lab** page opens. The status banner checks your local
   Ollama from the page itself and, within a few seconds, turns **green**
   ("Ollama connected!" with your model list).
2. Type a message into the chat and press send. The first reply can take a
   while (the worker is cold and the model runs locally) — subsequent
   replies are faster.
3. Explore:
   - `/ollama-test-lab-pro/` — the Pro SPA v2 chat surface.
   - `/wp-admin/` (user `admin`, password `password`) — assistants,
     **NV oOS → Settings → Providers → Ollama** (endpoint, model, "Test
     connection"), tools, workflows, and the rest of the toolkit.

## Quick preview in the browser (no install)

Prefer to just look around without running anything?

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/blueprints/ollama-demo.json
```

Recommended: **Firefox**. Chrome/Edge may block the browser's localhost
requests (Private Network Access); if so, either use Firefox or disable
`chrome://flags/#block-insecure-private-network-requests`.

In the preview, the **status banner** can turn green, but the **chat cannot
answer**: browsers sandbox Playground's PHP worker away from your localhost
(confirmed in both Chrome and Firefox). Use the local server above for the
full chat experience.

## What you should see

1. Playground boots WordPress and installs the Complete bundle (30–60
   seconds in the browser preview on first load; a few minutes for the
   local server — be patient; it is assembling a full WP install plus the
   plugin).
2. The **Ollama Test Lab** page opens. The status banner checks your local
   Ollama from the page itself and, within a few seconds, turns:
   - **Green** — "Ollama connected!" with your model list. Ready to chat.
   - **Amber** — the request timed out; Ollama is probably not running.
   - **Red** — the browser blocked the localhost request (CORS/PNA); see
     the browser notes above and the Ollama origin setup.
3. Type a message into the chat and press send. The first reply can take
   a while (the worker is cold and the model runs locally) — subsequent
   replies are faster.
4. Explore:
   - `/ollama-test-lab-pro/` — the Pro SPA v2 chat surface.
   - `/wp-admin/` (user `admin`, password `password`) — assistants,
     **NV oOS → Settings → Providers → Ollama** (endpoint, model, "Test
     connection"), tools, workflows, and the rest of the toolkit.

## Troubleshooting

| Symptom | What to do |
|---|---|
| Banner stays on "Checking…" forever | Playground served a cached blueprint. Hard-refresh, start a **new** site, or use the cache-busted link (`…/ollama-demo.json?v=2`). |
| Banner is amber ("Ollama not detected") | Ollama is not reachable. Check it is running, then refresh the page. |
| Banner is red ("browser blocked the localhost request") | Fix `OLLAMA_ORIGINS` (see above — remember the desktop-app relaunch gotcha), or switch to Firefox. |
| Chat times out while the banner is green | The browser preview sandboxes the PHP worker's call to your localhost — it cannot chat there. Run the demo locally with the `npx` command above for the full chat experience. |
| Page feels unresponsive after load | The cold worker takes seconds per request. Wait a moment and retry; a refresh on the same site is faster than a fresh boot. |

## Local server notes

- The first boot is slow by design: the CLI downloads the Complete bundle
  and activates the plugin lazily inside the first request. Expect several
  minutes before the landing page renders; the auto-login step then
  redirects once (the browser handles this transparently).
- Banner green + working chat is expected here with zero browser policy
  friction.
- You can also run the demo from a local copy of the blueprint:
  `npx -y @wp-playground/cli@3.1.54 server --blueprint=blueprints/ollama-demo.json --login`
  (from the repository root).

## Related

- [blueprints/README.md](../../blueprints/README.md) — blueprint internals,
  regeneration, and validation notes.
- [mcp-ai-wpoos-playground-demos skill](../../.agents/skills/mcp-ai-wpoos-playground-demos/SKILL.md) —
  authoring/debugging playbook for these demos.
