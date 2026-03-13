# NV oOS NPM Packages

## Overview

Standalone NPM packages extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin. Each package is independently usable in any JavaScript/TypeScript project.

All packages are **ES modules** with full TypeScript definitions. See each package's README for detailed API documentation.

---

## 📦 Packages

### Tier 1 — Core Utilities (Initial Set)

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-storage`](./nvoos-storage/) | Async JSON via Web Worker | Zero |
| [`nvoos-markdown`](./nvoos-markdown/) | XSS-safe markdown renderer | marked, dompurify |
| [`nvoos-events`](./nvoos-events/) | SSE client + job event bus | @microsoft/fetch-event-source |

### Tier 2 — Extended Utilities

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-http-client`](./nvoos-http-client/) | HTTP client with retry/backoff | ky |
| [`nvoos-clipboard`](./nvoos-clipboard/) | Clipboard copy with fallback | Zero |
| [`nvoos-offline-sync`](./nvoos-offline-sync/) | IndexedDB offline-first sync | Zero |

---

## Package Details

### @nvdigitalsolutions/nvoos-storage

- Async JSON parsing/stringifying via Web Workers (prevents main-thread blocking)
- Automatic fallback for small data and unsupported browsers
- Includes companion `storage-worker.js` script

```bash
npm install @nvdigitalsolutions/nvoos-storage
```

---

### @nvdigitalsolutions/nvoos-markdown

- Renders markdown to sanitized HTML using `marked` + `DOMPurify`
- Pre-configured security profile for AI-generated content
- `MarkdownRenderer` class with configurable CSS classes and allowed tags
- Standalone helper exports: `escapeHtml`, `sanitizeUrl`, `renderInlineLabel`

```bash
npm install @nvdigitalsolutions/nvoos-markdown marked dompurify
```

---

### @nvdigitalsolutions/nvoos-events

- Enhanced SSE client (POST support, auto-reconnect, max retry limit)
- Job event bus (mitt-compatible) with LRU cache eviction
- Promise-based `watchJob()` for async job completion tracking

```bash
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
```

---

### @nvdigitalsolutions/nvoos-http-client

- `postJson`, `uploadFile`, `get`, `delete` with automatic retry
- Exponential backoff with configurable limits
- Request/response hooks for auth failure detection, logging, and instrumentation
- `parseError()` helper for structured error handling

```bash
npm install @nvdigitalsolutions/nvoos-http-client ky
```

---

### @nvdigitalsolutions/nvoos-clipboard

- `copyTextToClipboard()` — Clipboard API with `execCommand` fallback
- `attachCopyButton()` — attaches a self-managing copy button to any element
- Configurable CSS class names via `configure()`
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-clipboard
```

---

### @nvdigitalsolutions/nvoos-offline-sync

- IndexedDB-backed message persistence (works offline immediately)
- Automatic sync queue that drains on reconnect
- Configurable `syncUrl`, `syncHeaders`, `dbName`, offline UI toggle
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-offline-sync
```

---

## Building All Packages

Each package has an `adapt-for-npm.js` build script that transforms the WordPress
plugin source into a clean ES module:

```bash
for pkg in nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync; do
  (cd $pkg && node adapt-for-npm.js)
done
```

---

## Additional Package Candidates

The following files were identified as future extraction candidates during the
industry-standards audit (see `FINAL_SUMMARY.md` for full analysis):

| File | Candidate Package | Portability |
|------|------------------|-------------|
| `chat-audio-service.js` (2112 LOC) | `nvoos-audio-recorder` + `nvoos-speech-synthesis` | Medium — split needed |
| `chat-transcription-service.js` (779 LOC) | `nvoos-transcription` | Medium |
| `chat-attachments-service.js` (586 LOC) | `nvoos-file-validator` | Medium |
| `cron-status-service.js` (485 LOC) | `nvoos-job-status` | High |
| `accessibility-enhancements.js` (503 LOC) | `nvoos-a11y` | Low — jQuery dep |

---

## License

All packages are MIT licensed. See `LICENSE` in each package directory.


**Location**: `/packages/nvoos-markdown/`

---

### 3. @nvdigitalsolutions/nvoos-events
**Real-time event coordination (SSE + Job Bus)**

- **Version**: 0.1.0-alpha.1
- **License**: MIT  
- **Size**: Combined ~16.5 KB (minified)
- **Dependencies**: @microsoft/fetch-event-source ^2.0.0 (peer)

**What it does:**
- Enhanced SSE client with automatic retry logic
- Job event bus for async operation tracking
- Promise-based job watching
- Event caching and replay

**Installation:**
```bash
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
```

**Location**: `/packages/nvoos-events/`

## 🏗️ Package Structure

