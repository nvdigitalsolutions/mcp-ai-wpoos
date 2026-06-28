# Features — Voice

GPT-Realtime-2 voice models with WebRTC transport, real-time translation,
streaming speech-to-text, and configurable reasoning for AI voice agents.

## What belongs here

- GPT-Realtime-2 voice models and GA Realtime API
- WebRTC transport with ephemeral tokens and SDP relay
- GPT-Realtime-Translate (70+ input → 13 output languages)
- GPT-Realtime-Whisper (streaming STT)
- Configurable reasoning effort and structured prompt templates
- Push-to-Talk (PTT) mode and commentary phase support
- Per-section filter hooks for prompt customization
- Admin settings reference for voice/realtime configuration

## What doesn't belong here

- SSE streaming implementation → `../streaming/`
- Chat UI voice controls → `../../user-guides/chat/`
- MCP protocol voice integration → `../../reference/api/`
- Provider client implementation → `../../developer/`
