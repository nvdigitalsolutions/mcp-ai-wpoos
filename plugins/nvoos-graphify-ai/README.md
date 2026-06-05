# NV oOS Graphify — AI

> **Version**: 1.0.0-dev | **Requires**: NV oOS Graphify 1.0+, PHP 8.1+, WordPress 6.5+

AI chat assistant addon for NV oOS Graphify. One install, one API key, complete AI experience.

---

## Features

### AI Chat
- Conversational AI chat with tool-calling loop
- SSE streaming for real-time responses
- REST API endpoints under `nvoos-graphify/v1/ai/`

### Providers (13 total)
- **Bundled**: OpenAI, Gemini, Ollama
- **Exotic** (coming): Anthropic, DeepSeek, OpenRouter, HuggingFace, Cloudflare, LMStudio, NVIDIA, DigitalOcean, Kimi, Baseten

### Coming in later sprints
- ~30 AI-powered tools (content generation, image creation, SEO analysis, etc.)
- Vector embeddings + semantic similarity search + RAG
- Agent memory (store, recall, mine, decay, provenance)

---

## Installation

1. Ensure NV oOS Graphify is installed and activated
2. Upload or copy the `nvoos-graphify-ai` folder to `/wp-content/plugins/`
3. Activate **NV oOS Graphify — AI** in Plugins → Installed Plugins
4. Go to Settings → NV oOS Graphify, enter your OpenAI API key
5. Send a POST to `/wp-json/nvoos-graphify/v1/ai/chat`

---

## REST API

Base path: `/wp-json/nvoos-graphify/v1`

| Method | Path | Description | Auth |
|---|---|---|---|
| `POST` | `/ai/chat` | Send chat messages | `edit_posts` |
| `GET` | `/ai/providers` | List available providers | `edit_posts` |

---

## Architecture

```
nvoos-graphify-ai/
├── nvoos-graphify-ai.php     Bootstrap + PSR-4 autoload
├── composer.json              PSR-4: NvoosGraphifyAi\
├── src/
│   ├── Plugin.php             Composition root
│   ├── Settings.php           Reads from core's grouped settings
│   ├── ProviderRegistry.php   Provider container
│   ├── Contracts/
│   │   └── ProviderClient.php Provider interface
│   ├── Providers/
│   │   └── OpenAi/            OpenAI client
│   ├── Chat/
│   │   └── ChatService.php    Tool-calling loop orchestrator
│   └── Rest/
│       └── ChatController.php REST endpoints
└── tests/
```

---

## License

GPL-3.0-or-later. See LICENSE file.