```
packages/
├── IMPLEMENTATION_PLAN.md          # Development roadmap
├── nvoos-storage/                  # Package 1
│   ├── package.json
│   ├── README.md
│   ├── adapt-for-npm.js           # Build script
│   ├── storage-util.js            # Original source
│   └── dist/
│       ├── nvoos-storage.js       # ES module output
│       └── nvoos-storage.d.ts     # TypeScript definitions
├── nvoos-markdown/                 # Package 2
│   ├── package.json
│   ├── README.md
│   ├── adapt-for-npm.js
│   ├── chat-markdown-service.js
│   └── dist/
│       ├── nvoos-markdown.js
│       └── nvoos-markdown.d.ts
└── nvoos-events/                   # Package 3
    ├── package.json
    ├── README.md
    ├── adapt-for-npm.js
    ├── sse-service.js
    ├── job-event-bus.js
    └── dist/
        ├── nvoos-events.js
        └── nvoos-events.d.ts
```

## 🔧 Build Process

Each package includes a custom `adapt-for-npm.js` script that:

1. **Removes WordPress Dependencies**: Strips out `window.wpMcpAi*` globals
2. **Converts to ES Modules**: Removes IIFE wrappers, adds ES exports
3. **Adds Configuration**: Replaces hardcoded values with injectable config
4. **Generates TypeScript Definitions**: Creates .d.ts files for type safety
5. **Preserves Comments**: Maintains JSDoc documentation

### Building All Packages

```bash
cd packages/nvoos-storage && npm run build
cd ../nvoos-markdown && npm run build  
cd ../nvoos-events && npm run build
```

## 🎯 Extraction Strategy

### What Was Changed

**From WordPress Plugin Code:**
- ❌ Global `window.wpMcpAi*` objects
- ❌ WordPress-specific console prefixes
- ❌ IIFE wrappers for browser globals
- ❌ Hardcoded configuration paths

**To NPM Package Code:**
- ✅ ES module imports/exports
- ✅ Configuration injection methods
- ✅ Framework-agnostic design
- ✅ TypeScript definitions
- ✅ Comprehensive README files

### What Was Preserved

- ✅ All core functionality
- ✅ Performance optimizations
- ✅ Security features
- ✅ Error handling patterns
- ✅ JSDoc documentation

## 📊 Package Comparison

| Feature | nvoos-storage | nvoos-markdown | nvoos-events |
|---------|--------------|----------------|--------------|
| **WordPress Dependencies** | None | None | None |
| **External Dependencies** | 0 | 2 (peer) | 1 (peer) |
| **Browser APIs Used** | Web Worker | None | fetch, AbortController |
| **TypeScript** | ✅ Definitions | ✅ Definitions | ✅ Definitions |
| **Tree-Shakeable** | ✅ Yes | ✅ Yes | ✅ Yes |
| **Size (minified)** | ~5.4 KB | ~8.1 KB | ~16.5 KB |

## 🚀 Publication Checklist

### Pre-Publication (Completed ✅)

- [x] Package structure created
- [x] Source code extracted and adapted
- [x] WordPress dependencies removed
- [x] ES module exports added
- [x] TypeScript definitions generated
- [x] README documentation written
- [x] Build scripts tested
- [x] package.json metadata complete

### Publication Steps

**Alpha Publishing (Ready ✅)**
- [x] Create GitHub Actions workflow for alpha publishing
- [x] Add helper script (`bin/publish-alpha.sh`)
- [x] Create comprehensive publishing documentation
- [ ] Configure NPM_TOKEN secret in repository
- [ ] Create NPM organization (@nvdigitalsolutions)
- [ ] Set up 2FA for NPM account
- [ ] Publish first alpha versions to NPM

**See [Alpha Publishing Guide](../docs/npm-alpha-publishing.md) for detailed instructions.**

**Future Steps**
- [ ] Test packages in external projects
- [ ] Write integration tests
- [ ] Publish stable versions to NPM
- [ ] Update main plugin to use packages (optional)
- [ ] Create announcement blog post
- [ ] Share on social media

### Post-Publication

- [ ] Monitor download statistics
- [ ] Respond to issues and PRs
- [ ] Collect community feedback
- [ ] Iterate based on usage patterns
- [ ] Plan additional extractions

## 🔗 Links

- **Main Repository**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Documentation**: /docs/npm-packages/
- **Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **NV Digital**: https://nvdigitalsolutions.com

## 📝 License

All three packages are licensed under MIT for maximum adoption and community benefit.

The original WordPress plugin (GPL-3.0) and extracted NPM packages (MIT) can coexist because:
- Extracted code is original work by NV Digital Solutions
- No GPL-licensed dependencies in extracted code
- Dual-licensing is explicitly permitted

## ✨ Success Metrics

**Target (First 30 Days):**
- 500+ combined weekly downloads
- 10+ GitHub stars
- 0 critical bugs reported
- 1+ external contribution

**Target (First 90 Days):**
- 2,000+ combined weekly downloads
- 50+ GitHub stars
- Active community discussions
- Featured in 1+ blog post

---

**Status**: ✅ **COMPLETE - Ready for Publication**

All three packages extracted, adapted, documented, and built successfully. Ready for alpha publication to NPM.

**Last Updated**: 2026-02-06  
**Maintained By**: NV Digital Solutions
