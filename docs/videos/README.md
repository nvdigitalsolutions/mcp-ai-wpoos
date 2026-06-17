# NV oOS Demo Videos

Automated feature demonstration videos generated via Docker + Playwright.

## Quick Start

```bash
# 1. Ensure Docker is running
docker compose up -d

# 2. Set your AI provider API key (optional — enables chat videos)
export OPENAI_API_KEY="sk-proj-..."

# 3. Run the pipeline
bash bin/capture-demo-videos.sh

# 4. View results
ls -lh docs/videos/base/
```

## Prerequisites

- **Docker 24+** with Docker Compose plugin
- **Node.js 18+**
- **Playwright** — `npm install playwright && npx playwright install chromium`
- **FFmpeg** (optional) — for `.webm` → `.mp4` conversion

## Available Videos

### Base Plugin

| # | Video File | Task | Script |
|---|---|---|---|
| 1 | `add-assistant-tools.mp4` | Add Assistant & Assign Tools | `bin/capture-demo-video-assistant.js` |
| 2 | `configure-ai-provider.mp4` | Configure AI Provider | `bin/capture-demo-video-provider.js` |
| 3 | `chat-conversation.mp4` | Chat Conversation | `bin/capture-demo-video-chat.js` |
| 4 | `chat-tool-execution.mp4` | Chat with Tool Execution | `bin/capture-demo-video-chat-tools.js` |
| 5 | `guest-mode-chat.mp4` | Guest Mode Chat | `bin/capture-demo-video-guest.js` |
| 6 | `manage-tools-presets.mp4` | Manage Tools & Presets | `bin/capture-demo-video-tools-manager.js` |
| 7 | `create-profession.mp4` | Create Profession Template | `bin/capture-demo-video-profession.js` |

### Pro Plugin

| # | Video File | Task | Script |
|---|---|---|---|
| 8–15 | `pro/*.mp4` | Pro dashboards, orchestration, security, etc. | `bin/capture-demo-video-pro.js` |

## Capturing a Single Video

```bash
# Individual script (requires Docker + WordPress already running)
node bin/capture-demo-video-assistant.js
```

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `BASE_URL` | `http://localhost:8000` | WordPress URL |
| `WP_ADMIN_USER` | `admin` | Admin username |
| `WP_ADMIN_PASS` | `password` | Admin password |
| `OPENAI_API_KEY` | — | OpenAI key for AI provider config |
| `GEMINI_API_KEY` | — | Gemini key for AI provider config |
| `CAPTURE_PRO` | `true` | Set to `false` to skip Pro videos |

## Reset Environment

```bash
# Wipe everything and start fresh
docker compose down -v
docker compose up -d
bash bin/capture-demo-videos.sh
```

## Architecture

```
bin/capture-demo-videos.sh        ← Orchestrator (Docker + WP-CLI + dispatch)
    │
    ├─ Docker: WordPress 6.9 + MySQL 8.0
    ├─ WP-CLI: install WP, activate plugin, create test data
    │
    └─ Playwright scripts (one per feature):
        bin/capture-demo-video-assistant.js
        bin/capture-demo-video-provider.js
        bin/capture-demo-video-chat.js
        bin/capture-demo-video-chat-tools.js
        bin/capture-demo-video-guest.js
        bin/capture-demo-video-tools-manager.js
        bin/capture-demo-video-profession.js
        bin/capture-demo-video-pro.js
            │
            └─ bin/video-helpers/
                ├── wp-admin.js    ← Login, navigation, REST helpers
                ├── wp-api.js      ← MCP API helpers
                └── video-utils.js ← Config, context factory, FFmpeg
```

## See Also

- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — Full architecture and implementation plan
- [bin/README-SCREENSHOT-TOOLS.md](../bin/README-SCREENSHOT-TOOLS.md) — Existing screenshot capture tools (similar pattern)
- [docs/screenshots/](../screenshots/) — Static screenshot documentation
