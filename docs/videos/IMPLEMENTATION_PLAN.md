# NV oOS Demo Video Pipeline — Comprehensive Implementation Plan

**Status:** Draft for review  
**Date:** 2026-06-08  
**Author:** AI Agent (Zed)  
**Scope:** Automated generation of feature demo videos for Base + Pro plugin, driven by Docker + Playwright

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Research Findings & Industry Best Practices](#research-findings--industry-best-practices)
3. [Existing Infrastructure Audit](#existing-infrastructure-audit)
4. [Architecture Decision](#architecture-decision)
5. [Target Video Catalog](#target-video-catalog)
6. [File Structure & Deliverables](#file-structure--deliverables)
7. [Implementation Phases](#implementation-phases)
8. [Script Design Specifications](#script-design-specifications)
9. [Docker Integration](#docker-integration)
10. [Narration Pipeline (Phase 3)](#narration-pipeline-phase-3)
11. [CI/CD Integration (Phase 4)](#cicd-integration-phase-4)
12. [Quality Standards](#quality-standards)
13. [Risk Register](#risk-register)
14. [Open Questions](#open-questions)

---

## Executive Summary

Create an automated pipeline that spins up a Dockerized WordPress environment, configures the NV oOS plugin (Base + Pro), and produces `.mp4` feature-demo videos — one per user task — via **Playwright browser automation with built-in `recordVideo`**. Each video demonstrates one complete user story (e.g., "Add an assistant and assign tools"). The pipeline is designed to be re-run on every release so videos never go stale.

**Core technology stack:** Docker Compose → WordPress 6.9 → Playwright `recordVideo` → FFmpeg optimization → `.mp4` output.

**Key design principle:** The narration script + Playwright test = single source of truth. Change the feature, update the script, re-run the pipeline.

---

## Research Findings & Industry Best Practices

### What the Industry Is Doing (2024–2026)

| Technique | Example Tools | Best For |
|---|---|---|
| **Playwright `recordVideo`** | Playwright native, `@playwright/test` | Automated test-to-video, CI pipelines |
| **Playwright + TTS Narration** | Playwright + ElevenLabs/OpenAI TTS + FFmpeg | Polished product walkthroughs with AI voiceover |
| **Playwright Trace → Video** | `playwright-recast` (npm) | Converting existing test traces to demo videos |
| **Traditional screen recording** | OBS Studio, Camtasia, Loom | One-off manual demos (not recommended for this project) |

### Key Articles Applied to This Project

1. **PurpleOwl: "We Automated Our Product Walkthrough Video. The Whole Thing."** (March 2026)  
   → Pattern: narration array → TTS → Playwright actions timed to audio durations → FFmpeg merge.  
   → Applied here: Phase 3 of this plan.

2. **Playwright Docs: `recordVideo`**  
   → `browser.newContext({ recordVideo: { dir, size } })` produces WebM directly.  
   → Applied here: Phase 1 — zero additional dependencies, immediate output.

3. **DEV.to: "I Was Tired of Re-Recording Product Demos Every Sprint"** (March 2026)  
   → `playwright-recast` library converts Playwright traces to polished videos with voiceover.  
   → Applied here: Option for Phase 3 if trace-based generation is preferred.

4. **Reddit r/Playwright: "Turn Playwright scripts into polished product demo videos"** (2026)  
   → Community consensus: Playwright recording with click annotations + chapters is the modern standard.  
   → Applied here: `show: { actions }` annotations in Phase 2.

### Decision Rationale

- **Start with Approach A (Playwright `recordVideo`)** — reuses existing Playwright patterns in `bin/capture-admin-screenshots.js` and `bin/playwright-capture-screenshots.js`. Zero new dependencies. Immediate working output.
- **Graduate to Approach B (TTS narration)** — once task scripts are stable and the output quality needs a polish boost.
- **Do NOT use traditional screen recording** — the plugin has ~830 tools and frequent UI changes. Manual re-recording is unsustainable.

---

## Existing Infrastructure Audit

### What We Already Have (That the Video Pipeline Reuses)

```
# Docker
docker-compose.yml          → WordPress 6.9 + MySQL 8.0 + WP-CLI (profiles: tools)
docker/README.md            → Docker docs
docker/setup.sh             → Laravel/Craft env setup (not used for WP videos)

# Playwright (library API — standalone scripts)
bin/capture-admin-screenshots.js     → Captures 80+ admin page screenshots
bin/playwright-capture-screenshots.js → Drives chat UI, captures 12 chat screenshots

# Playwright (@playwright/test API — test framework)
tests/qa/playwright/
├── package.json            → @playwright/test ^1.60.0
├── playwright.config.ts    → Video: 'off' (needs toggling for video capture)
├── fixtures/wp-admin.ts    → WPAdmin class (login, nonce, REST helpers)
├── utils/wp-helpers.ts     → mcpApiRequest, listAssistants, listTools, etc.
└── tests/
    ├── smoke.spec.ts       → 6 critical-path smoke tests
    ├── admin.spec.ts       → Admin page tests
    └── auth.spec.ts        → Authentication tests

# WP-CLI Setup (bash)
bin/capture-chat-screenshots.sh     → WP-CLI: install WP, activate plugin,
                                       configure AI provider, create test assistant & pages

# Output directories
docs/screenshots/admin/     → Admin screenshots (63 captured)
docs/screenshots/chat/      → Chat screenshots (12 captured)
docs/screenshots/dashboard/ → Pro dashboard screenshots
docs/screenshots/frontend/  → Frontend screenshots
docs/screenshots/tools/     → Tool screenshots
docs/screenshots/integrations/ → Integration screenshots

# Docs
docs/features/README.md     → Feature guide index
docs/screenshots/INDEX.md   → Screenshot index (66/71 captured)
docs/videos/                → **NEW** — video output directory (created by this plan)
```

### Key Reusable Components

| Component | File | What We Reuse |
|---|---|---|
| Docker WP env | `docker-compose.yml` | `docker compose up -d` → ready in ~30s |
| WP-CLI scripting | Pattern from `capture-chat-screenshots.sh` | Install WP, activate plugin, set options, create posts |
| Playwright login | `capture-admin-screenshots.js` lines 30–38 | `login(page)` function |
| Playwright `ss()` helper | `capture-admin-screenshots.js` lines 40–52 | `goto + waitForTimeout + screenshot` — adapt to video |
| WPAdmin fixture | `tests/qa/playwright/fixtures/wp-admin.ts` | `login()`, `goToAdminPage()`, `getRestNonce()` |
| REST helpers | `tests/qa/playwright/utils/wp-helpers.ts` | `listAssistants()`, `listTools()`, `executeTool()` |

---

## Architecture Decision

### Phase 1–2: Playwright Library API (standalone scripts)

**Decision:** Build video scripts as standalone Node.js scripts using `require('playwright')` (library API), following the exact pattern of `bin/capture-admin-screenshots.js`.

**Why not `@playwright/test`?**
- `@playwright/test` has `video: 'on'` in config, but it's per-test and requires the test runner.
- Standalone scripts give us full control over when recording starts/stops, viewport size, and annotations.
- Consistent with the existing `bin/*.js` screenshot scripts.
- Simpler: one `node bin/capture-demo-video-assistant.js` command.

**However:** The `WPAdmin` class and `wp-helpers.ts` utilities from the E2E suite will be **ported to CommonJS** versions in `bin/video-helpers/` for reuse.

### Phase 3: Optional TTS narration layer

**Decision:** Add a separate `narration/` directory with `.txt` scripts per video. A `bin/generate-narration-audio.js` script converts text to MP3 via ElevenLabs/OpenAI TTS. A `bin/merge-video-audio.js` script uses FFmpeg to combine silent video + narration audio.

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                  bin/capture-demo-videos.sh               │
│  Orchestrator: docker up → WP-CLI setup → run all scripts │
└──────────┬──────────────────────────────────┬────────────┘
           │                                  │
    ┌──────▼──────┐                   ┌───────▼───────┐
    │  WP-CLI     │                   │  Playwright    │
    │  setup      │                   │  video scripts │
    │             │                   │                │
    │ • install WP│                   │ • login        │
    │ • activate  │                   │ • navigate     │
    │ • configure │                   │ • interact     │
    │ • create    │                   │ • recordVideo  │
    │   test data │                   │ • close context│
    └─────────────┘                   └───────┬───────┘
                                             │
                                      ┌──────▼──────┐
                                      │  Output:     │
                                      │  .webm files │
                                      └──────┬──────┘
                                             │
                                    (Phase 3: FFmpeg)
                                             │
                                      ┌──────▼──────┐
                                      │  Final .mp4  │
                                      │  + narration │
                                      └─────────────┘
```

---

## Target Video Catalog

### Phase 1: Core Base Plugin Tasks (7 videos)

These are the most important user-facing tasks. Each video is **60–120 seconds**, self-contained, and shows one complete user story.

| # | Task | Video File | User Story | Key Interactions |
|---|---|---|---|---|
| 1 | **Add Assistant & Assign Tools** | `add-assistant-tools.mp4` | As a site admin, I want to create an AI assistant and assign tools so it can help my users | Navigate Assistants CPT → click Add New → enter title/system prompt → select model → open Tools tab → search/enable tools → publish → verify on frontend |
| 2 | **Configure AI Provider** | `configure-ai-provider.mp4` | As a site admin, I want to connect OpenAI/Gemini/Ollama so the plugin can generate responses | Settings → AI Providers tab → enter API key → select default model → test connection → save |
| 3 | **Chat Conversation** | `chat-conversation.mp4` | As a visitor, I want to chat with an AI assistant and see streaming responses | Frontend page with `[mcp_ai_chat]` shortcode → type message → send → see streaming response → ask follow-up → see context awareness |
| 4 | **Chat with Tool Execution** | `chat-tool-execution.mp4` | As a user, I want the AI to use tools to fetch real data from my site | Chat → ask "search my site for recent posts" → see tool execution indicator → see results from wp_post_search tool |
| 5 | **Guest Mode Chat** | `guest-mode-chat.mp4` | As an anonymous visitor, I want to chat without logging in | Incognito window → guest-enabled chat page → token generation → message exchange → history in localStorage |
| 6 | **Manage Tools & Presets** | `manage-tools-presets.mp4` | As a site admin, I want to enable/disable specific tools for different assistants | Tools Manager page → browse categories → toggle tools → create preset → assign preset to assistant |
| 7 | **Create Profession Template** | `create-profession.mp4` | As a site admin, I want to create a profession template that other admins can use | Add New Profession → name/description → system prompt template → assigned tool preset → publish → see in template grid |

### Phase 2: Pro Plugin Tasks (8 videos)

| # | Task | Video File | User Story |
|---|---|---|---|
| 8 | **Pro Dashboard Overview** | `pro-dashboard.mp4` | As a Pro user, I want to see analytics, monitoring, and usage stats at a glance |
| 9 | **Multi-Agent Orchestration** | `orchestration-workflow.mp4` | As a developer, I want to chain multiple AI agents for complex workflows |
| 10 | **Run Security Audit** | `security-audit.mp4` | As a security admin, I want to scan my site and see actionable findings |
| 11 | **Site Creator (Template → Deploy)** | `site-creator.mp4` | As a developer, I want to generate a complete site from a template |
| 12 | **Federation / Mesh Setup** | `federation-setup.mp4` | As a network admin, I want to connect remote sites for cross-site tool access |
| 13 | **Schedule Manager** | `schedule-manager.mp4` | As an admin, I want to schedule recurring AI tasks |
| 14 | **Workflow Builder** | `workflow-builder.mp4` | As a power user, I want to visually build multi-step AI pipelines |
| 15 | **Blueprint System** | `blueprints.mp4` | As a developer, I want to export/import complete assistant configurations |

### Phase 3: Narration + Polish (all 15 videos)

Add AI voiceover narration to all Phase 1–2 videos.

### Stretch Goals (Post-Phase 3)

- **Mobile-responsive chat** (375×667 viewport)
- **Error handling** (disconnect, invalid API key, rate limiting)
- **File upload** (attach PDF/image to chat)
- **Elementor widget integration** (requires Elementor installed in Docker)
- **WooCommerce tools demo** (requires WooCommerce installed)
- **REST API authentication flow** (nonce, bearer token, guest token)

---

## File Structure & Deliverables

```
# ── New Files Created by This Plan ──

docs/videos/
├── IMPLEMENTATION_PLAN.md          ← THIS FILE
├── README.md                       ← Index of all videos, how to regenerate, quick start
├── CATALOG.md                      ← Video catalog with descriptions, durations, status
├── base/                           ← Phase 1 output
│   ├── add-assistant-tools.mp4
│   ├── configure-ai-provider.mp4
│   ├── chat-conversation.mp4
│   ├── chat-tool-execution.mp4
│   ├── guest-mode-chat.mp4
│   ├── manage-tools-presets.mp4
│   └── create-profession.mp4
├── pro/                            ← Phase 2 output
│   ├── pro-dashboard.mp4
│   ├── orchestration-workflow.mp4
│   ├── security-audit.mp4
│   ├── site-creator.mp4
│   ├── federation-setup.mp4
│   ├── schedule-manager.mp4
│   ├── workflow-builder.mp4
│   └── blueprints.mp4
└── narration/                      ← Phase 3
    ├── add-assistant-tools.txt
    ├── configure-ai-provider.txt
    ├── chat-conversation.txt
    └── ...

bin/
├── capture-demo-videos.sh                  ← Orchestrator: docker up → WP-CLI setup → run all
├── capture-demo-video-assistant.js         ← Video #1: Add Assistant & Tools
├── capture-demo-video-provider.js          ← Video #2: Configure AI Provider
├── capture-demo-video-chat.js              ← Video #3: Chat Conversation
├── capture-demo-video-chat-tools.js        ← Video #4: Chat with Tool Execution
├── capture-demo-video-guest.js             ← Video #5: Guest Mode Chat
├── capture-demo-video-tools-manager.js     ← Video #6: Manage Tools & Presets
├── capture-demo-video-profession.js        ← Video #7: Create Profession Template
├── capture-demo-video-pro.js               ← Video #8–15: Pro plugin tasks
├── generate-narration-audio.js             ← Phase 3: Text → TTS MP3s
├── merge-demo-video.js                     ← Phase 3: WebM + MP3s → polished MP4
└── video-helpers/                          ← Shared helpers (ported from E2E suite)
    ├── wp-admin.js                         ← CommonJS port of fixtures/wp-admin.ts
    ├── wp-api.js                           ← CommonJS port of utils/wp-helpers.ts
    └── video-utils.js                      ← recordVideo config, FFmpeg wrappers, timing helpers
```

---

## Implementation Phases

### Phase 1: Foundation + Core Scripts (Week 1)

**Goal:** One-command pipeline that produces silent `.webm` videos for all 7 base plugin tasks.

#### Step 1.1: Create shared video helpers

**File:** `bin/video-helpers/wp-admin.js`  
**Contents:** CommonJS port of `WPAdmin` class from `tests/qa/playwright/fixtures/wp-admin.ts`.

```js
// bin/video-helpers/wp-admin.js
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

class WPAdmin {
  constructor(page) { this.page = page; }

  async login(username = process.env.WP_ADMIN_USER || 'admin',
              password = process.env.WP_ADMIN_PASS || 'password') {
    await this.page.goto('/wp-admin', { waitUntil: 'networkidle' });
    if (await this.page.$('#user_login')) {
      await this.page.fill('#user_login', username);
      await this.page.fill('#user_pass', password);
      await this.page.click('#wp-submit');
      await this.page.waitForSelector('#wpadminbar', { timeout: 15000 });
    }
  }

  async goToAdminPage(slug) {
    await this.page.goto(`/wp-admin/admin.php?page=${slug}`, { waitUntil: 'networkidle' });
  }

  // ... (port remaining methods from TypeScript fixture)
}

module.exports = { WPAdmin };
```

**File:** `bin/video-helpers/video-utils.js`  
**Contents:** Shared configuration, context factory, FFmpeg optimization wrapper.

```js
const VIDEO_CONFIG = {
  viewport: { width: 1920, height: 1080 },
  recordVideo: {
    dir: path.resolve(__dirname, '..', '..', 'docs', 'videos', 'base'),
    size: { width: 1920, height: 1080 },
  },
  baseUrl: process.env.BASE_URL || 'http://localhost:8000',
};

async function createVideoContext(browser, outputDir) {
  return browser.newContext({
    viewport: VIDEO_CONFIG.viewport,
    recordVideo: {
      dir: outputDir,
      size: VIDEO_CONFIG.size,
    },
  });
}

async function optimizeVideo(inputPath, outputPath) {
  const { execSync } = require('child_process');
  execSync(
    `ffmpeg -y -i "${inputPath}" -c:v libx264 -preset fast -crf 28 -c:a aac -b:a 128k "${outputPath}"`,
    { stdio: 'inherit' }
  );
}

module.exports = { VIDEO_CONFIG, createVideoContext, optimizeVideo };
```

#### Step 1.2: Build the orchestrator script

**File:** `bin/capture-demo-videos.sh`

Following the proven pattern from `bin/capture-chat-screenshots.sh`:

```bash
#!/bin/bash
set -euo pipefail

# ── Configuration ──
BASE_URL="${BASE_URL:-http://localhost:8000}"
ADMIN_USER="${WP_ADMIN_USER:-admin}"
ADMIN_PASS="${WP_ADMIN_PASS:-password}"
VIDEO_DIR="docs/videos/base"

# ── Colour helpers ──
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[VIDEO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[VIDEO]${NC} $1"; }
err()   { echo -e "${RED}[VIDEO]${NC} $1"; }

# ── Prerequisites check ──
check_prereqs() {
  info "Checking prerequisites..."

  if ! docker compose ps 2>/dev/null | grep -q "Up"; then
    info "Starting Docker environment..."
    docker compose up -d
    info "Waiting for WordPress to be ready..."
    for i in $(seq 1 30); do
      if curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null | grep -q "200\|302"; then
        break
      fi
      sleep 2
    done
  fi

  if ! curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" | grep -q "200\|302"; then
    err "WordPress is not responding at $BASE_URL"
    exit 1
  fi

  info "WordPress is ready at $BASE_URL"
}

# ── WordPress setup via WP-CLI ──
setup_wordpress() {
  info "Setting up WordPress..."

  INSTALL_STATUS=$(docker compose run --rm wp-cli core is-installed 2>&1 || echo "not installed")

  if echo "$INSTALL_STATUS" | grep -q "not installed"; then
    info "Installing WordPress..."
    docker compose run --rm wp-cli core install \
      --url="$BASE_URL" \
      --title="NV oOS Demo" \
      --admin_user="$ADMIN_USER" \
      --admin_password="$ADMIN_PASS" \
      --admin_email="demo@example.com" \
      --skip-email
  fi

  info "Activating plugin..."
  docker compose run --rm wp-cli plugin activate mcp-ai-wpoos 2>&1 || true

  # Activate pro if available
  if [ -d "addons/pro" ]; then
    docker compose run --rm wp-cli plugin activate mcp-ai-wpoos-pro 2>&1 || true
  fi

  # Configure AI provider if API key is set
  if [ -n "${OPENAI_API_KEY:-}" ]; then
    info "Configuring OpenAI..."
    docker compose run --rm wp-cli option update wp_mcp_ai_openai_api_key "$OPENAI_API_KEY"
    docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "openai"
    docker compose run --rm wp-cli option update wp_mcp_ai_default_model "gpt-4o"
  elif [ -n "${GEMINI_API_KEY:-}" ]; then
    info "Configuring Gemini..."
    docker compose run --rm wp-cli option update wp_mcp_ai_gemini_api_key "$GEMINI_API_KEY"
    docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "gemini"
  fi

  info "WordPress setup complete."
}

# ── Create test data ──
create_test_data() {
  info "Creating test data..."

  # Create a demo page with chat shortcode
  PAGE_ID=$(docker compose run --rm wp-cli post create \
    --post_type=page --post_title="AI Chat Demo" --post_status=publish \
    --post_content='[mcp_ai_chat allow_guests="true"]' \
    --porcelain 2>&1 | grep -o '[0-9]*' | head -1 || echo "")

  if [ -n "$PAGE_ID" ]; then
    info "Created chat demo page (ID: $PAGE_ID)"
    export PAGE_ID
  fi

  # Create a test post for search tools to find
  docker compose run --rm wp-cli post create \
    --post_type=post --post_title="Sample Blog Post" --post_status=publish \
    --post_content="This is a sample post for testing the AI search tools." 2>&1 || true
}

# ── Run video capture scripts ──
capture_base_videos() {
  mkdir -p "$VIDEO_DIR"

  info "Capturing Base Plugin videos..."

  node bin/capture-demo-video-assistant.js && info "✅ add-assistant-tools.mp4" || warn "⚠️  add-assistant-tools.mp4 FAILED"
  node bin/capture-demo-video-provider.js  && info "✅ configure-ai-provider.mp4" || warn "⚠️  configure-ai-provider.mp4 FAILED"
  node bin/capture-demo-video-chat.js      && info "✅ chat-conversation.mp4" || warn "⚠️  chat-conversation.mp4 FAILED"
  node bin/capture-demo-video-chat-tools.js && info "✅ chat-tool-execution.mp4" || warn "⚠️  chat-tool-execution.mp4 FAILED"
  node bin/capture-demo-video-guest.js     && info "✅ guest-mode-chat.mp4" || warn "⚠️  guest-mode-chat.mp4 FAILED"
  node bin/capture-demo-video-tools-manager.js && info "✅ manage-tools-presets.mp4" || warn "⚠️  manage-tools-presets.mp4 FAILED"
  node bin/capture-demo-video-profession.js && info "✅ create-profession.mp4" || warn "⚠️  create-profession.mp4 FAILED"
}

capture_pro_videos() {
  if [ ! -d "addons/pro" ]; then
    warn "Pro addon not found — skipping Pro videos."
    return
  fi

  mkdir -p "docs/videos/pro"

  info "Capturing Pro Plugin videos..."
  node bin/capture-demo-video-pro.js && info "✅ Pro videos captured" || warn "⚠️  Pro videos FAILED"
}

# ── Optimize output ──
optimize_videos() {
  if command -v ffmpeg &> /dev/null; then
    info "Optimizing videos with FFmpeg..."
    find docs/videos -name "*.webm" -exec sh -c '
      for f; do
        out="${f%.webm}.mp4"
        ffmpeg -y -i "$f" -c:v libx264 -preset fast -crf 28 -c:a aac -b:a 128k "$out" 2>/dev/null
        echo "  Optimized: $out"
      done
    ' _ {} +
  else
    warn "FFmpeg not found — videos remain as .webm"
  fi
}

# ── Main ──
main() {
  echo ""
  info "═══════════════════════════════════════════"
  info "  NV oOS Demo Video Pipeline"
  info "═══════════════════════════════════════════"
  echo ""

  check_prereqs
  setup_wordpress
  create_test_data

  echo ""
  info "Starting video capture..."
  echo ""

  capture_base_videos

  if [ "${CAPTURE_PRO:-true}" = "true" ]; then
    capture_pro_videos
  fi

  optimize_videos

  echo ""
  info "═══════════════════════════════════════════"
  info "  Pipeline Complete!"
  info "  Videos: docs/videos/base/*.mp4"
  [ -d "docs/videos/pro" ] && info "  Pro:     docs/videos/pro/*.mp4"
  info "═══════════════════════════════════════════"
  echo ""
}

main "$@"
```

#### Step 1.3: Build the first video script (Add Assistant & Tools)

**File:** `bin/capture-demo-video-assistant.js`

```js
#!/usr/bin/env node
/**
 * NV oOS Demo Video — Add Assistant & Assign Tools
 *
 * Demonstrates:
 *   1. Navigating to AI Assistants CPT
 *   2. Creating a new assistant (title, description, system prompt, model)
 *   3. Opening the Tools tab and searching/enabling tools
 *   4. Publishing the assistant
 *   5. Verifying the assistant appears in the list
 *
 * Usage:   node bin/capture-demo-video-assistant.js
 * Prereq:  docker compose up -d && bash bin/capture-demo-videos.sh (setup phase)
 * Output:  docs/videos/base/add-assistant-tools.webm
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { WPAdmin } = require('./video-helpers/wp-admin');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000';
const ADMIN_URL = `${BASE_URL}/wp-admin`;
const OUT_DIR = path.resolve(__dirname, '..', 'docs', 'videos', 'base');
const VIDEO_FILE = 'add-assistant-tools';

fs.mkdirSync(OUT_DIR, { recursive: true });

// ── Timing Constants (milliseconds) ──
const PAUSE_SHORT  = 800;   // Brief pause after navigation
const PAUSE_MEDIUM = 1500;  // Let user read content
const PAUSE_LONG   = 3000;  // Wait for JS rendering / API responses
const TYPE_DELAY   = 50;    // ms between keystrokes

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    recordVideo: {
      dir: OUT_DIR,
      size: { width: 1920, height: 1080 },
    },
  });

  const page = await context.newPage();
  const admin = new WPAdmin(page);

  try {
    // ── Login ──
    await admin.login();
    await page.waitForTimeout(PAUSE_SHORT);

    // ── Step 1: Navigate to Assistants list ──
    await page.goto(`${ADMIN_URL}/edit.php?post_type=mcp_ai_assistant`, {
      waitUntil: 'networkidle', timeout: 30000,
    });
    await page.waitForTimeout(PAUSE_MEDIUM);

    // ── Step 2: Click "Add New" ──
    const addNewBtn = await page.$('a.page-title-action, .wrap a[href*="post-new"]');
    if (addNewBtn) {
      await addNewBtn.click();
    } else {
      await page.goto(`${ADMIN_URL}/post-new.php?post_type=mcp_ai_assistant`, {
        waitUntil: 'networkidle',
      });
    }
    await page.waitForTimeout(PAUSE_MEDIUM);

    // ── Step 3: Fill assistant details ──
    // Title
    await page.fill('#title', 'Demo Support Assistant');
    await page.waitForTimeout(PAUSE_SHORT);

    // Description (if using classic editor)
    const contentArea = await page.$('#content, .wp-block-post-content, [data-testid="assistant-description"]');
    if (contentArea) {
      await contentArea.fill('A helpful AI assistant for customer support demonstrations.');
      await page.waitForTimeout(PAUSE_SHORT);
    }

    // ── Step 4: System Prompt (if Gutenberg meta box is visible) ──
    const systemPromptField = await page.$(
      '[data-testid="system-prompt"], #mcp_ai_system_prompt, textarea[name*="system_prompt"]'
    );
    if (systemPromptField) {
      await systemPromptField.fill(
        'You are a friendly customer support assistant. Answer questions clearly and concisely. ' +
        'If you do not know the answer, say so honestly.'
      );
      await page.waitForTimeout(PAUSE_SHORT);
    }

    // ── Step 5: Select Model (if dropdown exists) ──
    const modelSelect = await page.$(
      'select[name*="model"], [data-testid="model-select"], #mcp_ai_model'
    );
    if (modelSelect) {
      await modelSelect.selectOption({ label: 'GPT-4o' });
      await page.waitForTimeout(PAUSE_SHORT);
    }

    // ── Step 6: Assign Tools ──
    // Look for the Tools tab/panel
    const toolsTab = await page.$(
      '[data-testid="tools-tab"], .nav-tab-wrapper a[href*="tools"], button:has-text("Tools")'
    );
    if (toolsTab) {
      await toolsTab.click();
      await page.waitForTimeout(PAUSE_MEDIUM);

      // Search for tools
      const searchInput = await page.$(
        'input[type="search"], input[placeholder*="search"], input[placeholder*="Search"]'
      );
      if (searchInput) {
        await searchInput.fill('wp_post');
        await page.waitForTimeout(PAUSE_MEDIUM);
      }

      // Enable some tools (checkboxes)
      const checkboxes = await page.$$('input[type="checkbox"]:not(:checked)');
      for (let i = 0; i < Math.min(5, checkboxes.length); i++) {
        try {
          await checkboxes[i].check();
          await page.waitForTimeout(200);
        } catch (e) { /* skip if not interactable */ }
      }
      await page.waitForTimeout(PAUSE_SHORT);
    }

    // ── Step 7: Publish ──
    const publishBtn = await page.$('#publish, button.editor-post-publish-button, [data-testid="publish-button"]');
    if (publishBtn) {
      await publishBtn.click();
      await page.waitForTimeout(PAUSE_MEDIUM);
    } else {
      // Classic editor
      await page.click('#publish');
      await page.waitForTimeout(PAUSE_MEDIUM);
    }

    // ── Step 8: Verify — return to list and confirm the assistant appears ──
    await page.goto(`${ADMIN_URL}/edit.php?post_type=mcp_ai_assistant`, {
      waitUntil: 'networkidle',
    });
    await page.waitForTimeout(PAUSE_LONG);

    // ── End recording ──
    console.log(`✅ Video captured: ${path.join(OUT_DIR, VIDEO_FILE + '.webm')}`);

  } catch (error) {
    console.error('❌ Error during video capture:', error.message);
  } finally {
    await context.close(); // ← writes the .webm file
    await browser.close();
  }
})();
```

#### Step 1.4: Build remaining base video scripts

Each follows the same pattern as Step 1.3:

| Script | Admin URL / Interaction Target |
|---|---|
| `capture-demo-video-provider.js` | `admin.php?page=wp-mcp-ai-dashboard&tab=ai_providers` |
| `capture-demo-video-chat.js` | Frontend `/?page_id=<ID>` — send messages via chat input |
| `capture-demo-video-chat-tools.js` | Chat page — ask "search my site for recent posts" |
| `capture-demo-video-guest.js` | Incognito context → guest chat page |
| `capture-demo-video-tools-manager.js` | `admin.php?page=wp-mcp-ai-tools-manager` |
| `capture-demo-video-profession.js` | `post-new.php?post_type=mcp_ai_profession` |

### Phase 2: Pro Plugin Videos (Week 2)

**Goal:** Extend the pipeline to capture 8 Pro plugin videos.

**Key considerations:**
- Pro pages are JS-heavy (React-rendered). Need `waitUntil: 'networkidle'` + extra `waitForTimeout(3000)`.
- Pro addon activation requires `addons/pro/` directory present.
- Some Pro features depend on `JetEngine`, `WooCommerce`, `Elementor` — optional dependencies.

**Approach:** One script `bin/capture-demo-video-pro.js` that captures all 8 Pro tasks. Each task maps to a specific admin page:

```js
const PRO_TASKS = [
  { file: 'pro-dashboard',           url: 'nvoos-pro-dashboard' },
  { file: 'orchestration-workflow',  url: 'wp-mcp-ai-pro-orchestration' },
  { file: 'security-audit',          url: 'nvoos-pro-dashboard-audits' },
  { file: 'site-creator',            url: 'admin.php?page=wp-mcp-ai-site-creator' },
  { file: 'federation-setup',        url: 'wp-mcp-ai-mesh-settings' },
  { file: 'schedule-manager',        url: 'wp-mcp-ai-schedule-manager' },
  { file: 'workflow-builder',        url: 'wp-mcp-ai-pro-workflow-builder' },
  { file: 'blueprints',              url: 'wp-mcp-ai-blueprints' },
];
```

### Phase 3: Narration Pipeline (Week 3)

**Goal:** Add AI-generated voiceover narration to videos.

#### Step 3.1: Narration script format

Each video gets a `narration/<video-name>.txt` file:

```text
# add-assistant-tools.txt
# One line = one narration segment. Blank lines = pauses.

Welcome to NV oOS. In this video, we will create a new AI assistant and assign tools to it.

First, navigate to AI Assistants in the WordPress admin menu. This is where all your AI assistants live.

Click "Add New" to create your first assistant.

Give your assistant a name. This is how you will identify it later.

Write a system prompt. This defines how the AI behaves and responds to users. ...

Choose a model. GPT-4o is a great default for most use cases. ...

Now, let's assign tools. Open the Tools tab and search for the tools you want your assistant to use. ...

Check the boxes to enable tools. Your assistant can now search posts, manage users, and more. ...

Click Publish, and your assistant is ready. Let's verify it appears in the list. ...

That's it. Your AI assistant with tools is ready to use.
```

#### Step 3.2: TTS audio generation

**File:** `bin/generate-narration-audio.js`

```js
/**
 * Converts narration/*.txt files to narration/audio/*.mp3 via TTS API.
 *
 * Supported providers:
 *   - ElevenLabs     (ELEVENLABS_API_KEY)
 *   - OpenAI TTS     (OPENAI_API_KEY)
 *
 * Also writes narration/durations.json for timing synchronization.
 */

const VOICE_ID = process.env.TTS_VOICE_ID || '21m00Tcm4TlvDq8ikWAM'; // ElevenLabs "Rachel"
const PROVIDER = process.env.TTS_PROVIDER || 'openai'; // or 'elevenlabs'

async function generateSegmentAudio(text, voiceId) {
  switch (PROVIDER) {
    case 'elevenlabs':
      return generateElevenLabs(text, voiceId);
    case 'openai':
      return generateOpenAITTS(text);
    default:
      throw new Error(`Unknown TTS provider: ${PROVIDER}`);
  }
}
```

#### Step 3.3: Video-audio merge

**File:** `bin/merge-demo-video.js`

```js
/**
 * Merges silent Playwright .webm with narration .mp3 segments.
 *
 * Steps:
 *   1. Read durations.json for segment timing
 *   2. Generate silence gaps between segments
 *   3. FFmpeg concat: segment1.mp3 | silence | segment2.mp3 | silence | ...
 *   4. FFmpeg mux: combined_audio.mp3 + video.webm → final.mp4
 *
 * Usage:  node bin/merge-demo-video.js add-assistant-tools
 */
```

### Phase 4: CI/CD Integration (Week 4+)

**Goal:** Run video pipeline in GitHub Actions on every release.

```yaml
# .github/workflows/demo-videos.yml
name: Generate Demo Videos

on:
  workflow_dispatch:  # Manual trigger
  release:
    types: [published]

jobs:
  videos:
    runs-on: ubuntu-latest
    services:
      # Uses existing Docker Compose or GitHub service containers
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - run: sudo apt-get install -y ffmpeg
      - run: bash bin/capture-demo-videos.sh
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
          ELEVENLABS_API_KEY: ${{ secrets.ELEVENLABS_API_KEY }}
      - uses: actions/upload-artifact@v4
        with:
          name: demo-videos
          path: docs/videos/**/*.mp4
```

---

## Docker Integration

### Startup Flow

```
docker compose up -d          # Start WordPress + MySQL
    │
    ├─ wordpress container     # Apache/PHP on port 8000
    ├─ db container            # MySQL 8.0
    └─ (wp-cli on-demand)      # docker compose run --rm wp-cli ...
    │
    ▼
WordPress auto-installs (if first run)
    │
    ▼
capture-demo-videos.sh runs WP-CLI:
    ├─ core install (if needed)
    ├─ plugin activate mcp-ai-wpoos
    ├─ plugin activate mcp-ai-wpoos-pro (if addons/pro/ exists)
    ├─ option update wp_mcp_ai_openai_api_key ...
    ├─ post create (test page with shortcode)
    └─ post create (sample content for search tools)
    │
    ▼
Node.js Playwright scripts connect to http://localhost:8000
    │
    ├─ Headless Chromium navigates admin pages
    ├─ recordVideo captures everything
    └─ context.close() → .webm written
    │
    ▼
FFmpeg optimization → .mp4 output
```

### Key Docker-Specific Considerations

1. **Network:** Playwright scripts run on the host, connect to `http://localhost:8000` (port mapped in `docker-compose.yml`).
2. **Volume mount:** Plugin code at `.` is mounted at `/var/www/html/wp-content/plugins/mcp-ai-wpoos` — live edits are reflected immediately.
3. **WP-CLI profile:** The `wp-cli` service uses `profiles: [tools]` — it doesn't auto-start. Use `docker compose run --rm wp-cli <command>`.
4. **Reset:** `docker compose down -v && docker compose up -d` gives a clean WordPress install.
5. **Chrome in Docker (if running Playwright inside a container):** Use `chromium.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] })` when Playwright runs inside Docker. For Phase 1, Playwright runs on the host so this is not needed.

---

## Quality Standards

### Video Standards

| Property | Requirement |
|---|---|
| Resolution | 1920×1080 (Full HD) |
| Frame rate | 30 fps (default) |
| Codec | H.264 (`.mp4`) |
| Audio | AAC 128kbps (if narrated) |
| Duration | 60–180 seconds per task |
| Mouse cursor | Visible (default in headed mode; for headless, add `await page.mouse.move()` calls to show intent) |
| Click annotations | Phase 2: use Playwright `show: { actions }` option |
| Sensitive data | Never show real API keys, emails, or domains on screen |
| File size target | < 20 MB per video (optimize with CRF 28) |

### Script Standards

- All scripts are standalone (no test runner dependency).
- Each script does ONE task (Unix theory).
- Scripts are idempotent — re-running produces the same output.
- Failure in one script does not block others (`|| warn "FAILED"` pattern).
- All scripts use `#!/usr/bin/env node` shebang.
- JSDoc header block describes purpose, usage, prerequisites, outputs.
- Timeout handling: wrap all `page.goto()` in try-catch with graceful skip.

### Commit Standards

- One commit per new script file.
- Commit message format: `Add demo video script: <task-name>`
- Output `.mp4` / `.webm` files are **gitignored** (too large). They live as artifacts.

---

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Playwright selectors break when plugin UI changes | High | Medium | Use data-testid attributes if available; fall back to broad selectors; re-run pipeline on each release |
| AI provider API key missing → chat videos fail | Medium | High | Skip chat-dependent videos if `OPENAI_API_KEY` not set; use mock responses for offline capture |
| Pro addon not available in some environments | Medium | Low | Detect `addons/pro/` and skip gracefully |
| FFmpeg not installed → .webm not converted to .mp4 | Medium | Low | .webm is playable in most browsers; add FFmpeg to install instructions |
| CI runner disk space for video artifacts | Low | Medium | Optimize videos aggressively (CRF 30 for CI); use artifact retention policies |
| Plugin JS not loaded → blank pages captured | Medium | Medium | Always use `waitUntil: 'networkidle'` + extra `waitForTimeout` for JS-heavy pages |
| Docker port 8000 already in use | Low | Low | Allow override via `BASE_URL` env var |
| Slow CI runner → video generation times out | Low | Medium | Split video capture into parallel jobs; increase timeout |

---

## Open Questions

1. **Where should Playwright run?** Host machine (Phase 1) vs. in a Docker container alongside WordPress (Phase 4 CI)? The host-machine approach is simpler for development. For CI, adding a Playwright service container to `docker-compose.yml` would be cleaner.

2. **TTS provider?** ElevenLabs has the best voice quality but requires a paid API key. OpenAI TTS is simpler if already using their API. Edge TTS (`edge-tts` Python package) is free but lower quality. **Recommendation:** Start with OpenAI TTS (same API key as the plugin).

3. **Video hosting?** Where will these videos live? Options:
   - GitHub Releases (artifacts)
   - YouTube unlisted playlist
   - Plugin website (`nvdigitalsolutions.com/wpoos`)
   - WordPress.org plugin page (limited uploads)
   **Recommendation:** YouTube unlisted for sharing; GitHub Releases for archiving.

4. **Should videos be silent or narrated?** Phase 1 produces silent videos (good enough for internal testing). Phase 3 adds narration (good for public-facing demos). **Recommendation:** Ship Phase 1 first, gather feedback, then add narration.

5. **Should we gitignore the output videos?** Yes. `.webm` and `.mp4` files in `docs/videos/base/` and `docs/videos/pro/` should be in `.gitignore`. They are build artifacts, not source code. Add exception for a sample thumbnail `.png` per video if needed.

6. **Pro video script: one file or one-per-task?** One script (`capture-demo-video-pro.js`) is simpler for Phase 2. If individual Pro videos need different setup/teardown, split into per-task scripts later.

---

## Summary: Getting Started Today

```bash
# 1. Ensure Docker is running
docker compose up -d

# 2. Set your API key
export OPENAI_API_KEY="sk-proj-..."

# 3. Create the video helpers directory
mkdir -p bin/video-helpers docs/videos/base docs/videos/pro

# 4. Create the first video script
#    → bin/capture-demo-video-assistant.js (as specified in Phase 1.3)

# 5. Create the orchestrator
#    → bin/capture-demo-videos.sh (as specified in Phase 1.2)

# 6. Run it
bash bin/capture-demo-videos.sh

# 7. Check output
ls -lh docs/videos/base/
```

---

*End of plan. Next step: begin Phase 1 implementation.*
