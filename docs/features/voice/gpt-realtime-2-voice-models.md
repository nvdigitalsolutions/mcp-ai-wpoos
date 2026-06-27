# GPT-Realtime-2 Voice Models

**Version:** 1.1.34  
**Last Updated:** June 27, 2026  
**PR:** #5479

## Overview

NV oOS v1.1.34 migrates voice capabilities from the deprecated OpenAI Realtime
beta to the GA Realtime API, introducing **GPT-Realtime-2** with 128K context
and reasoning, plus two new voice models: **GPT-Realtime-Translate** and
**GPT-Realtime-Whisper**. WebRTC is now the primary transport with automatic
WebSocket fallback.

### What changed from beta

| Aspect | Beta (v1.1.33 and earlier) | GA (v1.1.34) |
|--------|----------------------------|--------------|
| API endpoints | `/v1/realtime/sessions` | `/v1/realtime/client_secrets`, `/v1/realtime/calls` |
| Session format | Flat JSON | Nested `session.type`, `audio.input/output` |
| Auth header | `OpenAI-Beta: true` | `OpenAI-Safety-Identifier` (hashed user ID) |
| Default model | `gpt-realtime` | `gpt-realtime-2` |
| Available voices | alloy, echo, fable, nova, onyx, shimmer | alloy, ash, ballad, coral, echo, sage, shimmer, cedar |
| Transport | WebSocket | **WebRTC** (primary), WebSocket (fallback) |

## WebRTC Transport

WebRTC is the default transport for all voice connections. The WebSocket
fallback is preserved in `chat-voice-realtime-service.js` with updated GA
event names.

### Connection flow

1. Browser requests an **ephemeral token** via `POST /mcp-ai/v1/realtime/token`
2. Token is used to create an `RTCPeerConnection` with the OpenAI Realtime API
3. Audio flows through the peer connection as a media stream track
4. SDP exchange is relayed through `POST /mcp-ai/v1/realtime/session`

### ICE reconnection

- Up to 3 reconnection attempts
- Exponential backoff between attempts
- Connection state transitions surfaced via CSS classes (`.voice-reconnecting`)

### JavaScript services

| Service file | Purpose |
|-------------|---------|
| `chat-webrtc-service.js` | WebRTC peer connection manager, ephemeral token handling, SDP relay |
| `chat-voice-realtime-service.js` | WebSocket fallback with GA event names |
| `chat-voice-mode-integration.js` | Transport selection, commentary support, mode integration |
| `chat-translation-service.js` | Translation mode UI and audio routing |
| `chat-transcription-realtime-service.js` | Streaming STT display and latency controls |

## Reasoning Effort

GPT-Realtime-2 supports configurable reasoning effort with 5 levels:

| Level | Description | Use case |
|-------|-------------|----------|
| `minimal` | Fastest, least reasoning | Simple Q&A, FAQs |
| `low` | Default | General conversation |
| `medium` | Balanced | Multi-step tasks |
| `high` | Deep reasoning | Complex analysis |
| `xhigh` | Maximum reasoning | Research, code review |

Configure via the admin setting **realtime_reasoning_effort** under
Settings → Providers → Voice.

## Structured Prompt Template

The GPT-Realtime-2 session prompt uses a 12-section template that can be
customized per-section via filter hooks:

| Section | Filter hook | Purpose |
|---------|-------------|---------|
| Role | `wp_mcp_ai_realtime_prompt_role` | Assistant persona and capabilities |
| Tone | `wp_mcp_ai_realtime_prompt_tone` | Communication style and personality |
| Language | `wp_mcp_ai_realtime_prompt_language` | Primary language and multilingual handling |
| Reasoning | `wp_mcp_ai_realtime_prompt_reasoning` | Reasoning approach and effort hints |
| Message Channels | `wp_mcp_ai_realtime_prompt_channels` | Available output channels (text, audio, etc.) |
| Preambles | `wp_mcp_ai_realtime_prompt_preambles` | Pre-response commentary behavior |
| Verbosity | `wp_mcp_ai_realtime_prompt_verbosity` | Response length and detail guidance |
| Tools | `wp_mcp_ai_realtime_prompt_tools` | Tool calling instructions |
| Unclear Audio | `wp_mcp_ai_realtime_prompt_unclear` | Handling of unclear/silent audio |
| Entity Capture | `wp_mcp_ai_realtime_prompt_entity` | Named entity recognition and capture |
| Long Context | `wp_mcp_ai_realtime_prompt_context` | Long-conversation memory guidance |
| Escalation | `wp_mcp_ai_realtime_prompt_escalation` | When and how to escalate to human |

