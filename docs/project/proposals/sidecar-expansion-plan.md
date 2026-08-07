# oOS Sidecar — Unified Worker Expansion Plan

**Status:** In Progress  
**Created:** 2026-08-07  
**Phases:** Phase 1 (Image) ✅ → Phase A (Video) 🔴 → Phase B (Document) 🟡 → Phase C (OCR) 🟢

## Executive Summary

Expand the existing `media-worker/` (Node.js Express API for AI image generation)
into a unified **oOS Sidecar** that consolidates all heavy-lift domains — image
generation, video processing, document creation, OCR, and social publishing —
into a single Docker container behind a single API surface.

## Current State

The `media-worker/` directory exists in the repo but is completely disconnected
from the PHP plugin. It provides:
- 10 AI image generation providers
- Basic video processing (5 ops, placeholder AI generation)
- Social publishing stubs
- Sharp-based image optimization

The PHP plugin independently implements similar functionality via:
- Direct API calls (image generation — 3 providers)
- Node.js subprocess spawning (document PDF/Word/Excel, Sharp, Remotion)
- System tool calls (FFmpeg for video, pdftotext for PDF)

## Phases

### Phase 1: Image Generation Integration ✅ IN PROGRESS
- Wire `media-worker/` into `docker-compose.yml`
- Create `.env.example` with all provider keys
- Add new API keys to `WP_MCP_AI_Api_Key_Store`
- Create `WP_MCP_AI_Sidecar_Client` bridge class
- Expand `WP_MCP_AI_Tool_Generate_Image_AI` to support all 10 providers
- Add admin settings UI for new provider keys

### Phase A: Video Processing Expansion 🔴
- Expand `/api/video/process` from 5 to 14 operations
- Add `/api/video/queue` for async long-running jobs
- Add `/api/video/remotion` warm endpoint
- Wire `/api/video/generate` to Replicate
- Refactor 17 PHP video tools to use worker-first path

### Phase B: Document Generation Migration 🟡
- Add `/api/document/*` endpoints (PDF, Word, Excel, HTML→PDF)
- Consolidate npm deps (pdfkit, docx, exceljs) into worker
- Add Puppeteer for HTML→PDF (replaces DomPDF/wkhtmltopdf)
- Refactor PHP document tools to use worker-first path

### Phase C: OCR Endpoint (Optional) 🟢
- Add `/api/ocr/tesseract` using tesseract.js v5

## Architecture

```
                     ┌──────────────────────────────────────┐
                     │        oOS Sidecar (:3100)            │
  PHP Plugin ───────▶│  /api/image/*   10 providers         │
  (WordPress)        │  /api/video/*   FFmpeg ops           │
                     │  /api/document/* pdfkit/docx/exceljs │
                     │  /api/ocr/*     Tesseract (optional) │
                     │  /api/social/*  4 platforms          │
                     │  /api/health                        │
                     └──────────────────────────────────────┘
```

## Key Design Decisions

1. **Single worker, not multiple** — shared FFmpeg, Sharp, Express infrastructure
2. **Fallback-preserving** — PHP tools always fall back to direct API/subprocess when worker is unavailable
3. **Docker-optional** — worker runs in Docker, but direct-API paths work without it
4. **Graceful degradation** — `is_available()` check before every worker call
5. **Filterable base URL** — `apply_filters('wp_mcp_ai_sidecar_url', 'http://localhost:3100')`

## Files Affected

| File | Phase | Change |
|---|---|---|
| `.env.example` | P1 | NEW — all provider key slots |
| `docker-compose.yml` | P1 | ADD media-worker service |
| `.gitignore` | P1 | ADD media-worker/node_modules |
| `includes/security/class-wp-mcp-ai-api-key-store.php` | P1 | ADD 8 new MANAGED_KEYS |
| `includes/admin/sections/class-wp-mcp-ai-section-providers.php` | P1 | ADD API key fields |
| `includes/integrations/class-wp-mcp-ai-sidecar-client.php` | P1 | NEW — HTTP bridge |
| `addons/pro/includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php` | P1 | EXPAND to 10 providers |
| `media-worker/src/routes/video.js` | PA | Full rewrite — 14 ops |
| `media-worker/src/routes/document.js` | PB | NEW — 8 endpoints |
| `addons/pro/includes/tools/video-production/*.php` (17 files) | PA | ADD worker path |
| `addons/pro/includes/tools/document-generation/*.php` (5 files) | PB | ADD worker path |

## Testing Strategy

- PHPUnit unit tests for `WP_MCP_AI_Sidecar_Client` (mock HTTP)
- PHPUnit tests for worker-fallback paths in tools
- Manual integration: `docker compose --profile media up -d` → curl health → test each endpoint
- Manual fallback: `docker compose stop media-worker` → verify direct-API paths still work
