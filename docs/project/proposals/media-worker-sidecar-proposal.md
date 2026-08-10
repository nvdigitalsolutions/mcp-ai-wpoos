# Media Worker Sidecar — Implementation Report

**Status:** ✅ IMPLEMENTED  
**Date:** August 2026  
**Version:** 2.1.0

---

## Overview

The Design Stack Media Worker now serves as a comprehensive **sidecar** that offloads all NPM-package-dependent operations from the `mcp-ai-wpoos` WordPress plugin. The plugin retains full backward compatibility — the sidecar is an opt-in acceleration layer.

When the sidecar is available (`WP_MEDIA_WORKER_URL` constant set), all heavy operations route via HTTP. When unavailable, existing filter-based and local Node.js fallbacks continue unchanged.

---

## Architecture

```
WordPress (PHP)                          Media Worker (Node.js, Docker)
──────────────                           ──────────────────────────────
wp_remote_post() ─────────────────────→  http://media-worker:3100

Service cascade in every class:
  1. apply_filters()       ← existing hook (backward compat)
  2. $this->sidecar_request()  ← HTTP to media-worker (NEW)
  3. Local Node.js exec()  ← retained fallback
  4. WP_Error(501)         ← graceful degradation
```

---

## Service Classes with Sidecar Cascade

### Pipeline Services (filter → sidecar → local → error)

| Service Class | Sidecar Route | Status |
|---|---|---|
| `WP_MCP_AI_Prettier_Service` | `/api/code/format`, `/api/code/check-syntax` | ✅ |
| `WP_MCP_AI_MJML_Service` | `/api/email/compile-mjml` | ✅ |
| `WP_MCP_AI_Nodemailer_Service` | `/api/email/send` | ✅ |
| `WP_MCP_AI_Fluent_FFmpeg_Service` | `/api/video/process` (5 methods) | ✅ |
| `WP_MCP_AI_Language_Detection_Service` | `/api/data/language-detect`, `/api/data/phone-format` | ✅ |
| `WP_MCP_AI_OCR_Service` | `/api/ocr/recognize` | ✅ |
| `WP_MCP_AI_Video_Frame_Extractor_Service` | `/api/video/extract-frames` | ✅ |

### Tool Classes (filter → sidecar → error)

| Tool Class | NPM Package | Sidecar Route | Status |
|---|---|---|---|
| `WP_MCP_AI_Tool_Render_Math_Equation` | katex | `/api/data/render-math` | ✅ |
| `WP_MCP_AI_Tool_Export_Calendar_ICS` | ics | `/api/data/generate-ics` | ✅ |
| `WP_MCP_AI_Tool_Generate_Health_Chart` | chart.js | `/api/data/render-chart` | ✅ |
| `WP_MCP_AI_Tool_Analyze_Geospatial` | @turf/turf | `/api/data/analyze-geospatial` | ✅ |

---

## Media Worker API — Full Endpoint Map

### Image (existing)
| Method | Route | Package |
|---|---|---|
| POST | `/api/image/generate` | openai, gemini, stability, replicate, etc. |
| POST | `/api/image/optimize` | sharp |
| POST | `/api/image/optimize-batch` | sharp |
| GET | `/api/image/providers` | — |

### Video (existing)
| Method | Route | Package |
|---|---|---|
| POST | `/api/video/generate` | replicate |
| POST | `/api/video/process` | fluent-ffmpeg |
| GET | `/api/video/info` | — |
| GET | `/api/video/models` | — |
| GET | `/api/video/prediction/:id` | — |

### Social (existing)
| Method | Route | Package |
|---|---|---|
| POST | `/api/social/post` | twitter, facebook, instagram, linkedin |
| POST | `/api/social/generate-content` | openai |
| GET | `/api/social/accounts` | — |

### Workflow (existing)
| Method | Route | Package |
|---|---|---|
| POST | `/api/workflow/social-package` | composite |
| POST | `/api/workflow/brand-assets` | composite |
| POST | `/api/workflow/video-pipeline` | composite |
| GET | `/api/workflow/status` | redis |

### PDF (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/pdf/extract` | pdf-parse, pdfjs-dist |
| POST | `/api/pdf/render` | pdfjs-dist, canvas |
| POST | `/api/pdf/generate` | puppeteer, pdfkit |
| POST | `/api/pdf/merge` | pdf-lib |
| POST | `/api/pdf/watermark` | pdf-lib |

### Document (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/document/excel` | exceljs |
| POST | `/api/document/word` | docx |

### OCR (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/ocr/recognize` | tesseract.js |

### Email (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/email/send` | nodemailer |
| POST | `/api/email/compile-mjml` | mjml |

### Code (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/code/format` | prettier |
| POST | `/api/code/check-syntax` | prettier |

### Data / Utilities (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/data/translate` | google-translate-api-x |
| POST | `/api/data/language-detect` | franc, iso-639-1 |
| POST | `/api/data/qrcode` | qrcode |
| POST | `/api/data/render-math` | katex |
| POST | `/api/data/generate-ics` | ics |
| POST | `/api/data/render-chart` | chart.js, chartjs-node-canvas |
| POST | `/api/data/analyze-geospatial` | @turf/turf |

### Browser (new)
| Method | Route | Package |
|---|---|---|
| POST | `/api/browser/screenshot` | puppeteer |
| POST | `/api/browser/pdf` | puppeteer |

### Health
| Method | Route | Description |
|---|---|---|
| GET | `/api/health` | Status, version, provider status, capabilities matrix |

---

## Files Changed

### New Files
| File | Purpose |
|---|---|
| `includes/traits/trait-wp-mcp-ai-media-worker-client.php` | Shared HTTP client trait (`sidecar_request()`, `is_sidecar_available()`, `get_sidecar_url()`) |
| `addons/pro/includes/admin/class-wp-mcp-ai-media-worker-settings.php` | Admin UI (Settings → Media Worker) with connection test, capabilities table, URL/token config |

