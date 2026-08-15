# Embedded Addon v0.2.0

**Version:** 0.2.0
**Category:** Addon (Proprietary)
**Directory:** `addons/embedded/`
**Last Updated:** August 5, 2026

## Overview

The Embedded Addon enables server-side and client-side LLM inference without external API dependencies. v0.2.0 adds voice tool calling, OpenMed healthcare tools, MCP abilities, a backend registry, and self-hosted OCR integration.

## New in v0.2.0

### Voice Tool Calling
- Bridge script (`voice-tool-calling-bridge.js`) connects browser voice input to AI tools
- Real-time voice → text → tool execution pipeline
- Works with the WebChat P2P rooms

### OpenMed Healthcare Tools
- `deidentify-health-record` — PHI redaction for medical documents
- `extract-clinical-entities` — Named entity recognition for clinical terms
- HIPAA-aware data handling with auto-redaction

### MCP Abilities
- Embedded abilities registry (`includes/abilities/`)
- Tools register as machine-readable abilities for AI agent discovery
- Category: `nvoos-embedded` with sub-categories for inference, voice, and health

### Backend Registry
- `NVOOS_Embedded_Backend_Registry` (new class) manages available inference backends
- Supports dynamic backend discovery and health checking
- Three backends registered: server, client, and self-hosted OCR

### Self-Hosted OCR Integration
- `NVOOS_Embedded_Self_Hosted_OCR_Backend` bridges to vLLM OCR servers
- OCR health dashboard (`class-nvoos-embedded-ocr-dashboard.php`)
- Admin UI for OCR endpoint configuration

## Architecture

```
addons/embedded/
├── nvoos-embedded.php              # Entry point
├── README.md
├── assets/
│   ├── css/voice-embedded.css      # Voice UI styles
│   ├── js/
│   │   ├── voice-mode-embedded.js   # Voice recording & playback
│   │   └── voice-tool-calling-bridge.js  # Voice → tool bridge (NEW)
├── includes/
│   ├── class-nvoos-embedded.php     # Main addon class
│   ├── abilities/
│   │   ├── class-nvoos-embedded-abilities.php  # Abilities registry (NEW)
│   │   └── README.md
│   ├── admin/
│   │   ├── class-nvoos-embedded-ocr-dashboard.php  # OCR health UI (NEW)
│   │   └── README.md
│   └── embedded/
│       ├── class-nvoos-embedded-backend-registry.php  # Backend registry (NEW)
│       ├── class-nvoos-embedded-client-backend.php
│       ├── class-nvoos-embedded-server-backend.php
│       ├── class-nvoos-embedded-self-hosted-ocr-backend.php  # OCR backend (NEW)
│       ├── interface-nvoos-embedded-llm-backend.php
│       └── README.md
```

## Inferences Backends

| Backend | Type | Model | Requirements |
|---|---|---|---|
| Server | llama.cpp (GGUF) | Configurable | PHP with proc_open |
| Client | WebLLM/WebGPU | Browser-dependent | Chrome/Edge with WebGPU |
| Self-Hosted OCR | vLLM REST API | Unlimited-OCR / DeepSeek-OCR | GPU server + vLLM |

## WebChat P2P Rooms

Embedded includes a P2P WebChat system using WebRTC:
- Direct browser-to-browser communication
- No server relay for messages (signaling only)
- Voice integration with tool calling bridge
- See `includes/webchat/README.md` for setup

## Related

- [Embedded Addon README](../../addons/embedded/README.md)
- [Self-Hosted OCR](self-hosted-ocr.md)
- [Abilities API](abilities-api.md)
- [Embedded Addon Enhancement Plan](../project/proposals/embedded-addon-enhancement-plan.md)
- [Embedded Addon Implementation Plan](../project/proposals/embedded-addon-implementation-plan.md)
