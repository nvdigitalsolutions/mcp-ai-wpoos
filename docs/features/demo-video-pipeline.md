# Demo Video Pipeline

**Status:** Stable — v1.1.40
**Source:** `bin/capture-demo-videos.sh`, `bin/_video-template.js`, `.github/workflows/demo-videos.yml`
**Catalog:** `docs/videos/CATALOG.md`

---

## Overview

The Demo Video Pipeline automates the production of feature demonstration videos for NV oOS. It combines scripted browser-scene recording with AI-generated voiceover narration, producing polished videos that showcase plugin features.

## Phases

| Phase | Component | Description |
|-------|-----------|-------------|
| 0 | Scripting | Narration scripts define scenes, cursor movements, and spoken text |
| 1 | Recording | Puppeteer/Playwright scripts capture browser interactions frame-by-frame |
| 2 | Voiceover | AI text-to-speech generates narration audio from scripts |
| 3 | Annotation | On-screen annotations (highlights, arrows, cards) added in post |
| 4 | Assembly | `ffmpeg` composites video, audio, and annotation layers |
| 5 | CI/CD | GitHub Actions workflow automates the full pipeline |

## File Layout

```
bin/
├── capture-demo-videos.sh          # Main entry point — orchestrates all phases
├── _video-template.js              # Base template for scene recording scripts
├── capture-demo-video-assistant.js # Assistant management demo
├── capture-demo-video-chat.js      # Chat interface demo
├── capture-demo-video-chat-tools.js# Tool execution demo
├── capture-demo-video-guest.js     # Guest mode demo
├── capture-demo-video-pro.js       # Pro features demo
├── capture-demo-video-profession.js# Profession setup demo
├── generate-narration-audio.js     # TTS audio generation
├── utils/video-selectors.js        # DOM selectors for all scenes
├── video-helpers/
│   ├── annotation-utils.js         # On-screen annotation rendering
│   ├── card-utils.js               # Info card rendering
│   ├── cursor-utils.js             # Mouse cursor animation
│   ├── narration-utils.js          # Voiceover sync utilities
│   └── wp-admin.js                 # WordPress admin navigation helpers

docs/videos/
├── CATALOG.md                      # Complete video inventory
└── narration/                      # 14 narration scripts
    ├── blueprints.txt
    ├── chat-conversation.txt
    ├── chat-tool-execution.txt
    ├── configure-ai-provider.txt
    ├── create-profession.txt
    ├── federation-setup.txt
    ├── guest-mode-chat.txt
    ├── manage-tools-presets.txt
    ├── orchestration-workflow.txt
    ├── pro-dashboard.txt
    ├── schedule-manager.txt
    ├── security-audit.txt
    ├── site-creator.txt
    └── workflow-builder.txt
```

## Narration Scripts

14 scripts cover all major features:

| Script | Feature |
|--------|---------|
| `blueprints.txt` | Blueprint system |
| `chat-conversation.txt` | Basic chat interaction |
| `chat-tool-execution.txt` | Tool execution in chat |
| `configure-ai-provider.txt` | Provider setup |
| `create-profession.txt` | Profession creation |
| `federation-setup.txt` | Federation & mesh networking |
| `guest-mode-chat.txt` | Guest access mode |
| `manage-tools-presets.txt` | Tool presets management |
| `orchestration-workflow.txt` | Workflow orchestration |
| `pro-dashboard.txt` | Pro dashboard |
| `schedule-manager.txt` | Schedule management |
| `security-audit.txt` | Security auditing |
| `site-creator.txt` | Site creation tools |
| `workflow-builder.txt` | Workflow builder |

## CI/CD Integration

The pipeline runs via `.github/workflows/demo-videos.yml`. Each demo video is:

1. Recorded in a headless browser environment
2. Annotated with cursor movements and info cards
3. Combined with AI-generated narration
4. Uploaded as a workflow artifact

## Running Locally

```bash
# Install dependencies
npm install

# Run all demos
bash bin/capture-demo-videos.sh

# Run a specific demo
node bin/capture-demo-video-chat.js

# Generate narration for a script
node bin/generate-narration-audio.js docs/videos/narration/chat-conversation.txt
```

## Related

- [Video Catalog](../videos/CATALOG.md)
- [Pro SPA v2](pro-spa-v2.md) — many demos showcase the Pro SPA interface
- [Agent Delegation System](agent-delegation-system.md) — demonstrated in orchestration workflow video