### Updated Files — Plugin
| File | Change |
|---|---|
| `includes/bootstrap/loader.php` | Added trait `require_once` |
| `addons/pro/mcp-ai-wpoos-pro.php` | Added admin settings `require_once` |
| `addons/pro/includes/npm-integration-filters.php` | Sidecar-aware `wp_mcp_ai_is_nodejs_available()`, updated admin notice |
| `addons/pro/includes/services/class-wp-mcp-ai-prettier-service.php` | +trait + cascade |
| `addons/pro/includes/services/class-wp-mcp-ai-mjml-service.php` | +trait + cascade |
| `addons/pro/includes/services/class-wp-mcp-ai-nodemailer-service.php` | +trait + cascade |
| `addons/pro/includes/services/class-wp-mcp-ai-fluent-ffmpeg-service.php` | +trait + cascade (5 methods) |
| `addons/pro/includes/services/class-wp-mcp-ai-language-detection-service.php` | +trait + cascade |
| `addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php` | +trait + cascade |
| `addons/pro/includes/services/class-wp-mcp-ai-video-frame-extractor-service.php` | +trait + cascade |
| `addons/pro/includes/tools/math/class-wp-mcp-ai-tool-render-math-equation.php` | +trait + cascade |
| `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-export-calendar-ics.php` | +trait + cascade |
| `addons/pro/includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-generate-health-chart.php` | +trait + cascade |
| `addons/pro/includes/tools/developer/class-wp-mcp-ai-tool-analyze-geospatial.php` | +trait + cascade |

### Updated Files — Media Worker
| File | Change |
|---|---|
| `Dockerfile` | Added `build-base python3 pkgconfig pixman-dev` for canvas compilation |
| `package.json` | Added 10 packages: pdfjs-dist, pdfkit, pdf-lib, pdf-parse, exceljs, docx, puppeteer, tesseract.js, nodemailer, mjml, prettier, franc, iso-639-1, google-translate-api-x, qrcode, katex, ics, chart.js, chartjs-node-canvas, @turf/turf |
| `src/index.js` | Registered 7 new route groups, expanded health check |
| `src/routes/pdf.js` | New — extract, render, generate, merge, watermark |
| `src/routes/document.js` | New — Excel, Word generation |
| `src/routes/ocr.js` | New — Tesseract.js OCR |
| `src/routes/code.js` | New — Prettier format + syntax check |
| `src/routes/email.js` | New — Nodemailer send + MJML compile |
| `src/routes/data.js` | Extended — +render-math, +generate-ics, +render-chart, +analyze-geospatial |
| `src/routes/browser.js` | New — Puppeteer screenshot + PDF |

### Updated Files — Docker
| File | Change |
|---|---|
| `docker-compose.yml` | Removed `worker_node_modules` volume, enabled `WP_DEBUG_DISPLAY` |
| `.env` | Fixed `MCP_PLUGIN_PATH` to WSL path |
| `.zed/settings.json` | Removed trailing commas, cross-project context server |

---

## Test Results — 16/16 Endpoints

| # | Endpoint | Result |
|---|---|---|
| 1 | Health Check | ✅ v2.1.0 |
| 2 | Code Format | ✅ Prettier |
| 3 | Code Syntax Check | ✅ Prettier |
| 4 | Language Detect | ✅ franc |
| 5 | MJML Compile | ✅ Template → HTML |
| 6 | QR Code | ✅ PNG output |
| 7 | Excel Generation | ✅ .xlsx |
| 8 | Word Generation | ✅ .docx |
| 9 | Translation | ✅ en→fr |
| 10 | Browser Screenshot | ✅ PNG |
| 11 | PDF Generation | ✅ HTML → PDF |
| 12 | PDF Extraction | ✅ Text extracted |
| 13 | PDF Merge | ✅ Merged |
| 14 | OCR | ✅ 74% confidence |
| 15 | Math (KaTeX) | ✅ LaTeX → HTML |
| 16 | Calendar ICS | ✅ Valid ICS |
| 17 | Chart (Chart.js) | ✅ 7,979 byte PNG |
| 18 | Geospatial (Turf) | ✅ Area calculation |

---

## Bugs Fixed During Implementation

| Bug | Fix |
|---|---|
| `canvas` native module failed to compile (no `make` on Alpine) | Added `build-base python3 pkgconfig pixman-dev` to Dockerfile, removed after `npm install` |
| `iso-639-1` not in package.json | Added to dependencies |
| Named `worker_node_modules` volume overriding fresh `npm install` | Removed volume from docker-compose.yml |
| Trait `WP_MCP_AI_Media_Worker_Client` not autoloaded | Added `require_once` to `includes/bootstrap/loader.php` |
| Admin class loaded lazily inside a function | Moved `require_once` to eager load in pro file |
| Wrong parent menu slug (`wp-mcp-ai-pro` → `nvoos-pro-dashboard`) | Changed to `add_options_page()` for reliable registration |
| Admin menu priority 20 ran before parent existed | Changed to priority 30 |
| `WP_DEBUG_DISPLAY` not taking effect on restart | Added directly to `wp-config.php` |
| OCR route crashed with `TypeError: logger is not a function` | Fixed `createWorker` to not pass `undefined` logger |
| OCR worker threads crashed on Alpine | Switched to `createWorker()` with single worker + explicit lifecycle |
| `.env` Windows path broke `docker compose up` | Fixed to WSL path `/mnt/f/GITHUB/mcp-ai-wpoos` |
| Media worker health check blocking page load | Deferred to AJAX (Test Connection button) |
