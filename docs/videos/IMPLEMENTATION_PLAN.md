# NV oOS Demo Video Pipeline — Comprehensive Implementation Plan

**Status:** Updated — 2026-07-14
**Author:** AI Agent (Zed)
**Scope:** Automated generation of narrated feature demo videos for Base + Pro plugin, driven by Docker + Playwright + TTS + FFmpeg

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Industry Research & Best Practices (2024–2026)](#industry-research--best-practices-20242026)
3. [Architecture Decision](#architecture-decision)
4. [Current State Assessment](#current-state-assessment)
5. [Target Video Catalog](#target-video-catalog)
6. [File Structure & Deliverables](#file-structure--deliverables)
7. [Implementation Phases](#implementation-phases)
    - [Phase 0: Hardening (Week 1)](#phase-0-hardening-foundation--selector-stability-week-1)
    - [Phase 1: Silent Pipeline — Complete & Polish (Week 1–2)](#phase-1-silent-pipeline--complete--polish-week-12)
    - [Phase 2: Pro Plugin Videos — Interactions (Week 2)](#phase-2-pro-plugin-videos--interactions-week-2)
    - [Phase 3: Narration Pipeline (Week 3)](#phase-3-narration-pipeline-week-3)
    - [Phase 4: Polish & Production (Week 4)](#phase-4-polish--production-week-4)
    - [Phase 5: CI/CD Integration (Week 5)](#phase-5-cicd-integration-week-5)
8. [Quality Standards](#quality-standards)
9. [Risk Register](#risk-register)
10. [Open Questions](#open-questions)
11. [Quick Start](#quick-start)

---

## Executive Summary

Create an end-to-end automated pipeline that:
1. Spins up a **Dockerized WordPress** environment with the NV oOS plugin
2. Configures the plugin via **WP-CLI** (activation, API keys, test data)
3. Produces **silent `.webm` demo videos** via Playwright `recordVideo` (Phase 1–2)
4. Generates **AI-narrated `.mp4` videos** via OpenAI TTS + FFmpeg merge (Phase 3)
5. Adds **visual polish**: chapter markers, action highlights, intro/outro cards (Phase 4)
6. Runs on **every release** via GitHub Actions CI (Phase 5)

**Core principle:** The narration script + Playwright script = single source of truth. Change the feature, update the script, re-run the pipeline. Videos are build artifacts, never manually recorded.

**Technology stack:** Docker Compose → WordPress 6.9 → Playwright Library API (`recordVideo` + `page.screencast` in v1.59) → OpenAI TTS → FFmpeg → `.mp4` output.

---

## Industry Research & Best Practices (2024–2026)

### Key References Applied to This Plan

| Source | Key Insight | Applied In |
|--------|------------|------------|
| **PurpleOwl — "We Automated Our Product Walkthrough Video"** (Mar 2026) | Narration script as single source of truth; `durations.json` timing manifest; ElevenLabs ID passthrough for voice consistency; FFmpeg concat with silence gaps; `-shortest` safety net | Phase 3 (narration pipeline architecture) |
| **playwright-recast (npm)** — DEV.to (Mar 2026) | Lazy/immutable pipeline API; `speedUp(duringIdle)` for dead time; `hideSteps()` to skip login; multi-language from one trace | Phase 3 (TTS generation design), Phase 4 (speed controls) |
| **Justin Abrahms — "Generating Demo Videos with Playwright"** (Feb 2026) | CSS-injected fake cursor for headless; `page.setContent()` title cards; FFmpeg background music with audio ducking | Phase 4 (cursor, intro/outro cards, background music) |
| **Playwright v1.59 `page.screencast` API** (May 2026) | `showActions()` for automatic interaction annotations; `showChapter()` for segment markers; `showOverlay()` for HTML overlays; `onFrame` for real-time streaming; agentic "video receipts" | Phase 4 (visual polish), Phase 5 (CI integration) |
| **Playwright Official Docs — Videos** | `video: 'retain-on-failure'` for CI (never `'on'`); `recordVideo` requires `context.close()`; 640×480 reduces file size ~60% | Phase 5 (CI config) |
| **BrowserStack — "15 Playwright Best Practices 2026"** | `data-testid` attributes for selector stability; role-based locators over CSS; no hardcoded waits | Phase 0 (selector hardening) |

### What the Industry Has Converged On

1. **Narration-first workflow.** Write the spoken script first. Everything else — browser actions, TTS, timing — derives from it. (PurpleOwl, playwright-recast)
2. **Playwright as the recording engine.** `recordVideo` for full-session captures; `page.screencast` (v1.59+) for focused recordings with annotations. No one uses OBS/screen recorders for this anymore.
3. **FFmpeg as the post-production layer.** WebM → H.264 MP4 conversion, audio concatenation, video+audio mux, background music ducking. Universal tool across all examples.
4. **TTS over human voiceover.** ElevenLabs for highest quality (paid); OpenAI TTS for simplicity (same API key); Edge TTS for free fallback.
5. **`data-testid` is non-negotiable.** Every article, every guide, every Reddit thread converges on this: stable selectors demand dedicated test attributes in the UI. Multi-selector fallback patterns are a stopgap, not a strategy.
6. **CI as the execution environment.** Videos are build artifacts. They're regenerated on release, never manually recorded. GitHub Actions is the standard.

### FFmpeg Encoding Reference

| Use Case | Command | Notes |
|----------|---------|-------|
| **Silent demo (default)** | `-c:v libx264 -preset fast -crf 28 -an` | ~2–5 MB/min at 1080p |
| **Narrated demo** | `-c:v libx264 -preset fast -crf 28 -c:a aac -b:a 192k` | AAC 192kbps for clear voice |
| **CI draft** | `-c:v libx264 -preset ultrafast -crf 35` | 5× faster, larger files; discard after review |
| **Production quality** | `-c:v libx264 -preset medium -crf 23 -c:a aac -b:a 256k` | For public-facing videos |
| **Web streaming** | Add `-movflags +faststart` | Enables progressive download |

---

## Architecture Decision

### Phase 1–2: Playwright Library API (standalone scripts)

**Decision:** Build video scripts as standalone Node.js scripts using `require('playwright')` (library API).

**Why not `@playwright/test`?**
- `@playwright/test` has `video: 'on'` in config, but it's per-test and requires the test runner.
- Standalone scripts give full control over when recording starts/stops, viewport size, and annotations.
- Consistent with existing `bin/*.js` screenshot scripts.
- One command: `node bin/capture-demo-video-assistant.js`

**Why not `playwright-recast` (npm library)?**
- Adds an external dependency. Our pipeline is already built on the Playwright library API.
- `playwright-recast` is v0.1.0 (as of Mar 2026) — too immature for production dependency.
- We can adopt its design patterns (lazy pipeline, speed controls, multi-language branching) in our own code.

### Phase 3: Narration Pipeline Architecture

Based directly on the PurpleOwl pattern, adapted for our WordPress environment:

```
narration/<video-name>.txt          ← Single source of truth
        │
        ▼
bin/generate-narration-audio.js     ← OpenAI TTS → MP3 per segment
        │                               + durations.json manifest
        ▼
docs/videos/narration/audio/<name>/ ← seg-0.mp3, seg-1.mp3, ...
        │                               durations.json
        ▼
bin/merge-demo-video.js             ← FFmpeg: concat audio + silence
        │                               → mux with silent .webm
        ▼
docs/videos/base/<name>.mp4         ← Final narrated video
```

**Key design decisions:**
- **OpenAI TTS as primary provider.** Same API key as the plugin's AI provider. Simpler than adding an ElevenLabs dependency.
- **One narration `.txt` file per video.** Line = spoken segment. Blank line = pause. `#` comments ignored.
- **`durations.json` as timing bridge.** Generated by TTS script. Consumed by merge script. Also consumable by future video scripts for action-timing sync.
- **Silence gaps between segments:** Configurable via `GAP_MS` env var (default 500 ms).

### Phase 4: Visual Polish Layer

Using Playwright v1.59's `page.screencast` API where available, falling back to `page.evaluate()` DOM injection:

| Feature | v1.59 API | Fallback | Applied To |
|---------|-----------|----------|------------|
| Action annotations | `page.screencast.showActions()` | CSS overlay via `page.evaluate()` | All interaction steps |
| Chapter markers | `page.screencast.showChapter()` | `showOverlay()` with HTML | Video section transitions |
| Mouse cursor | N/A (headless) | CSS circle + animation injection | All videos |
| Intro/outro cards | `page.screencast.showOverlay()` | `page.setContent()` | Start/end of each video |

---

## Current State Assessment

### What's Built (Commit `9776d378e`)

| Component | Status | Notes |
|-----------|--------|-------|
| **Orchestrator** `capture-demo-videos.sh` | ✅ Complete | Docker → WP-CLI → dispatch → FFmpeg optimize |
| **Video helpers** (`wp-admin.js`, `wp-api.js`, `video-utils.js`) | ✅ Complete | CommonJS ports of E2E test fixtures |
| **Base video scripts** (7 scripts) | ✅ Complete | assistant, provider, chat, chat-tools, guest, tools-manager, profession |
| **Pro video script** (1 script, 8 tasks) | ⚠️ Partial | Navigates pages but no interactions (bare `page.goto` + scroll) |
| **Narration merge** `merge-demo-video.js` | ✅ Complete | FFmpeg audio concat + video mux with `durations.json` |
| **Narration text** | ⚠️ 1 of 15 | Only `add-assistant-tools.txt` exists |
| **TTS generation** `generate-narration-audio.js` | ❌ Missing | Referenced by merge script but never built |
| **CI/CD workflow** `.github/workflows/demo-videos.yml` | ❌ Missing | Planned but not implemented |
| **`data-testid` attributes in plugin UI** | ❌ Missing | Scripts rely on fragile multi-selector fallbacks |
| **Intro/outro cards** | ❌ Missing | No title cards or outro screens |
| **Mouse cursor visualization** | ❌ Missing | Headless captures have invisible cursor |
| **Background music** | ❌ Missing | No ambient audio support |

### Critical Gaps

1. **No TTS generation script.** `merge-demo-video.js` expects `durations.json` and audio segments that don't exist. The narration pipeline is scaffolded but non-functional.
2. **Selector fragility.** Every script uses `tryClick(page, [5+ selectors])` fallback patterns. Industry consensus is `data-testid` attributes in the UI. Without them, every UI change breaks videos.
3. **Pro videos show no interactions.** Just page loads and scrolls. No clicking, typing, or feature demonstration.
4. **No visual polish.** Silent, cursorless, annotation-free raw captures. Not suitable for public-facing marketing.

---

## Target Video Catalog

### Phase 1: Core Base Plugin Tasks (7 videos)

| # | Video File | User Story | Key Interactions | Duration |
|---|-----------|------------|-----------------|----------|
| 1 | `add-assistant-tools.mp4` | Create an AI assistant and assign tools | Navigate CPT → Add New → title/system prompt/model → Tools tab → search/enable tools → Publish → verify | 90–120s |
| 2 | `configure-ai-provider.mp4` | Connect OpenAI/Gemini/Ollama | Settings → Providers tab → enter API key → select default model → test connection → save | 60–90s |
| 3 | `chat-conversation.mp4` | Chat with streaming responses | Frontend → type message → send → streaming response → follow-up → context awareness | 90–120s |
| 4 | `chat-tool-execution.mp4` | AI uses tools to fetch real data | Chat → "search my site for posts" → tool indicator → results from wp_post_search | 90–120s |
| 5 | `guest-mode-chat.mp4` | Anonymous visitor chat | Incognito → guest page → token generation → message exchange → history in localStorage | 90–120s |
| 6 | `manage-tools-presets.mp4` | Enable/disable tools, create presets | Tools Manager → browse categories → search → toggle tools → create preset → assign | 90–120s |
| 7 | `create-profession.mp4` | Create a profession template | Add New → name/description → system prompt template → assigned preset → publish → grid view | 60–90s |

### Phase 2: Pro Plugin Tasks (8 videos)

| # | Video File | User Story | Key Interactions | Duration |
|---|-----------|------------|-----------------|----------|
| 8 | `pro-dashboard.mp4` | Analytics, monitoring, usage stats | Dashboard → scan metrics → usage charts → quick actions | 60–90s |
| 9 | `orchestration-workflow.mp4` | Chain multiple AI agents | Orchestration page → create workflow → add agents → define triggers → test run | 90–120s |
| 10 | `security-audit.mp4` | Scan site, see findings | Audits page → run scan → review findings → apply fixes | 90–120s |
| 11 | `site-creator.mp4` | Generate site from template | Site Creator → select template → configure options → deploy → preview | 90–120s |
| 12 | `federation-setup.mp4` | Connect remote sites | Mesh settings → add remote → authenticate → verify cross-site tools | 60–90s |
| 13 | `schedule-manager.mp4` | Schedule recurring AI tasks | Schedule Manager → create schedule → set recurrence → assign agent → activate | 60–90s |
| 14 | `workflow-builder.mp4` | Visually build AI pipelines | Workflow Builder → drag nodes → connect steps → configure params → save | 90–120s |
| 15 | `blueprints.mp4` | Export/import assistant configs | Blueprints → export assistant → download JSON → import on another site → verify | 60–90s |

### Stretch Goals (Post-Phase 5)

- Mobile-responsive viewport (375×667)
- Error handling scenarios (invalid API key, rate limiting, disconnect)
- File upload demonstration (PDF/image in chat)
- Elementor widget integration (requires Elementor in Docker)
- WooCommerce tools demo (requires WooCommerce)
- REST API authentication flow (nonce, bearer, guest token)
- Multi-language variants (one narration → multiple TTS languages)

---

## File Structure & Deliverables

```
# ── Existing (committed) ──

bin/
├── capture-demo-videos.sh                  ✅ Orchestrator
├── capture-demo-video-assistant.js         ✅ Video #1
├── capture-demo-video-provider.js          ✅ Video #2
├── capture-demo-video-chat.js              ✅ Video #3
├── capture-demo-video-chat-tools.js        ✅ Video #4
├── capture-demo-video-guest.js             ✅ Video #5
├── capture-demo-video-tools-manager.js     ✅ Video #6
├── capture-demo-video-profession.js        ✅ Video #7
├── capture-demo-video-pro.js               ⚠️  Video #8–15 (page loads only)
├── merge-demo-video.js                     ✅ Audio-video merger
└── video-helpers/
    ├── wp-admin.js                         ✅ Login, navigation, REST
    ├── wp-api.js                           ✅ MCP API helpers
    └── video-utils.js                      ✅ Config, context, FFmpeg

docs/videos/
├── IMPLEMENTATION_PLAN.md                  ✅ This file
├── README.md                               ✅ Quick start guide
├── narration/
│   └── add-assistant-tools.txt             ⚠️  Only 1 of 15
├── base/                                   📁 Output (gitignored)
└── pro/                                    📁 Output (gitignored)

# ── To Be Created ──

bin/
├── generate-narration-audio.js             ❌ Phase 3: Text → TTS MP3s + durations.json
├── video-helpers/
│   ├── narration-utils.js                  ❌ Phase 3: Shared narration helpers
│   ├── cursor-utils.js                     ❌ Phase 4: Fake cursor injection
│   ├── annotation-utils.js                 ❌ Phase 4: Screencast/chapter helpers
│   └── card-utils.js                       ❌ Phase 4: Intro/outro card templates
└── utils/
    └── video-selectors.js                  ❌ Phase 0: Centralized selector registry

docs/videos/
├── CATALOG.md                              ❌ Phase 3: Video catalog with status, duration, links
└── narration/
    ├── configure-ai-provider.txt           ❌
    ├── chat-conversation.txt               ❌
    ├── chat-tool-execution.txt             ❌
    ├── guest-mode-chat.txt                 ❌
    ├── manage-tools-presets.txt            ❌
    ├── create-profession.txt               ❌
    ├── pro-dashboard.txt                   ❌
    ├── orchestration-workflow.txt          ❌
    ├── security-audit.txt                  ❌
    ├── site-creator.txt                    ❌
    ├── federation-setup.txt                ❌
    ├── schedule-manager.txt                ❌
    ├── workflow-builder.txt                ❌
    └── blueprints.txt                      ❌

.github/workflows/
└── demo-videos.yml                         ❌ Phase 5: CI pipeline on release
```

---

## Implementation Phases

### Phase 0: Hardening — Foundation & Selector Stability (Week 1)

**Goal:** Make the existing pipeline robust enough that UI changes don't break videos. This is a prerequisite for all subsequent phases.

#### Step 0.1: Audit plugin UI for `data-testid` coverage

Run each video script and catalog every selector that fails. Produce a list of UI elements that need `data-testid` attributes:

```bash
# Run all scripts in dry-run/verbose mode, collecting selector misses
node bin/capture-demo-video-assistant.js --dry-run --verbose 2>&1 | grep "⚠️.*not found"
```

**Output:** `docs/videos/data-testid-gap-report.md` — a prioritized list of UI components that need test IDs.

#### Step 0.2: Add `data-testid` attributes to plugin UI

For every element the video scripts interact with, add a `data-testid` attribute to the PHP/JSX template:

```php
<!-- Before -->
<button class="button button-primary" id="publish">Publish</button>

<!-- After -->
<button class="button button-primary" id="publish"
        data-testid="publish-button">Publish</button>
```

**Priority order:** (1) Buttons/actions, (2) Input fields, (3) Navigation tabs, (4) Content areas, (5) Status indicators.

**Naming convention:** `{component}-{element}` — e.g., `assistant-title-input`, `tools-search-input`, `chat-send-button`.

#### Step 0.3: Create centralized selector registry

**File:** `bin/utils/video-selectors.js`

```js
/**
 * Centralized selector registry for demo video scripts.
 *
 * Every selector used by video scripts lives here, organized by page/feature.
 * Scripts import named exports instead of defining inline selector arrays.
 *
 * When the UI changes, update the selector HERE — not in each script.
 */

const SELECTORS = {
  // ── WordPress Admin (global) ──
  admin: {
    loginForm: { user: '#user_login', pass: '#user_pass', submit: '#wp-submit' },
    adminBar: '#wpadminbar',
    addNewButton: ['a.page-title-action', '[data-testid="add-new-button"]'],
    publishButton: ['#publish', '[data-testid="publish-button"]'],
  },

  // ── Assistant Editor ──
  assistant: {
    titleInput: ['#title', '[data-testid="assistant-title-input"]'],
    contentArea: ['#content', '[data-testid="assistant-description"]'],
    systemPrompt: ['[data-testid="system-prompt"]', 'textarea[name*="system_prompt"]'],
    modelSelect: ['[data-testid="model-select"]', 'select[name*="model"]'],
    toolsTab: ['[data-testid="tools-tab"]', '.nav-tab-wrapper a[href*="tools"]'],
    toolSearchInput: ['[data-testid="tools-search-input"]', 'input[type="search"]'],
    toolCheckboxes: ['[data-testid="tool-toggle"]', 'input[type="checkbox"]'],
  },

  // ── Chat UI ──
  chat: {
    input: ['[data-testid="chat-input"]', 'textarea[placeholder*="Message"]'],
    sendButton: ['[data-testid="send-button"]', 'button[type="submit"]'],
    response: ['[data-testid="assistant-message"]', '.mcp-ai-message-assistant'],
    toolIndicator: ['[data-testid="tool-execution"]', '.mcp-ai-tool-call'],
    guestBadge: ['[data-testid="guest-badge"]', '.guest-badge'],
  },

  // ── ... per-page sections for all 15 features ──
};

module.exports = { SELECTORS };
```

#### Step 0.4: Refactor existing scripts to use centralized selectors

Replace inline selector arrays in all 7 base scripts + 1 pro script with imports from `video-selectors.js`:

```js
// Before (each script duplicates this)
const CHAT_INPUT_SELECTORS = [
    '[data-testid="chat-input"]',
    'textarea[placeholder*="message" i]',
    // ... 6 more
];

// After (one import)
const { SELECTORS } = require('../utils/video-selectors');
// Usage: await findElement(page, SELECTORS.chat.input);
```

#### Step 0.5: Add Playwright v1.59+ detection and screencast capability flag

Add a feature detection helper so scripts can use `page.screencast` when available:

```js
// video-helpers/screencast-utils.js
function supportsScreencast(page) {
    return typeof page.screencast !== 'undefined';
}

async function startAnnotatedRecording(page, options = {}) {
    if (supportsScreencast(page)) {
        await page.screencast.start({ path: options.path, size: options.size });
        await page.screencast.showActions({ position: 'top-right', duration: 800 });
    }
    // Fallback: rely on recordVideo in browser context
}
```

---

### Phase 1: Silent Pipeline — Complete & Polish (Week 1–2)

**Goal:** 7 base videos that are robust, reliable, and visually clear — even without narration.

#### Step 1.1: Standardize script template

Create a canonical script template at `bin/_video-template.js` that all scripts follow:

```js
#!/usr/bin/env node
/**
 * NV oOS Demo Video — {TASK NAME}
 *
 * User Story: {ONE SENTENCE}
 * Duration:    {TARGET SECONDS}
 *
 * Usage:   node bin/capture-demo-video-{slug}.js
 * Prereq:  docker compose up -d
 * Output:  docs/videos/base/{slug}.webm
 */

const { chromium } = require('playwright');
const { WPAdmin } = require('./video-helpers/wp-admin');
const { VIDEO_CONFIG, PAUSE } = require('./video-helpers/video-utils');
const { SELECTORS } = require('./utils/video-selectors');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIDEO_CONFIG.viewport,
        recordVideo: { dir: OUT_DIR, size: VIDEO_CONFIG.size },
    });
    const page = await context.newPage();
    const admin = new WPAdmin(page);

    try {
        // 1. Login
        // 2. Navigate to feature
        // 3. Perform interactions
        // 4. Verify result
        // 5. Closing shot
    } finally {
        await context.close();
        await browser.close();
    }
})();
```

#### Step 1.2: Add fake cursor visualization

Following Justin Abrahms' pattern, inject a visible mouse cursor for headless recordings:

```js
// video-helpers/cursor-utils.js
async function injectCursor(page) {
    await page.evaluate(() => {
        const cursor = document.createElement('div');
        cursor.id = '__demo_cursor';
        cursor.style.cssText = `
            position: fixed; pointer-events: none; z-index: 999999;
            width: 20px; height: 20px; border-radius: 50%;
            background: rgba(255, 50, 50, 0.5);
            border: 2px solid rgba(255, 50, 50, 0.8);
            transition: left 0.15s ease-out, top 0.15s ease-out;
        `;
        document.body.appendChild(cursor);
    });
}

async function moveCursorTo(page, selector) {
    const box = await page.locator(selector).boundingBox();
    if (!box) return;
    await page.evaluate(({ x, y }) => {
        const cursor = document.getElementById('__demo_cursor');
        if (cursor) {
            cursor.style.left = (x + 10) + 'px';
            cursor.style.top = (y + 10) + 'px';
        }
    }, { x: box.x, y: box.y });
}
```

#### Step 1.3: Verify all 7 base scripts against fresh Docker environment

Run the full pipeline from a clean state and fix any broken selectors or timing issues:

```bash
docker compose down -v
bash bin/capture-demo-videos.sh
```

Each script must produce a viewable `.webm`. Failures should be graceful (warn + continue, not crash).

---

### Phase 2: Pro Plugin Videos — Interactions (Week 2)

**Goal:** 8 Pro videos that actually demonstrate features, not just page loads.

#### Step 2.1: Audit Pro pages for interactive elements

For each Pro admin page, document the available interactive elements (buttons, forms, charts, toggles). Some pages may be read-only dashboards — that's fine, but document it.

#### Step 2.2: Add interactions to each Pro task

Update `capture-demo-video-pro.js` with `extraActions` functions for each task:

```js
const PRO_TASKS = [
    {
        file: 'pro-dashboard',
        label: 'Pro Dashboard Overview',
        url: 'nvoos-pro-dashboard',
        extraActions: async (page) => {
            // Click between dashboard tabs
            await page.click('[data-testid="tab-analytics"]');
            await page.waitForTimeout(PAUSE.MEDIUM);
            await page.click('[data-testid="tab-usage"]');
            await page.waitForTimeout(PAUSE.MEDIUM);
            // Hover over a chart to show tooltip
            await page.hover('[data-testid="chart-token-usage"]');
            await page.waitForTimeout(PAUSE.MEDIUM);
        },
    },
    {
        file: 'security-audit',
        label: 'Run Security Audit',
        url: 'nvoos-pro-dashboard-audits',
        extraActions: async (page) => {
            await page.click('[data-testid="start-audit-button"]');
            await page.waitForTimeout(PAUSE.LONG); // wait for scan
            await page.waitForSelector('[data-testid="audit-results"]', { timeout: 60000 });
        },
    },
    // ... remaining 6 tasks
];
```

#### Step 2.3: Split Pro script if individual tasks need unique setup

If any Pro task requires significant setup (creating test data, configuring services), split it into its own script following the Phase 1 template pattern.

---

### Phase 3: Narration Pipeline (Week 3)

**Goal:** All 15 videos narrated with AI voiceover. One command: silent `.webm` → narrated `.mp4`.

#### Step 3.1: Build the TTS generation script

**File:** `bin/generate-narration-audio.js`

```js
#!/usr/bin/env node
/**
 * NV oOS — Narration Audio Generator
 *
 * Converts narration/*.txt files to individual MP3 segments via OpenAI TTS.
 * Produces a durations.json manifest for timing synchronization with merge-demo-video.js.
 *
 * Usage:
 *   node bin/generate-narration-audio.js <video-name>
 *   node bin/generate-narration-audio.js --all
 *   node bin/generate-narration-audio.js add-assistant-tools --force
 *
 * Environment:
 *   OPENAI_API_KEY       Required. Used for TTS API calls.
 *   TTS_VOICE            OpenAI voice: alloy, echo, fable, onyx, nova, shimmer (default: nova)
 *   TTS_MODEL            Model: tts-1 (faster) or tts-1-hd (higher quality). Default: tts-1
 *   GAP_MS               Silence between segments in ms (default: 500)
 */

const fs = require('fs');
const path = require('path');
const OpenAI = require('openai');

const REPO_ROOT = path.resolve(__dirname, '..');
const NARRATION_DIR = path.join(REPO_ROOT, 'docs', 'videos', 'narration');
const AUDIO_DIR = path.join(NARRATION_DIR, 'audio');
const VOICE = process.env.TTS_VOICE || 'nova';
const MODEL = process.env.TTS_MODEL || 'tts-1';
const GAP_MS = parseInt(process.env.GAP_MS || '500', 10);

if (!process.env.OPENAI_API_KEY) {
    console.error('❌ OPENAI_API_KEY environment variable is required.');
    process.exit(1);
}

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

/**
 * Parse a narration .txt file into an array of segment objects.
 *
 * Format:
 *   - One line = one spoken segment.
 *   - Blank lines are ignored (they become silence gaps in the merge step).
 *   - Lines starting with # are comments (ignored).
 *
 * @param {string} filePath
 * @returns {{ id: string, text: string }[]}
 */
function parseNarrationScript(filePath) {
    const content = fs.readFileSync(filePath, 'utf-8');
    const lines = content.split('\n')
        .map(l => l.trim())
        .filter(l => l.length > 0 && !l.startsWith('#'));

    return lines.map((text, i) => ({
        id: `seg-${String(i).padStart(2, '0')}`,
        text,
    }));
}

/**
 * Generate TTS audio for a single segment.
 *
 * @param {string} text
 * @param {object} options
 * @returns {Promise<{ buffer: Buffer, durationMs: number }>}
 */
async function generateSegmentAudio(text) {
    const response = await openai.audio.speech.create({
        model: MODEL,
        voice: VOICE,
        input: text,
        response_format: 'mp3',
    });

    const buffer = Buffer.from(await response.arrayBuffer());

    // Measure duration using mp3-duration or ffprobe fallback
    // (simplified: OpenAI returns Content-Length; we estimate ~16 KB/s for tts-1)
    const estimatedDurationMs = Math.round((buffer.length / 16000) * 1000);

    return { buffer, durationMs: estimatedDurationMs };
}

async function generateForVideo(videoName) {
    // ... (main logic: parse script → generate audio per segment → write .mp3 files → write durations.json)
}
```

**Critical design: `durations.json` format**

```json
{
    "seg-00": 8420,
    "seg-01": 6180,
    "seg-02": 5930,
    "seg-03": 3210
}
```

This is what `merge-demo-video.js` already reads. The merge script expects this format at `narration/audio/<video-name>/durations.json`.

#### Step 3.2: Write narration scripts for all 15 videos

Following the existing `add-assistant-tools.txt` format:

```text
# <video-name>.txt
# One line = one spoken segment. Blank lines = pauses. # = comments.
# Target: ~90 seconds

Welcome to NV oOS. In this video, we will configure your AI provider connection.

Navigate to Settings and open the AI Providers tab. This is where you manage all your AI service connections.

Enter your OpenAI API key. You can generate one from the OpenAI dashboard at platform.openai.com.

Select your default model. GPT-4o is recommended for most use cases.

Click Test Connection to verify your API key is valid. You should see a green success indicator.

Save your settings. Your AI provider is now connected and ready to use.

That's it. The plugin is now configured to generate AI responses using your OpenAI account.
```

**Writing guidelines:**
- **Conversational, not robotic.** Read it aloud. If it sounds like a script, rewrite it.
- **One action per segment.** Don't pack multiple steps into one sentence.
- **80–120 words per 60 seconds.** TTS ~150 WPM.
- **No filler.** Every sentence conveys information. Cut "you know," "basically," "just."
- **Active voice.** "Click the Publish button" not "The Publish button should be clicked."

#### Step 3.3: Update orchestrator to include narration step

Add a `generate_narration()` function to `capture-demo-videos.sh`:

```bash
generate_narration() {
    if [ -z "${OPENAI_API_KEY:-}" ]; then
        warn "OPENAI_API_KEY not set — skipping narration generation."
        warn "Videos will remain silent."
        return
    fi

    info "Generating narration audio..."
    node bin/generate-narration-audio.js --all
}
```

And a `merge_videos()` step after video capture:

```bash
merge_videos() {
    if ! command -v ffmpeg &>/dev/null; then
        warn "FFmpeg not found — skipping video merge."
        return
    fi

    info "Merging video + narration..."
    node bin/merge-demo-video.js --all
}
```

#### Step 3.4: Add narration helpers module

**File:** `bin/video-helpers/narration-utils.js`

Shared utilities for narration scripts: text parsing, duration estimation, OpenAI TTS wrapper, ElevenLabs fallback, Edge TTS free fallback.

---

### Phase 4: Polish & Production (Week 4)

**Goal:** Videos that look professional — not raw test recordings.

#### Step 4.1: Intro and outro cards

Using `page.setContent()` to render HTML title cards before/after the main content:

```js
// video-helpers/card-utils.js
async function showIntroCard(page, { title, subtitle, duration = 3000 }) {
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head><style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                display: flex; align-items: center; justify-content: center;
                height: 100vh; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            }
            .card { text-align: center; color: #fff; }
            .card h1 { font-size: 2.8rem; margin-bottom: 1rem; font-weight: 700; }
            .card p { font-size: 1.4rem; color: rgba(255,255,255,0.7); }
            .logo { font-size: 1rem; color: rgba(255,255,255,0.4); margin-top: 3rem; }
        </style></head>
        <body>
            <div class="card">
                <h1>${title}</h1>
                <p>${subtitle}</p>
                <div class="logo">NV oOS — Open Operator System</div>
            </div>
        </body></html>
    `);
    await page.waitForTimeout(duration);
}
```

#### Step 4.2: Action annotations (Playwright v1.59 screencast API)

For environments with Playwright ≥1.59, use the `page.screencast` API for built-in action highlights:

```js
if (supportsScreencast(page)) {
    await page.screencast.showActions({
        position: 'top-right',
        duration: 800,
        fontSize: 16,
    });
}
```

Fallback for older Playwright: inject a CSS overlay that briefly highlights clicked elements.

#### Step 4.3: Chapter markers

Insert visual section dividers during the recording:

```js
// With v1.59
await page.screencast.showChapter('Step 2: Assign Tools', {
    description: 'Search and enable the tools your assistant will use',
    duration: 2500,
});

// Fallback: overlay div
await showOverlayCard(page, {
    title: 'Step 2: Assign Tools',
    subtitle: 'Search and enable the tools your assistant will use',
    duration: 2500,
});
```

#### Step 4.4: Background music (optional, configurable)

Add support for an ambient background track with audio ducking during narration:

```js
// FFmpeg filter for background music with ducking
const ffmpegArgs = [
    `-i "${videoPath}"`,           // 0:v — silent video
    `-i "${combinedAudioPath}"`,   // 1:a — narration
    `-i "${bgMusicPath}"`,         // 2:a — background music
    `-filter_complex`,
    `[2:a]volume=0.08[bg];` +                    // Reduce music to 8%
    `[1:a]asplit=[narration][sidechain];` +       // Split narration
    `[bg][sidechain]sidechaincompress=` +         // Duck music when narration plays
        `threshold=0.01:ratio=4:attack=5:release=100[bgducked];` +
    `[narration][bgducked]amix=inputs=2:duration=first[aout]`,
    `-map 0:v -map "[aout]"`,
    `-c:v libx264 -preset fast -crf 28`,
    `-c:a aac -b:a 192k`,
    `-shortest "${finalPath}"`,
].join(' ');
```

Controlled by env var: `BG_MUSIC_PATH=/path/to/ambient.mp3`

#### Step 4.5: Video catalog page

**File:** `docs/videos/CATALOG.md`

A markdown page with embedded video previews, links to scripts, durations, and status badges:

```markdown
| # | Video | Duration | Status | Script | Narrated |
|---|-------|----------|--------|--------|----------|
| 1 | Add Assistant & Tools | 1:45 | ✅ | `capture-demo-video-assistant.js` | ✅ |
| 2 | Configure AI Provider | 1:20 | ✅ | `capture-demo-video-provider.js` | ⏳ |
| ... | ... | ... | ... | ... | ... |
```

---

### Phase 5: CI/CD Integration (Week 5)

**Goal:** Videos regenerated automatically on every release.

#### Step 5.1: GitHub Actions workflow

**File:** `.github/workflows/demo-videos.yml`

```yaml
name: Generate Demo Videos

on:
  workflow_dispatch:  # Manual trigger
    inputs:
      narration:
        description: 'Generate narrated videos'
        type: boolean
        default: true
      pro:
        description: 'Include Pro videos'
        type: boolean
        default: true
  release:
    types: [published]

jobs:
  videos:
    runs-on: ubuntu-latest
    timeout-minutes: 45

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: wordpress
          MYSQL_DATABASE: wordpress
        ports:
          - 3306:3306
        options: >-
          --health-cmd "mysqladmin ping -h localhost"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Install system dependencies
        run: |
          sudo apt-get update
          sudo apt-get install -y ffmpeg curl

      - name: Install Node dependencies
        run: npm ci

      - name: Install Playwright browsers
        run: npx playwright install --with-deps chromium

      - name: Start WordPress with Docker Compose
        run: |
          docker compose up -d
          # Wait for WordPress healthcheck
          for i in $(seq 1 60); do
            if curl -sf http://localhost:8000 > /dev/null 2>&1; then
              echo "WordPress is ready."
              break
            fi
            echo "Waiting for WordPress... ($i)"
            sleep 3
          done

      - name: Run video pipeline
        run: bash bin/capture-demo-videos.sh
        env:
          BASE_URL: http://localhost:8000
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
          CAPTURE_PRO: ${{ inputs.pro || 'false' }}

      - name: Generate narration audio
        if: ${{ inputs.narration && env.OPENAI_API_KEY != '' }}
        run: node bin/generate-narration-audio.js --all
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}

      - name: Merge video + narration
        if: ${{ inputs.narration }}
        run: |
          for dir in docs/videos/base docs/videos/pro; do
            [ -d "$dir" ] || continue
            for webm in "$dir"/*.webm; do
              [ -f "$webm" ] || continue
              name=$(basename "$webm" .webm)
              node bin/merge-demo-video.js "$name" || true
            done
          done

      - name: Upload video artifacts
        uses: actions/upload-artifact@v4
        with:
          name: demo-videos
          path: |
            docs/videos/base/*.mp4
            docs/videos/pro/*.mp4
          retention-days: 30

      - name: Attach videos to release
        if: github.event_name == 'release'
        uses: softprops/action-gh-release@v2
        with:
          files: |
            docs/videos/base/*.mp4
            docs/videos/pro/*.mp4
```

#### Step 5.2: CI performance tuning

- **Reduce resolution for CI** to 1280×720 (vs 1920×1080 for local dev). Controlled by `CI_VIDEO_SIZE` env var.
- **Use `preset ultrafast`** for CI encoding (5× faster, larger files — fine for artifacts).
- **Parallelize** base and pro video capture (separate GitHub Actions jobs).
- **Cache Playwright browsers** and npm dependencies.
- **Clean up Docker volumes** after the job to free disk space.

#### Step 5.3: Slack/notification integration

Post a summary to a Slack channel when videos are generated:

```yaml
- name: Notify Slack
  uses: slackapi/slack-github-action@v2
  with:
    webhook: ${{ secrets.SLACK_DEMO_VIDEOS_WEBHOOK }}
    webhook-type: incoming-webhook
    payload: |
      {
        "text": "🎬 Demo videos regenerated for release ${{ github.ref_name }}\n${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}"
      }
```

---

## Quality Standards

### Video Standards

| Property | Requirement | Rationale |
|----------|------------|-----------|
| Resolution | 1920×1080 (local), 1280×720 (CI) | Full HD for marketing; 720p sufficient for CI review |
| Codec | H.264 (`.mp4`) | Universal playback |
| Audio | AAC 192 kbps (narrated), silent otherwise | Clear voice without bloat |
| Duration | 60–180 seconds per video | Attention span + information density |
| File size | < 25 MB per video | GitHub artifact limits, download speed |
| Mouse cursor | Visible (CSS-injected for headless) | Viewers need to see what's being clicked |
| `data-testid` coverage | All interacted elements | Industry standard for selector stability |

### Script Standards

- **One script, one task.** Each script demonstrates one complete user story.
- **Idempotent.** Re-running produces the same output. No cumulative side effects.
- **Graceful degradation.** Failure in one script does not block others.
- **Environment-configurable.** All URLs, credentials, and paths come from `process.env` with sensible defaults.
- **Selectors in registry, not inline.** `bin/utils/video-selectors.js` is the single source of truth.
- **JSDoc headers.** Every script documents purpose, usage, prerequisites, output.

### Narration Standards

- **Conversational tone.** Not a spec sheet read aloud.
- **One action per spoken segment.** Each line of the narration `.txt` maps to one UI action.
- **80–120 words per 60 seconds.** Matches TTS ~150 WPM.
- **Active voice, present tense.** "Click the button" not "The button is clicked."
- **Version controlled.** Narration `.txt` files live in `docs/videos/narration/` alongside the code they describe.

### Commit Standards

- **One commit per deliverable.** New script, new narration file, new workflow = separate commits.
- **Commit message format:** `Add demo video {type}: {description}`
  - `Add demo video script: Configure AI Provider`
  - `Add demo video narration: Chat Conversation`
  - `Add demo video CI: GitHub Actions workflow`
- **Output files are gitignored.** `.webm` and `.mp4` files never committed. They are build artifacts.

---

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Selectors break on UI change** | High | High | Phase 0: add `data-testid` attributes. Centralized selector registry. Re-run pipeline on each release. |
| **AI API key missing → chat/narration fails** | Medium | High | Skip chat-dependent scripts gracefully. Warn clearly. CI secrets rotation. |
| **Pro addon not available** | Medium | Low | Detect `addons/pro/` at runtime. Skip Pro section gracefully. |
| **FFmpeg not installed → no MP4 output** | Medium | Low | `.webm` is playable in modern browsers. Document FFmpeg as optional optimization. |
| **CI runner disk space for videos** | Medium | Medium | Use CRF 35 for CI drafts. 30-day artifact retention. Clean up Docker volumes post-job. |
| **TTS API costs at scale** | Low | Medium | OpenAI `tts-1` is $0.015/1K chars. ~$0.03 per 90s video. 15 videos ≈ $0.50 per full run. Acceptable. |
| **JS-heavy pages don't render in time** | Medium | Medium | `waitUntil: 'networkidle'` + generous `waitForTimeout`. Option to use `page.screencast.start()` after render. |
| **Port 8000 already in use** | Low | Low | Override via `BASE_URL` env var. |
| **Playwright version mismatch** | Low | Medium | Pin Playwright version in `package.json`. Screencast API is v1.59+ — feature-detect with graceful fallback. |

---

## Open Questions

1. **TTS provider?** Plan defaults to OpenAI TTS (`tts-1`, `nova` voice) — same API key as the plugin, $0.015/1K chars. ElevenLabs has better voice quality but requires a separate paid key. **Decision:** Ship with OpenAI TTS. Offer ElevenLabs as an optional override via `TTS_PROVIDER=elevenlabs` env var.

2. **Video hosting?** Where will the final `.mp4` files live?
   - **GitHub Releases** (CI artifacts) — automatic, versioned, free
   - **YouTube unlisted playlist** — embeddable, accessible
   - **Plugin website** (`nvdigitalsolutions.com/wpoos`) — self-hosted, branded
   - **WordPress.org plugin page** — limited upload size, manual process
   **Recommendation:** YouTube unlisted playlist for public sharing. GitHub Releases for archival/version history.

3. **Should videos be silent or narrated?** Phase 1–2 produce silent `.webm` (useful for debugging). Phase 3 adds narration (for public demos). **Decision:** Pipeline supports both. Narration is optional (gated on `OPENAI_API_KEY`).

4. **One Pro script or per-task scripts?** Current `capture-demo-video-pro.js` handles all 8 tasks in one file. This works for simple page-load demos. If individual Pro tasks need complex interactions, split them into per-task scripts following the Phase 1.3 template.

5. **Background music license?** If we add ambient music in Phase 4, it must be royalty-free with commercial license. Options: Epidemic Sound, Artlist, or free sources like Pixabay Music (CC0). **Decision:** Make background music optional and configurable via `BG_MUSIC_PATH`. Ship a default CC0 track.

6. **Should we adopt `playwright-recast` (npm)?** It solves many of the same problems (narration sync, speed control, subtitles) but is v0.1.0 (Mar 2026). **Decision:** Do not adopt as a dependency. Borrow its design patterns. Re-evaluate when it reaches v1.0.

7. **Playwright v1.59 `page.screencast` adoption?** The API was released May 2026 and adds `showActions()`, `showChapter()`, `showOverlay()`. **Decision:** Feature-detect and use when available. Fall back to DOM injection for older Playwright versions.

---

## Quick Start

```bash
# 1. Ensure Docker is running
docker compose up -d

# 2. Set your API key (required for narration; optional for silent videos)
export OPENAI_API_KEY="sk-proj-..."

# 3. Run the full silent pipeline (Phase 1–2)
bash bin/capture-demo-videos.sh

# 4. Generate narration and merge (Phase 3)
node bin/generate-narration-audio.js --all
node bin/merge-demo-video.js --all

# 5. View results
ls -lh docs/videos/base/
ls -lh docs/videos/pro/

# 6. Record a single video
node bin/capture-demo-video-assistant.js

# 7. Reset and re-record everything
docker compose down -v
docker compose up -d
bash bin/capture-demo-videos.sh
```

---

*End of plan. Next action: begin Phase 0 — audit selectors and add `data-testid` attributes.*
