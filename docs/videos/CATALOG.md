# NV oOS Demo Video Catalog

Automated feature demonstration videos generated via Docker + Playwright + TTS + FFmpeg.

**Last generated:** See CI artifacts for latest build.

---

## Base Plugin Videos (Phase 1)

| # | Video | Duration | User Story | Script | Narration |
|---|-------|----------|------------|--------|-----------|
| 1 | **Add Assistant & Tools** | ~90s | Create an AI assistant and assign tools for customer support | `capture-demo-video-assistant.js` | ✅ `add-assistant-tools.txt` |
| 2 | **Configure AI Provider** | ~60s | Connect OpenAI or Gemini to power AI responses | `capture-demo-video-provider.js` | ✅ `configure-ai-provider.txt` |
| 3 | **Chat Conversation** | ~90s | Chat with streaming AI responses in real time | `capture-demo-video-chat.js` | ✅ `chat-conversation.txt` |
| 4 | **Chat with Tool Execution** | ~90s | AI searches your site and returns real content | `capture-demo-video-chat-tools.js` | ✅ `chat-tool-execution.txt` |
| 5 | **Guest Mode Chat** | ~90s | Anonymous visitors chat without logging in | `capture-demo-video-guest.js` | ✅ `guest-mode-chat.txt` |
| 6 | **Manage Tools & Presets** | ~90s | Browse, search, toggle tools and create presets | `capture-demo-video-tools-manager.js` | ✅ `manage-tools-presets.txt` |
| 7 | **Create Profession Template** | ~60s | Create reusable assistant configuration templates | `capture-demo-video-profession.js` | ✅ `create-profession.txt` |

## Pro Plugin Videos (Phase 2)

| # | Video | Duration | User Story | Script | Narration |
|---|-------|----------|------------|--------|-----------|
| 8 | **Pro Dashboard Overview** | ~75s | Analytics, token usage, and monitoring at a glance | `capture-demo-video-pro.js` | ✅ `pro-dashboard.txt` |
| 9 | **Multi-Agent Orchestration** | ~100s | Chain multiple AI agents for complex workflows | `capture-demo-video-pro.js` | ✅ `orchestration-workflow.txt` |
| 10 | **Run Security Audit** | ~90s | Scan your site and get actionable security findings | `capture-demo-video-pro.js` | ✅ `security-audit.txt` |
| 11 | **Site Creator** | ~90s | Generate a complete WordPress site from a template | `capture-demo-video-pro.js` | ✅ `site-creator.txt` |
| 12 | **Federation / Mesh Setup** | ~75s | Connect remote sites for cross-site tool access | `capture-demo-video-pro.js` | ✅ `federation-setup.txt` |
| 13 | **Schedule Manager** | ~75s | Schedule recurring AI tasks with cron patterns | `capture-demo-video-pro.js` | ✅ `schedule-manager.txt` |
| 14 | **Workflow Builder** | ~100s | Visually design multi-step AI pipelines | `capture-demo-video-pro.js` | ✅ `workflow-builder.txt` |
| 15 | **Blueprint System** | ~75s | Export and import complete assistant configurations | `capture-demo-video-pro.js` | ✅ `blueprints.txt` |

## Status Legend

| Icon | Meaning |
|------|---------|
| ✅ | Implemented and available |
| ⚠️ | Partially implemented |
| ❌ | Not yet implemented |

## Quality Standards

- **Resolution:** 1920×1080 (local), 1280×720 (CI)
- **Codec:** H.264 MP4
- **Audio:** AAC 192kbps (narrated)
- **Duration:** 60–180 seconds per video
- **File size:** < 25 MB per video

## Regeneration

```bash
# Full pipeline
bash bin/capture-demo-videos.sh

# With narration
export OPENAI_API_KEY="sk-proj-..."
bash bin/capture-demo-videos.sh
node bin/generate-narration-audio.js --all
node bin/merge-demo-video.js --all

# Single video
node bin/capture-demo-video-assistant.js
```

## See Also

- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — Full architecture and implementation plan
- [README.md](README.md) — Quick start guide
- [bin/](../bin/) — All capture and helper scripts