### Example: Customizing the tone section

```php
add_filter( 'wp_mcp_ai_realtime_prompt_tone', function( $tone ) {
    return 'Speak in a calm, professional manner with occasional light humor.';
} );
```

## GPT-Realtime-Translate

Translates speech in real time between 70+ input languages and 13 output
languages.

### Supported output languages

Arabic, Chinese (Mandarin), Dutch, English, French, German, Hindi, Italian,
Japanese, Korean, Portuguese, Russian, Spanish.

### Configuration

- **Input language:** `realtime_translate_input_lang` (auto-detect by default)
- **Output language:** `realtime_translate_output_lang`

### Provider class

`WP_MCP_AI_OpenAI_Realtime_Translate_Client` — registered with the voice
controller alongside the main realtime client.

## GPT-Realtime-Whisper

Streaming speech-to-text with configurable latency.

### Configuration

- **Latency delay:** `realtime_whisper_latency_delay` (milliseconds)

Lower values produce faster transcription at the cost of accuracy; higher
values improve accuracy but increase perceived latency.

### Provider class

`WP_MCP_AI_OpenAI_Realtime_Whisper_Client` — registered with the voice
controller for streaming STT.

## Push-to-Talk (PTT) Mode

PTT mode is extended to WebRTC connections:

- **Hold to speak:** Audio captured while button is held
- **Release to process:** Buffer cleared and committed on release
- **Buffer management:** Clear/commit flow prevents stale audio accumulation

## Commentary Phase

When preambles are enabled (`realtime_preambles_enabled`), the assistant
can emit commentary during tool execution:

- `onCommentary` callback surfaces preamble text
- Progress display for tool calls
- CSS styles in `voice-chat.css` for commentary rendering

## Admin Settings

New settings added in v1.1.34 (Settings → Providers → Voice):

| Setting key | Default | Description |
|-------------|---------|-------------|
| `realtime_reasoning_effort` | `low` | Reasoning effort level for GPT-Realtime-2 |
| `realtime_preambles_enabled` | `true` | Enable preamble/commentary during tool calls |
| `voice_realtime_transport` | `webrtc` | Primary transport (`webrtc` or `websocket`) |
| `realtime_translate_input_lang` | `auto` | Input language for translation mode |
| `realtime_translate_output_lang` | `en` | Output language for translation mode |
| `realtime_whisper_latency_delay` | `200` | Latency delay for Whisper STT (ms) |
| `realtime_safety_identifier_enabled` | `true` | Enable hashed user ID in safety header |

## REST Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/mcp-ai/v1/realtime/token` | Mint ephemeral WebRTC token |
| `POST` | `/mcp-ai/v1/realtime/session` | Relay SDP for WebRTC negotiation |

Both endpoints require a valid WordPress nonce or assistant credential.

## wait_for_user Tool

See [`docs/reference/tools/wait-for-user-tool.md`](../../reference/tools/wait-for-user-tool.md)
for the dedicated tool reference covering the `wait_for_user` no-op tool
used for silence, background noise, and non-addressed audio handling.

## Troubleshooting

### Common issues

**Voice connection fails with WebRTC**
- Verify the server supports HTTPS (WebRTC requires secure context)
- Check that the ephemeral token endpoint returns a valid token
- Inspect browser console for ICE connection state errors

**WebSocket fallback not working**
- Ensure `voice_realtime_transport` is set to `websocket`
- Verify the OpenAI API key has Realtime API access
- Check for `OpenAI-Safety-Identifier` header in requests

**Translation not producing output**
- Confirm both input and output language settings are configured
- Verify the input audio contains clear speech in the selected language
- Increase recording volume if audio level is too low

### Debug logging

Enable `WP_MCP_AI_DEBUG` to capture voice connection lifecycle events:
```php
define( 'WP_MCP_AI_DEBUG', true );
```

Realtime connection logs appear under **Settings → NV oOS → Recent Activity**.

## Related Documentation

- [GPT-Realtime-2 Upgrade Proposal](../../project/proposals/GPT-REALTIME-2-UPGRADE-PROPOSAL.md)
- [GPT-Realtime-2 Implementation Plan](../../project/proposals/GPT-REALTIME-2-IMPLEMENTATION-PLAN.md) (1,166 lines)
- [Voice Chat Troubleshooting](../../operations/troubleshooting/chat/voice-chat-troubleshooting.md)
- [wait_for_user Tool Reference](../../reference/tools/wait-for-user-tool.md)
