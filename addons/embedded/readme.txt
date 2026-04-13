=== NV oOS Embedded AI ===
Contributors: nvdigitalsolutions
Tags: ai, llm, embedded, webllm, webchat
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Embedded AI inference and WebChat P2P extension for NV oOS (Open Operator System).

== Description ==

NV oOS Embedded AI provides two powerful capabilities for your WordPress site:

**Embedded LLM Inference**
- Server-side AI inference via llama.cpp with GGUF models — no external API keys needed
- Client-side browser inference via WebLLM (WebGPU) — runs entirely in the user's browser
- 12+ pre-configured model options from Qwen, Llama, Phi, Gemma, and more
- Tool calling support for client-side models
- Model management admin interface for downloading, configuring, and deleting models

**WebChat P2P Rooms**
- Decentralised peer-to-peer chat rooms with WebRTC signaling
- AI assistant integration within chat rooms
- JetEngine CCT support for persistent message storage
- REST API signaling controller for connection management

== Installation ==

1. Ensure the NV oOS (Open Operator System) base plugin is active.
2. Upload the `nvoos-embedded` folder to `/wp-content/plugins/`.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure embedded models via Settings → NV oOS → Embedded AI.

== External Services ==

This plugin connects to the following external services:

= WebLLM CDN =
The WebLLM client-side inference engine is loaded from a CDN when browser-based AI is used.
- Service: WebLLM (https://webllm.mlc.ai/)
- Data sent: None (library code is downloaded to the browser)
- Terms: https://github.com/nicholaskajoh/webllm/blob/main/LICENSE

= Hugging Face Model Hub =
GGUF models for server-side inference are downloaded from Hugging Face.
- Service: Hugging Face (https://huggingface.co/)
- Data sent: Model download requests
- Terms: https://huggingface.co/terms-of-service
- Privacy: https://huggingface.co/privacy

== Changelog ==

= 1.0.0 =
* Initial release
* Extracted from NV oOS Pro addon
* Server-side embedded LLM via llama.cpp
* Client-side WebLLM browser inference
* WebChat P2P room management
* 12+ pre-configured model options
* Tool calling support for embedded models
