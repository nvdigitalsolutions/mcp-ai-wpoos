# NV oOS Embedded AI Addon

> **Version:** 0.2.0  
> **License:** Proprietary © 2025-2026 NV Digital Solutions  
> **Requires:** NV oOS base plugin (`WP_MCP_AI_VERSION`)

---

## Purpose

Provides local-first AI inference and communication for WordPress — client-side WebLLM (browser GPU), server-side llama.cpp (CPU), speech-to-text, voice tool calling, and P2P WebChat rooms. No external API keys required.

## Architecture (v0.2.0)

```
nvoos-embedded.php                    ← Entry point (constants, class loader)
includes/
├── class-nvoos-embedded.php          ← Core singleton (registry wiring, hooks, Site Health)
├── embedded/                         ← Backend registry + inference implementations
│   ├── interface-nvoos-embedded-llm-backend.php
│   ├── class-nvoos-embedded-backend-registry.php
│   ├── class-nvoos-embedded-client-backend.php    (WebLLM, browser)
│   ├── class-nvoos-embedded-server-backend.php    (llama.cpp, server)
│   ├── class-wp-mcp-ai-embedded-client.php        (server internals)
│   └── class-wp-mcp-ai-embedded-transcribe.php    (Gemma 4 STT)
├── abilities/                        ← WordPress Abilities (MCP-compatible)
│   └── class-nvoos-embedded-abilities.php (5 abilities)
├── admin/                            ← Settings UI + model management
└── webchat/                          ← P2P rooms + 7 tool classes
assets/
├── js/                               ← 17 JS modules (WebLLM, STT, voice, tool calling)
└── css/                              ← Voice UI + admin styles
```

## Quick Start

```
Settings → NV oOS → Providers → Embedded AI
├── Inference Backend: Auto (or Client-Side / Server-Side)
├── Client Model: Llama 3.2 1B (recommended)
├── Enable Voice Mode: ☑ (optional)
└── Save
```

## Backend Selection

| Backend | Slug | Where It Runs | Requirements |
|---------|------|--------------|-------------|
| Client-Side WebLLM | `client_side` | User's browser (WebGPU) | Chrome 113+, Edge 113+, Safari 18+ |
| Server-Side llama.cpp | `server_side` | WordPress server (CPU) | `shell_exec`, llama.cpp binary, 2-8GB RAM |

Auto mode prefers server-side when available; falls back to client-side on shared hosting.

## AI Agent Discoverability

All embedded operations are registered as WordPress Abilities (`wp_register_ability()`) with `meta.mcp.public = true`. AI agents (Claude Desktop, Cursor, VS Code, Claude Code) can discover and execute them via the [WordPress MCP Adapter](https://github.com/wordpress/mcp-adapter).

```
nvoos-embedded/transcribe-audio    → STT transcription
nvoos-embedded/get-stt-config      → STT settings
nvoos-embedded/get-llm-backends    → Backend listing
nvoos-embedded/get-model-list      → Model catalog
nvoos-embedded/analyze-image       → Vision model analysis
```

## Conventions

- **Canonical envelope:** All tool `execute()` methods return `array` on success or `WP_Error` on failure. Never `array('success' => false, ...)`.
- **Two-gate sanitisation:** Sanitize `$arguments[...]` at entry; escape every value at exit.
- **Backend contract:** Every inference backend implements `NV_oOS_Embedded_LLM_Backend`. The registry follows the WordPress 7.0 Connectors API pattern.
- **Per-folder READMEs:** Each PHP-bearing subdirectory has its own README with public surface, inputs/outputs, and conventions.

## Tests

```bash
vendor/bin/phpunit tests/php/test-embedded-*.php
```

## Also Load

- [CLAUDE.md](../../CLAUDE.md) — PHP compat, tool patterns, naming conventions
- [AGENTS.md](../../AGENTS.md) — Agent inventory + coordination
- `.context/conventions.md` — Naming + style
- `.context/security-checklist.md` — Security rules
- [docs/embedded-addon-enhancement-plan.md](../../docs/project/proposals/embedded-addon-enhancement-plan.md)
- [docs/embedded-addon-implementation-plan.md](../../docs/project/proposals/embedded-addon-implementation-plan.md)
