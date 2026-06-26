# GPT-Realtime-2 Voice Models — Comprehensive Implementation Plan

**Date:** 2026-06-26
**Phase:** 3 — Implementation Planning
**Status:** Draft
**Author:** Zed AI Agent
**Proposal Reference:** `docs/project/proposals/GPT-REALTIME-2-UPGRADE-PROPOSAL.md`
**Version:** 1.0

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Epics & Stories](#2-epics--stories)
3. [File Inventory — Create / Modify / Delete](#3-file-inventory--create--modify--delete)
4. [Detailed Implementation Tasks](#4-detailed-implementation-tasks)
5. [Database & Settings Changes](#5-database--settings-changes)
6. [REST API Specification](#6-rest-api-specification)
7. [JavaScript Module Architecture](#7-javascript-module-architecture)
8. [Testing Strategy](#8-testing-strategy)
9. [Rollout & Migration Plan](#9-rollout--migration-plan)
10. [Risk Register](#10-risk-register)

---

## 1. Architecture Overview

### 1.1 Target Architecture (WebRTC-First)

```
┌─────────────────────────────────────────────────────────────────┐
│                        BROWSER (Client)                          │
│                                                                   │
│  ┌──────────┐  ┌──────────────┐  ┌────────────────────────────┐ │
│  │ Chat UI  │  │ WebRTC Svc   │  │ Translation / Transcription │ │
│  │ (chat.js)│  │ (new)        │  │ Services (new)              │ │
│  └────┬─────┘  └──────┬───────┘  └─────────────┬──────────────┘ │
│       │               │                         │                 │
│       │    ┌──────────┴──────────┐              │                 │
│       │    │  RTCPeerConnection  │              │                 │
│       │    │  - audio track (mic)│              │                 │
│       │    │  - remote stream    │              │                 │
│       │    │  - oai-events DC    │              │                 │
│       │    └──────────┬──────────┘              │                 │
└───────┼───────────────┼─────────────────────────┼─────────────────┘
        │               │ SDP offer/answer        │
        ▼               ▼                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                   WORDPRESS BACKEND (PHP)                        │
│                                                                   │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────────┐ │
│  │ Token Endpoint   │  │ SDP Relay        │  │ Voice REST     │ │
│  │ POST /realtime/  │  │ POST /realtime/  │  │ Controller     │ │
│  │ token            │  │ session          │  │ (update)       │ │
│  └────────┬─────────┘  └────────┬─────────┘  └───────┬───────┘ │
│           │                     │                      │          │
│  ┌────────┴─────────────────────┴──────────────────────┴───────┐ │
│  │              OpenAI Realtime Clients (PHP)                    │ │
│  │  ┌─────────────────┐ ┌──────────────┐ ┌──────────────────┐  │ │
│  │  │ Realtime Client │ │ Translate    │ │ Whisper Client   │  │ │
│  │  │ (rewrite)       │ │ Client (new) │ │ (new)            │  │ │
│  │  └─────────────────┘ └──────────────┘ └──────────────────┘  │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐ │
│  │                 Supporting Systems                            │ │
│  │  Admin Settings │ Tool Registry │ Credential Resolver         │ │
│  └──────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────────┐
│                     OPENAI API (GA)                               │
│                                                                   │
│  /v1/realtime/client_secrets  ← ephemeral token minting          │
│  /v1/realtime/calls           ← WebRTC SDP relay (unified)       │
│  /v1/realtime                 ← WebRTC peer / WebSocket connect  │
│  /v1/realtime/translations    ← translation sessions             │
│  (transcription sessions)     ← streaming transcription          │
└─────────────────────────────────────────────────────────────────┘
```

### 1.2 Session Format Migration — Beta → GA

```php
// ── OLD (beta — DEPRECATED May 12, 2026) ─────────────────
$payload = array(
    'model'               => 'gpt-realtime',
    'voice'               => 'marin',
    'modalities'          => array( 'text', 'audio' ),
    'input_audio_format'  => 'pcm16',
    'output_audio_format' => 'pcm16',
    'temperature'         => 0.8,
    'instructions'        => '...',
    'turn_detection'      => array( 'type' => 'server_vad', ... ),
    'tools'               => array( ... ),
);

// ── NEW (GA — after May 12, 2026) ────────────────────────
$payload = array(
    'session' => array(
        'type'              => 'realtime',
        'model'             => 'gpt-realtime-2',
        'output_modalities' => array( 'audio', 'text' ),
        'audio'             => array(
            'input'  => array(
                'format'         => array( 'type' => 'audio/pcm', 'rate' => 24000 ),
                'turn_detection' => array( 'type' => 'semantic_vad' ),
            ),
            'output' => array(
                'format' => array( 'type' => 'audio/pcm' ),
                'voice'  => 'marin',
            ),
        ),
        'reasoning'         => array( 'effort' => 'low' ),
        'instructions'      => '...',
        'tools'             => array( ... ),
    ),
);
```

### 1.3 Connection Flow Comparison

| Step | Old (Beta WebSocket) | New (GA WebRTC — Ephemeral) | New (GA WebRTC — Unified) |
|------|---------------------|---------------------------|--------------------------|
| 1 | Server calls `/v1/realtime/sessions` | Server calls `/v1/realtime/client_secrets` | Browser creates `RTCPeerConnection` |
| 2 | Returns `client_secret.value` token | Returns ephemeral key | Browser generates SDP offer |
| 3 | Browser opens `new WebSocket(wsUrl)` | Browser creates `RTCPeerConnection` | Browser POSTs SDP to NV oOS server |
| 4 | Manual PCM16 encoding per frame | Browser calls `getUserMedia`, adds track | Server POSTs SDP + session config to `/v1/realtime/calls` |
| 5 | `input_audio_buffer.append` events | Browser handles encoding via WebRTC | Server returns SDP answer to browser |
| 6 | Decode base64 audio deltas | `pc.ontrack` → `<audio>` element | Browser sets remote description |

---

## 2. Epics & Stories

### Epic 1: GA Session Format Migration *(Critical Path)*
*Goal: Replace beta session endpoints and format with GA equivalents. This unblocks all other work.*

- **Story 1.1:** Update `WP_MCP_AI_OpenAI_Realtime_Client` constants and endpoints to GA
  - Change `SESSION_ENDPOINT` → `/v1/realtime/client_secrets`
  - Change `WEBSOCKET_BASE` → GA WebSocket URL (fallback only)
  - Remove `OpenAI-Beta` header
  - Add constants for `/v1/realtime/calls` and translation/transcription endpoints
  - Deps: None | Complexity: Small

- **Story 1.2:** Migrate session payload from flat beta format to GA nested format
  - Nest `audio.input`, `audio.output` configs
  - Add `session.type: "realtime"` wrapper
  - Change `modalities` → `output_modalities`
  - Change `input_audio_format` → `audio.input.format`
  - Deps: Story 1.1 | Complexity: Medium

- **Story 1.3:** Update JS `chat-voice-realtime-service.js` to handle GA server events
  - Update event type names for GA format
  - Handle new `response.output_audio.delta` (vs old `response.audio.delta`)
  - Handle `response.output_audio_transcript.delta`
  - Handle `conversation.item.input_audio_transcription.completed`
  - Deps: Story 1.2 | Complexity: Medium

- **Story 1.4:** Add `OpenAI-Safety-Identifier` header support
  - Hash user ID server-side
  - Pass in ephemeral token creation request
  - Add admin setting for safety identifier opt-in
  - Deps: Story 1.1 | Complexity: Small

- **Story 1.5:** Update admin settings for GA session configuration
  - Add new voice options (`cedar`; remove deprecated `fable`/`nova`/`onyx`)
  - Update model dropdown with `gpt-realtime-2`, `gpt-realtime-1.5`
  - Deps: Story 1.2 | Complexity: Small

### Epic 2: WebRTC Transport *(High Value)*
*Goal: Add WebRTC as primary transport, keeping WebSocket as configurable fallback.*

- **Story 2.1:** Create `chat-webrtc-service.js` — WebRTC peer connection manager
  - `RTCPeerConnection` lifecycle (create, connect, disconnect, reconnect)
  - Microphone track via `getUserMedia`
  - Remote audio track → `<audio>` element
  - Data channel `"oai-events"` for control messages
  - ICE connection state monitoring
  - Deps: None (parallel with Epic 1) | Complexity: Large

- **Story 2.2:** Create REST endpoint `POST /mcp-ai/v1/realtime/token`
  - Server mints ephemeral token via `POST /v1/realtime/client_secrets`
  - Returns `{ client_secret: { value, expires_at } }` to browser
  - Capability-gated: `edit_posts` minimum
  - Deps: Story 1.1 | Complexity: Small

- **Story 2.3:** Create REST endpoint `POST /mcp-ai/v1/realtime/session`
  - Browser posts SDP offer → server creates session via `/v1/realtime/calls`
  - Returns SDP answer to browser
  - Capability-gated; API key stays server-side
  - Deps: Story 1.1 | Complexity: Medium

- **Story 2.4:** Integrate WebRTC service into voice mode integration
  - `chat-voice-mode-integration.js` selects transport based on config
  - Feature-detect WebRTC support; degrade to WebSocket
  - Add admin setting `voice_realtime_transport` (WebRTC / WebSocket)
  - Deps: Stories 2.1, 2.2, 2.3 | Complexity: Medium

- **Story 2.5:** ICE reconnection and error handling
  - Handle `iceconnectionstatechange` events
  - Exponential backoff reconnection (max 3 attempts)
  - User-visible connection status indicator
  - Deps: Story 2.1 | Complexity: Medium

### Epic 3: Model Upgrade — gpt-realtime-2 *(High Value)*
*Goal: Support gpt-realtime-2 reasoning, preambles, 128K context, and message channels.*

- **Story 3.1:** Add reasoning effort to session config and admin UI
  - `reasoning.effort` field: `minimal` / `low` / `medium` / `high` / `xhigh`
  - Admin dropdown for default reasoning effort
  - Per-session override via REST API
  - Deps: Story 1.2 | Complexity: Small

- **Story 3.2:** Implement preamble configuration
  - Admin settings: enable/disable preambles, configure style
  - System prompt template includes `# Preambles` section
  - Render `commentary` phase messages in chat UI
  - Deps: Story 1.2 | Complexity: Medium

- **Story 3.3:** Handle response phases (commentary vs final_answer)
  - Parse `response.done` event for `phase` field
  - Render commentary with distinct styling (italic, muted, transient)
  - Render final_answer as standard chat bubble
  - JS event handling in WebRTC data channel
  - Deps: Stories 2.1, 2.4 | Complexity: Medium

- **Story 3.4:** Add image input support in voice mode
  - Allow `input_image` content parts in `conversation.item.create`
  - Integrate with existing attachment system in `chat.js`
  - Deps: Story 2.1 | Complexity: Small

- **Story 3.5:** Add context window usage indicator
  - Estimate token usage in current voice session
  - Show progress bar (used / 128K) in chat UI
  - Warn at 90% utilization
  - Deps: Story 2.1 | Complexity: Small

- **Story 3.6:** Rewrite system prompt template for gpt-realtime-2
  - Implement labeled-section template in `get_assistant_instructions()`
  - Add filters for each section: `wp_mcp_ai_realtime_prompt_{section}`
  - Add admin UI for editing prompt template sections
  - Deps: Story 1.2 | Complexity: Medium

### Epic 4: Translation & Transcription Models *(Medium Value)*
*Goal: Add GPT-Realtime-Translate and GPT-Realtime-Whisper support.*

- **Story 4.1:** Create `WP_MCP_AI_OpenAI_Realtime_Translate_Client`
  - New PHP class implementing `WP_MCP_AI_Voice_Provider`
  - Dedicated endpoint: `/v1/realtime/translations`
  - Session config: `type: "translation"`, `input_language`, `output_language`
  - Supported language lists (70+ input, 13 output)
  - Deps: Story 1.1 | Complexity: Medium

- **Story 4.2:** Create `chat-translation-service.js`
  - WebRTC connection to translation endpoint
  - Source language selector dropdown
  - Target language selector dropdown
  - Streaming translated audio output
  - Translated transcript display
  - Deps: Stories 2.1, 4.1 | Complexity: Medium

- **Story 4.3:** Create `WP_MCP_AI_OpenAI_Realtime_Whisper_Client`
  - New PHP class implementing `WP_MCP_AI_Voice_Provider`
  - Transcription session type for streaming STT
  - Configurable latency delay parameter
  - Deps: Story 1.1 | Complexity: Small

- **Story 4.4:** Create `chat-transcription-realtime-service.js`
  - Streaming transcript deltas from realtime audio
  - Partial → final transcript progression
  - Live caption overlay in chat UI
  - Deps: Stories 2.1, 4.3 | Complexity: Medium

- **Story 4.5:** Add translation/transcription mode selectors to voice UI
  - Mode toggle: Chat / Translate / Transcribe
  - Language selectors (source + target for translate)
  - Integration with `chat-voice-mode-integration.js`
  - Deps: Stories 4.2, 4.4 | Complexity: Small

### Epic 5: Tool System Enhancement for Voice *(Medium Value)*
*Goal: Optimize tool calling for voice interactions.*

- **Story 5.1:** Add `wait_for_user` built-in tool
  - No-op tool for handling silence/background noise
  - Tool definition with empty parameters
  - System prompt instructions for when to call
  - Registration in `tools-init.php`
  - Deps: None | Complexity: Small

- **Story 5.2:** Configure parallel tool calling
  - Enable parallel tool execution in session config
  - Handle multiple simultaneous `function_call` outputs
  - UI: show parallel tool calls as list with status indicators
  - Deps: Stories 1.2, 2.4 | Complexity: Medium

- **Story 5.3:** Add per-tool preamble phrases
  - Extend tool definition format to include `preamble_samples`
  - Pass preamble samples in session tool config
  - Model uses preamble samples before calling tools
  - Deps: Story 1.2 | Complexity: Small

- **Story 5.4:** Implement sideband control channel for tool security
  - Tool execution happens server-side via REST API
  - Browser sends function call outputs via data channel
  - Server validates and executes tools; returns results
  - Prevents tool logic from being exposed in browser
  - Deps: Stories 2.1, 2.2 | Complexity: Large

- **Story 5.5:** Add tool output JSON envelope formatting
  - Wrap tool outputs in `{ response_text, require_repeat_verbatim, format }`
  - System prompt instructs model to respect `require_repeat_verbatim`
  - Improves verbatim delivery reliability
  - Deps: Story 3.6 | Complexity: Small

### Epic 6: Polish & Documentation *(Quality)*
*Goal: Final quality improvements, testing, and documentation.*

- **Story 6.1:** Add per-session cost tracking for realtime
  - Track audio input/output tokens or minutes
  - Display cost estimate in chat UI
  - Log to usage tracker database
  - Deps: Stories 2.4, 3.1 | Complexity: Medium

- **Story 6.2:** Push-to-talk (PTT) mode
  - Disable VAD, use application-level gate (spacebar)
  - WebRTC: `input_audio_buffer.clear` on push down
  - WebRTC: `input_audio_buffer.commit` on push up
  - UI: PTT button with hold-to-speak behavior
  - Deps: Story 2.4 | Complexity: Medium

- **Story 6.3:** Add voice-specific error recovery messages
  - "I'm having trouble with that right now" — graceful degradation
  - Tool failure recovery instructions in system prompt
  - Unclear audio handling: "Could you repeat that?"
  - Deps: Story 3.6 | Complexity: Small

- **Story 6.4:** Admin settings consolidation
  - Voice settings tab reorganization
  - Transport mode selector (WebRTC / WebSocket)
  - Per-model settings (reasoning, voice, languages)
  - Deps: Across all epics | Complexity: Medium

- **Story 6.5:** Testing — PHP unit tests
  - Test session config generation for all three models
  - Test token minting endpoint
  - Test SDP relay endpoint
  - Test capability checks
  - Deps: Stories 1.2, 2.2, 2.3 | Complexity: Medium

- **Story 6.6:** Testing — JS integration tests
  - WebRTC connection lifecycle
  - Event handling for all three models
  - Voice mode UI state transitions
  - Fallback to WebSocket when WebRTC unavailable
  - Deps: Stories 2.1, 2.4 | Complexity: Medium

- **Story 6.7:** Documentation updates
  - Update `docs/features/` with voice documentation
  - Update `docs/reference/` with REST endpoint docs
  - Update `readme.txt` with new voice capabilities
  - Update `CHANGELOG.md`
  - Deps: Across all epics | Complexity: Small

---

## 3. File Inventory — Create / Modify / Delete

### 3.1 Files to CREATE

| # | File Path | Purpose | Epic |
|---|-----------|---------|------|
| 1 | `includes/class-wp-mcp-ai-openai-realtime-translate-client.php` | Translation session client (PHP) | 4 |
| 2 | `includes/class-wp-mcp-ai-openai-realtime-whisper-client.php` | Transcription session client (PHP) | 4 |
| 3 | `assets/js/chat-webrtc-service.js` | WebRTC peer connection manager | 2 |
| 4 | `assets/js/chat-translation-service.js` | Translation session frontend | 4 |
| 5 | `assets/js/chat-transcription-realtime-service.js` | Streaming transcription frontend | 4 |
| 6 | `includes/rest/class-wp-mcp-ai-rest-realtime-token.php` | Token minting REST endpoint | 2 |
| 7 | `includes/rest/class-wp-mcp-ai-rest-realtime-session.php` | SDP relay REST endpoint | 2 |
| 8 | `includes/tools/class-wp-mcp-ai-tool-wait-for-user.php` | `wait_for_user` no-op tool | 5 |
| 9 | `tests/test-openai-realtime-ga-migration.php` | PHPUnit tests for GA migration | 6 |
| 10 | `tests/test-realtime-translate-client.php` | PHPUnit tests for translate client | 6 |
| 11 | `tests/test-realtime-whisper-client.php` | PHPUnit tests for whisper client | 6 |
| 12 | `docs/features/voice/realtime-2-upgrade.md` | Feature documentation | 6 |
| 13 | `docs/reference/rest-api-realtime.md` | REST API reference for realtime | 6 |

### 3.2 Files to MODIFY

| # | File Path | Changes | Epic |
|---|-----------|---------|------|
| 1 | `includes/class-wp-mcp-ai-openai-realtime-client.php` | **Major rewrite** — GA endpoints, nested session format, WebRTC token minting, new model defaults, Safety-Identifier header, reasoning effort, preamble config, image input | 1, 3 |
| 2 | `assets/js/chat-voice-realtime-service.js` | Update for GA event names, keep as WebSocket fallback | 1 |
| 3 | `assets/js/chat-voice-mode-integration.js` | WebRTC integration, transport selection, model selector, reasoning UI, translation/transcription mode toggles | 2, 3, 4 |
| 4 | `assets/js/chat.js` | Commentary phase rendering, parallel tool call display, context window meter, image input in voice mode | 3, 5 |
| 5 | `includes/class-wp-mcp-ai-admin-settings.php` | New settings: `openai_realtime_model`, `realtime_reasoning_effort`, `realtime_preambles_enabled`, `voice_realtime_transport`, `realtime_translate_input_lang`, `realtime_translate_output_lang`, voice list update | 1, 3, 4, 6 |
| 6 | `includes/class-wp-mcp-ai-rest.php` | Register new REST routes for `/realtime/token` and `/realtime/session` | 2 |
| 7 | `includes/class-wp-mcp-ai-plugin.php` | Register new client classes, enqueue new JS files | 1, 2 |
| 8 | `includes/tools-init.php` | Register `wait_for_user` tool | 5 |
| 9 | `includes/rest/class-wp-mcp-ai-rest-voice-controller.php` | Register translate and whisper providers | 4 |
| 10 | `assets/css/` (relevant files) | New styles: commentary phase, context meter, WebRTC status, translation panel | 3, 4, 6 |
| 11 | `docs/ROADMAP.md` | Add GPT-Realtime-2 upgrade to v1.2.0 milestone | 6 |
| 12 | `CHANGELOG.md` | Entry for voice model upgrade | 6 |
| 13 | `readme.txt` | Update feature list with new voice capabilities | 6 |

### 3.3 Files to KEEP (no changes needed)

| File Path | Reason |
|-----------|--------|
| `assets/js/chat-browser-voice-service.js` | Browser Web Speech API fallback — unaffected |
| `includes/class-wp-mcp-ai-openai-client.php` | Chat Completions client — unaffected |
| `includes/class-wp-mcp-ai-enhanced-openai-client.php` | Rate-limited wrapper — unaffected |
| `includes/class-wp-mcp-ai-gemini-live-client.php` | Gemini Live provider — parallel, not replaced |
| `assets/js/chat-audio-service.js` | Audio utilities — reusable by new services |
| `assets/js/chat-attachments-service.js` | Attachment handling — reusable |

---

## 4. Detailed Implementation Tasks

### 4.1 Epic 1: GA Session Format Migration

#### Story 1.1 — Update PHP Client Constants & Endpoints

**File:** `includes/class-wp-mcp-ai-openai-realtime-client.php`

```php
// ── CONSTANTS TO CHANGE ──────────────────────────────────────────
const SESSION_ENDPOINT = 'https://api.openai.com/v1/realtime/client_secrets';
// OLD: const SESSION_ENDPOINT = 'https://api.openai.com/v1/realtime/sessions';

const CALLS_ENDPOINT = 'https://api.openai.com/v1/realtime/calls';
// NEW constant for unified WebRTC interface

const TRANSLATION_ENDPOINT = 'https://api.openai.com/v1/realtime/translations';
// NEW constant for translation sessions

const WEBSOCKET_BASE = 'wss://api.openai.com/v1/realtime';
// Unchanged for WebSocket fallback

const DEFAULT_MODEL = 'gpt-realtime-2';
// OLD: const DEFAULT_MODEL = 'gpt-realtime';

// ── REMOVE ───────────────────────────────────────────────────────
// Do NOT send: 'OpenAI-Beta: realtime=v1' header
```

**Methods to add:**
- `create_ephemeral_token( $assistant_id, $options )` — calls `POST /v1/realtime/client_secrets`
- `create_unified_session( $sdp_offer, $assistant_id, $options )` — calls `POST /v1/realtime/calls`
- `build_safety_identifier()` — returns hashed user ID for `OpenAI-Safety-Identifier` header

**Methods to update:**
- `build_request_headers()` — remove beta header; add Safety-Identifier
- `create_session()` — keeps WebSocket flow as fallback; delegate to `create_ephemeral_token()` for WebRTC

#### Story 1.2 — Migrate Session Payload Format

**File:** `includes/class-wp-mcp-ai-openai-realtime-client.php`

New `build_session_payload()` method:

```php
protected function build_session_payload( $assistant_id, $options ) {
    $model    = $this->resolve_model( $options );
    $voice    = $this->resolve_voice( $options );
    $vad      = $this->get_vad_config();
    $tools    = $this->get_assistant_tools_for_realtime( $assistant_id );
    $reasoning = isset( $options['reasoning_effort'] )
        ? sanitize_text_field( $options['reasoning_effort'] )
        : 'low';

    return array(
        'session' => array(
            'type'              => 'realtime',
            'model'             => $model,
            'output_modalities' => array( 'audio', 'text' ),
            'audio'             => array(
                'input'  => array(
                    'format'         => array(
                        'type' => 'audio/pcm',
                        'rate' => 24000,
                    ),
                    'turn_detection' => $vad,
                ),
                'output' => array(
                    'format' => array( 'type' => 'audio/pcm' ),
                    'voice'  => $voice,
                ),
            ),
            'reasoning'         => array( 'effort' => $reasoning ),
            'instructions'      => $this->get_assistant_instructions( $assistant_id ),
            'tools'             => $tools,
        ),
    );
}
```

#### Story 1.3 — Update JS Event Handling for GA

**File:** `assets/js/chat-voice-realtime-service.js`

Event name mapping (old → new):
| Old Event | New GA Event | Notes |
|-----------|-------------|-------|
| `response.audio.delta` | `response.output_audio.delta` | Audio output chunks |
| `response.audio.done` | `response.output_audio.done` | Audio output complete |
| `response.audio_transcript.delta` | `response.output_audio_transcript.delta` | Transcript of audio output |
| `conversation.item.input_audio_transcription.completed` | Same (unchanged) | User speech transcript |
| `response.function_call_arguments.done` | Same (unchanged) | Function call arguments |
| `session.created` | Same (unchanged) | Session ready |
| `session.updated` | Same (unchanged) | Session config applied |

#### Story 1.5 — Update Voice Options

**File:** `includes/class-wp-mcp-ai-openai-realtime-client.php`

```php
const AVAILABLE_VOICES = array(
    'alloy'   => 'Alloy (neutral, balanced)',
    'ash'     => 'Ash (warm, measured)',
    'ballad'  => 'Ballad (emotional, musical)',
    'coral'   => 'Coral (bright, crisp)',
    'cedar'   => 'Cedar (new — natural, warm) ⭐ recommended',
    'echo'    => 'Echo (deep, resonant)',
    'marin'   => 'Marin (warm, engaging) ⭐ recommended',
    'sage'    => 'Sage (gentle, wise)',
    'shimmer' => 'Shimmer (clear, friendly)',
    'verse'   => 'Verse (poetic, rhythmic)',
);
// REMOVED: 'fable', 'nova', 'onyx' — deprecated by OpenAI
```

### 4.2 Epic 2: WebRTC Transport

#### Story 2.1 — WebRTC Service (JS)

**File (new):** `assets/js/chat-webrtc-service.js`

Key API surface:
```javascript
const wpMcpAiWebRTC = {
    // Check browser support
    isSupported: function() { ... },

    // Create a peer connection with audio tracks
    createPeerConnection: function(config) { ... },

    // Connect using ephemeral token flow
    connectWithToken: function(tokenEndpoint, buildHeaders, sessionConfig, callbacks) { ... },

    // Connect using unified interface flow
    connectWithRelay: function(relayEndpoint, buildHeaders, sessionConfig, callbacks) { ... },

    // Disconnect and cleanup
    disconnect: function(key) { ... },

    // Send event over data channel
    sendEvent: function(key, event) { ... },

    // Mute/unmute microphone
    setMuted: function(key, muted) { ... },

    // Get connection state
    getState: function(key) { ... },
};
```

Implementation notes:
- Use `RTCPeerConnection` with `iceServers` (configurable STUN/TURN)
- Audio track from `navigator.mediaDevices.getUserMedia({ audio: true })`
- Remote track → create `<audio>` element with `autoplay`
- Data channel name: `"oai-events"` (required by OpenAI)
- Handle `iceconnectionstatechange`, `connectionstatechange`, `track` events
- Reconnection: exponential backoff (1s, 2s, 4s), max 3 attempts

#### Story 2.2 — Token Minting REST Endpoint

**File (new):** `includes/rest/class-wp-mcp-ai-rest-realtime-token.php`

```php
class WP_MCP_AI_REST_Realtime_Token {
    public function register_routes() {
        register_rest_route( 'mcp-ai/v1', '/realtime/token', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_token' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'args' => array(
                'assistant_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    public function create_token( $request ) {
        $assistant_id = $request->get_param( 'assistant_id' );
        $client       = new WP_MCP_AI_OpenAI_Realtime_Client();
        $result       = $client->create_ephemeral_token( $assistant_id );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $result->get_error_message(),
            ), 500 );
        }

        return rest_ensure_response( $result );
    }
}
```

#### Story 2.3 — SDP Relay REST Endpoint

**File (new):** `includes/rest/class-wp-mcp-ai-rest-realtime-session.php`

```php
class WP_MCP_AI_REST_Realtime_Session {
    public function register_routes() {
        register_rest_route( 'mcp-ai/v1', '/realtime/session', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'relay_session' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ));
    }

    public function relay_session( $request ) {
        // Accept raw SDP body + assistant_id query param
        $sdp_offer    = $request->get_body();
        $assistant_id = absint( $request->get_param( 'assistant_id' ) );

        $client = new WP_MCP_AI_OpenAI_Realtime_Client();
        $result = $client->create_unified_session( $sdp_offer, $assistant_id );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( $result->get_error_message(), 500 );
        }

        // Return raw SDP answer
        return new WP_REST_Response( $result, 200, array(
            'Content-Type' => 'application/sdp',
        ));
    }
}
```

### 4.3 Epic 3: Model Upgrade — gpt-realtime-2

#### Story 3.6 — Structured Prompt Template

**File:** `includes/class-wp-mcp-ai-openai-realtime-client.php`

Replace `get_assistant_instructions()` output with:

```php
protected function get_assistant_instructions( $assistant_id ) {
    $base_instructions = $this->get_base_assistant_prompt( $assistant_id );

    $template = <<<PROMPT
# Role and Objective
You are a helpful voice assistant integrated into a WordPress site.
{$base_instructions['role']}

# Personality and Tone
- Friendly, calm, and approachable.
- Warm, concise, confident — never fawning.
- 2–3 sentences per turn for direct answers.

# Language
- Default to English.
- Do not infer language from accent alone.
- Only switch languages if the user explicitly asks.

# Reasoning
- For direct answers and simple lookups, respond quickly without reasoning.
- For multi-step tasks, tool decisions, or troubleshooting, reason before acting.
- Do not reason when audio is unclear — ask for clarification.

# Message Channels
- Use commentary channel for preambles and tool-call progress.
- Use final channel for the user-facing response.
- Keep commentary short: one sentence maximum.

# Preambles
- Use when about to call a tool that may take noticeable time.
- Keep natural and concise. Vary wording across turns.
- Do not use for direct answers, corrections, or unclear audio.
- Preferred: "I'll check that now." "Let me look that up."

# Verbosity
- Direct answers: 1–2 short sentences.
- Tool results: Summarize result first, then give next action.
- Troubleshooting: One step at a time unless user asks for full procedure.

# Tools
- Use only tools explicitly provided in the current tool list.
- For read-only tools: call when intent and required fields are clear.
- For write tools: summarize intended action and ask for confirmation.
- For exact identifiers: confirm digit-by-digit before calling tools.
- If a tool fails: briefly explain in user-friendly language, give next step.

# Unclear Audio
- Only respond to clear audio or text.
- If audio is unclear, ask "Sorry, could you repeat that?"
- Do not guess, reason, or call tools on unclear audio.

# Entity Capture
- Collect one value at a time.
- Convert clearly spoken digits into numeric values.
- Confirm high-precision identifiers digit-by-digit before tool calls.

# Long Context Behavior
- Use the most recent information for decisions.
- Distinguish current state from historical background.
- When sources conflict, prefer the most recently retrieved source.

# Escalation
- Escalate to human if: safety risk, user explicitly requests, repeated failures.
- Say: "Let me connect you with someone who can help further."
PROMPT;

    return apply_filters(
        'wp_mcp_ai_openai_realtime_instructions',
        $template,
        $assistant_id
    );
}
```

### 4.4 Epic 4: Translation & Transcription

#### Story 4.1 — Translate Client (PHP)

**File (new):** `includes/class-wp-mcp-ai-openai-realtime-translate-client.php`

Key design:
```php
class WP_MCP_AI_OpenAI_Realtime_Translate_Client implements WP_MCP_AI_Voice_Provider {
    // Supported input languages (70+)
    const INPUT_LANGUAGES = array( 'en', 'es', 'fr', 'de', 'ja', 'ko', 'zh', ... );
    
    // Supported output languages (13)
    const OUTPUT_LANGUAGES = array( 'en', 'es', 'fr', 'de', 'ja', 'ko', 'zh', 'ar', 'hi', 'pt', 'it', 'nl', 'pl' );

    public function get_slug() { return 'openai_realtime_translate'; }
    public function get_transport_mode() { return 'realtime'; }

    public function create_session( $assistant_id, $options = array() ) {
        // Uses dedicated /v1/realtime/translations endpoint
        // Session type: "translation"
        // Config: input_language, output_language
    }
}
```

#### Story 4.3 — Whisper Client (PHP)

**File (new):** `includes/class-wp-mcp-ai-openai-realtime-whisper-client.php`

```php
class WP_MCP_AI_OpenAI_Realtime_Whisper_Client implements WP_MCP_AI_Voice_Provider {
    const DEFAULT_LATENCY_DELAY = 1.0; // seconds — lower = earlier partial text

    public function get_slug() { return 'openai_realtime_whisper'; }
    public function get_transport_mode() { return 'realtime'; }

    public function create_session( $assistant_id, $options = array() ) {
        // Transcription session — no model-generated responses
        // Emits transcript deltas only
    }
}
```

### 4.5 Epic 5: Tool System Enhancement

#### Story 5.1 — wait_for_user Tool

**File (new):** `includes/tools/class-wp-mcp-ai-tool-wait-for-user.php`

```php
class WP_MCP_AI_Tool_Wait_For_User extends WP_MCP_AI_Tool_Base {
    public function get_slug() { return 'wait_for_user'; }

    public function get_definition() {
        return array(
            'name'        => 'Wait for User',
            'description' => 'Call this when the latest audio does not need a spoken response — silence, background noise, hold music, TV audio, side conversation, or speech not addressed to the assistant. This tool helps end the turn without a spoken reply.',
            'required_capability' => 'read',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(),
                'required'   => array(),
            ),
        );
    }

    public function execute( $arguments, $context ) {
        return array(
            'success' => true,
            'action'  => 'waiting',
            'message' => 'No response needed. Waiting for user input.',
        );
    }
}
```

Register in `tools-init.php`:
```php
$registry->register( 'wait_for_user', new WP_MCP_AI_Tool_Wait_For_User() );
```

---

## 5. Database & Settings Changes

### 5.1 New WordPress Options

| Option Key | Type | Default | Description |
|-----------|------|---------|-------------|
| `wp_mcp_ai_openai_realtime_model` | string | `gpt-realtime-2` | Active realtime model |
| `wp_mcp_ai_realtime_reasoning_effort` | string | `low` | Reasoning effort level |
| `wp_mcp_ai_realtime_preambles_enabled` | bool | `true` | Enable preamble generation |
| `wp_mcp_ai_voice_realtime_transport` | string | `webrtc` | Transport: `webrtc` or `websocket` |
| `wp_mcp_ai_realtime_translate_input_lang` | string | `en` | Default source language |
| `wp_mcp_ai_realtime_translate_output_lang` | string | `es` | Default target language |
| `wp_mcp_ai_realtime_whisper_latency_delay` | float | `1.0` | Latency delay in seconds |
| `wp_mcp_ai_realtime_safety_identifier_enabled` | bool | `false` | Send safety identifier |

### 5.2 Modified Options

| Option Key | Change |
|-----------|--------|
| `wp_mcp_ai_openai_realtime_voice` | Remove deprecated voice options from validation |

### 5.3 Transients

| Key Pattern | TTL | Purpose |
|------------|-----|---------|
| `wp_mcp_ai_realtime_ephemeral_{assistant_id}_{user_id}` | 50s | Cached ephemeral token |
| `wp_mcp_ai_realtime_session_{assistant_id}_{user_id}` | 50s | Cached session config (WebSocket fallback) |

---

## 6. REST API Specification

### 6.1 New Endpoints

#### `POST /mcp-ai/v1/realtime/token`

Mints an ephemeral token for browser WebRTC connection.

**Permission:** `edit_posts`
**Request:**
```json
{
  "assistant_id": 123,
  "model": "gpt-realtime-2",
  "voice": "marin",
  "reasoning_effort": "low"
}
```

**Response (200):**
```json
{
  "client_secret": {
    "value": "ek_abc123...",
    "expires_at": 1717796400
  },
  "model": "gpt-realtime-2",
  "voice": "marin"
}
```

**Error (500):**
```json
{
  "success": false,
  "message": "OpenAI Realtime session creation failed (HTTP 401)."
}
```

#### `POST /mcp-ai/v1/realtime/session`

Relays browser SDP offer to OpenAI and returns SDP answer (unified interface).

**Permission:** `edit_posts`
**Request Body:** Raw SDP text (`application/sdp`)
**Query Params:** `assistant_id` (int), `model` (string, optional)

**Response (200):** Raw SDP answer text (`application/sdp`)

**Error (500):** Plain text error message

### 6.2 Modified Endpoints

#### `POST /mcp-ai/v1/voice/session`

Updated to support new session format. Accepts additional params:
- `transport` — `webrtc` or `websocket`
- `model` — `gpt-realtime-2`, `gpt-realtime-translate`, `gpt-realtime-whisper`
- `reasoning_effort` — for `gpt-realtime-2`
- `input_language`, `output_language` — for `gpt-realtime-translate`

---

## 7. JavaScript Module Architecture

### 7.1 Module Dependency Graph

```
chat.js (main chat app)
├── chat-webrtc-service.js (NEW)         ← primary voice transport
│   └── Browser APIs: RTCPeerConnection, getUserMedia
├── chat-voice-realtime-service.js (UPDATED) ← WebSocket fallback
│   └── Browser APIs: WebSocket, AudioContext
├── chat-translation-service.js (NEW)    ← translation sessions
│   └── chat-webrtc-service.js
├── chat-transcription-realtime-service.js (NEW) ← transcription
│   └── chat-webrtc-service.js
├── chat-voice-mode-integration.js (UPDATED) ← voice UI orchestration
│   ├── chat-webrtc-service.js
│   ├── chat-voice-realtime-service.js
│   ├── chat-translation-service.js
│   ├── chat-transcription-realtime-service.js
│   └── chat-browser-voice-service.js (unchanged)
├── chat-audio-service.js (unchanged)    ← audio playback utilities
├── chat-browser-voice-service.js (unchanged) ← Web Speech API fallback
├── chat-attachments-service.js (unchanged)
└── chat-http-client-service.js (unchanged)
```

### 7.2 Enqueue Changes

**File:** `includes/class-wp-mcp-ai-plugin.php`

```php
// NEW scripts to enqueue
wp_enqueue_script(
    'wp-mcp-ai-chat-webrtc',
    WP_MCP_AI_URL . 'assets/js/chat-webrtc-service.js',
    array(), // No dependencies — uses browser APIs
    WP_MCP_AI_VERSION,
    true
);

wp_enqueue_script(
    'wp-mcp-ai-chat-translation',
    WP_MCP_AI_URL . 'assets/js/chat-translation-service.js',
    array( 'wp-mcp-ai-chat-webrtc' ),
    WP_MCP_AI_VERSION,
    true
);

wp_enqueue_script(
    'wp-mcp-ai-chat-transcription-realtime',
    WP_MCP_AI_URL . 'assets/js/chat-transcription-realtime-service.js',
    array( 'wp-mcp-ai-chat-webrtc' ),
    WP_MCP_AI_VERSION,
    true
);
```

### 7.3 Config Localization (wp_add_inline_script)

```php
wp_add_inline_script( 'wp-mcp-ai-chat-webrtc', '
    window.wpMcpAiWebRTCConfig = ' . wp_json_encode( array(
        'tokenEndpoint'    => rest_url( 'mcp-ai/v1/realtime/token' ),
        'sessionEndpoint'  => rest_url( 'mcp-ai/v1/realtime/session' ),
        'transportMode'    => $settings['voice_realtime_transport'] ?? 'webrtc',
        'iceServers'       => array(
            array( 'urls' => 'stun:stun.l.google.com:19302' ),
        ),
        'reconnectMaxAttempts' => 3,
        'reconnectBaseDelayMs' => 1000,
    ) ),
    'before'
);
```

---

## 8. Testing Strategy

### 8.1 PHP Unit Tests

**File (new):** `tests/test-openai-realtime-ga-migration.php`

Test cases:
1. `test_session_payload_has_ga_format()` — payload contains `session.type: "realtime"` wrapper
2. `test_session_payload_has_nested_audio_config()` — `audio.input.format`, `audio.output.voice`
3. `test_session_payload_has_reasoning_effort()` — `reasoning.effort` is set
4. `test_session_payload_has_output_modalities()` — `output_modalities` instead of `modalities`
5. `test_ephemeral_token_endpoint_is_ga()` — calls `/v1/realtime/client_secrets`
6. `test_beta_header_not_sent()` — no `OpenAI-Beta` header in request
7. `test_safety_identifier_header_sent()` — `OpenAI-Safety-Identifier` present when enabled
8. `test_create_session_requires_capability()` — 403 for subscribers
9. `test_voice_list_excludes_deprecated()` — `fable`, `nova`, `onyx` not in list
10. `test_default_model_is_realtime_2()` — `DEFAULT_MODEL === 'gpt-realtime-2'`

**File (new):** `tests/test-realtime-translate-client.php`
**File (new):** `tests/test-realtime-whisper-client.php`

### 8.2 JavaScript Tests

Focus areas:
- WebRTC service: `isSupported()` feature detection across browsers
- Event handling: parse GA event types correctly
- Transport fallback: WebRTC → WebSocket degradation
- Voice mode UI: state transitions (idle → connecting → active → listening → processing)
- Translation service: language selection, audio routing
- Transcription service: partial → final text progression

### 8.3 Manual Integration Testing

1. **WebRTC connection test:** Start voice session → verify audio input/output → end session
2. **WebSocket fallback test:** Disable WebRTC in settings → verify WebSocket voice works
3. **Reasoning test:** Set `xhigh` reasoning → ask complex multi-step question → verify reasoning indicator
4. **Preamble test:** Ask a question requiring tool call → verify "I'll check that now" appears
5. **Translation test:** Select Spanish → English → speak in Spanish → verify English output
6. **Transcription test:** Enable transcription mode → speak → verify live captions
7. **Tool calling test:** Ask "What's my order status?" → verify tool result in voice session
8. **Interruption test:** Speak while model is responding → verify graceful interruption
9. **Error recovery test:** Kill network mid-session → verify reconnection message
10. **Browser compatibility:** Chrome, Firefox, Safari, Edge (latest 2 versions)

---

## 9. Rollout & Migration Plan

### 9.1 Phase Sequence

```
Week 1–2:  Epic 1 (GA Migration)     ← CRITICAL — unblocks everything
Week 2–3:  Epic 2 (WebRTC Transport)  ← Can partially overlap with Epic 1
Week 3–4:  Epic 3 (gpt-realtime-2)    ← Depends on Epic 1
Week 4–5:  Epic 4 (Translate/Whisper) ← Can run parallel with Epic 5
Week 5–6:  Epic 5 (Tool Enhancement)  ← Depends on Epic 1, 3
Week 6–7:  Epic 6 (Polish & Docs)     ← Integration phase
```

### 9.2 Migration Steps for Existing Users

1. **Auto-detect old config:** On plugin update, check if `voice_mode` is `realtime` with `openai` provider
2. **Show admin notice:** "NV oOS voice has been upgraded to GPT-Realtime-2 with WebRTC. Your settings have been migrated. [Review Settings]"
3. **Migrate settings:**
   - `openai_realtime_model`: `gpt-realtime` → `gpt-realtime-2`
   - `voice_realtime_transport`: auto-set to `webrtc`
   - `realtime_reasoning_effort`: auto-set to `low`
4. **Keep WebSocket as fallback:** Existing WebSocket config preserved; user can switch manually
5. **No data loss:** Voice sessions are ephemeral; no stored data affected

### 9.3 Rollback Plan

If critical issues arise:
1. Set `voice_realtime_transport` option to `websocket` to disable WebRTC
2. Revert `DEFAULT_MODEL` to `gpt-realtime-1.5` if `gpt-realtime-2` has issues
3. GA session format is backward-compatible with the GA endpoint — no rollback needed for format
4. Deactivate new JS services via `wp_mcp_ai_voice_webrtc_enabled` filter

---

## 10. Risk Register

| # | Risk | Probability | Impact | Mitigation | Owner |
|---|------|------------|--------|-----------|-------|
| 1 | Beta endpoint already removed — voice broken | Certain | Critical | Prioritize Epic 1; ship GA migration ASAP | Dev |
| 2 | WebRTC fails in some browsers/networks | Medium | High | Feature-detect; WebSocket fallback; configurable STUN/TURN | Dev |
| 3 | gpt-realtime-2 cost higher than expected | Medium | Medium | Default `low` reasoning; cost tracking; admin budget alerts | PM |
| 4 | Session format migration breaks existing voice configs | Low | High | Auto-migration script; admin notice; backward-compat where possible | Dev |
| 5 | Translation/transcription latency unacceptable | Low | Medium | Configurable latency delay; test with real conditions | QA |
| 6 | ICE connection failures in restricted networks | Medium | Medium | TURN server configuration option; WebSocket fallback | Dev/Ops |
| 7 | New JS services conflict with existing chat.js | Medium | Medium | Namespace isolation; feature flags; incremental roll-out | Dev |
| 8 | Third-party plugin conflicts with WebRTC | Low | Low | Detect conflicts; disable WebRTC if incompatible plugin active | Support |

---

## 11. Story Sequencing Diagram

```
Epic 1: GA Migration
  Story 1.1 ──┬── Story 1.2 ──┬── Story 1.3
               │               │
               ├── Story 1.4   ├── Story 1.5
               │               │
Epic 2: WebRTC │               │
  Story 2.1 ───┤               │
  Story 2.2 ───┤               │
  Story 2.3 ───┤               │
               │               │
               ├── Story 2.4 ──┤
               ├── Story 2.5   │
               │               │
Epic 3: gpt-realtime-2         │
               ├── Story 3.1 ──┤
               ├── Story 3.2 ──┤
               ├── Story 3.3 ──┤
               ├── Story 3.4   │
               ├── Story 3.5   │
               ├── Story 3.6 ──┤
               │               │
Epic 4: Translate/Whisper      │
               ├── Story 4.1 ──┤
               ├── Story 4.2 ──┤
               ├── Story 4.3 ──┤
               ├── Story 4.4 ──┤
               ├── Story 4.5 ──┘
               │
Epic 5: Tools
               ├── Story 5.1 (independent)
               ├── Story 5.2 (needs 1.2, 2.4)
               ├── Story 5.3 (needs 1.2)
               ├── Story 5.4 (needs 2.1, 2.2)
               ├── Story 5.5 (needs 3.6)
               │
Epic 6: Polish
               ├── Story 6.1 (needs 2.4, 3.1)
               ├── Story 6.2 (needs 2.4)
               ├── Story 6.3 (needs 3.6)
               ├── Story 6.4 (cross-cutting)
               ├── Story 6.5 (needs 1.2, 2.2, 2.3)
               ├── Story 6.6 (needs 2.1, 2.4)
               └── Story 6.7 (cross-cutting)
```

---

## Appendix A: OpenAI Realtime API Pricing (GA)

| Model | Input | Output | Cached Input | Per-Minute |
|-------|-------|--------|-------------|-----------|
| gpt-realtime-2 | $32.00/1M tokens | $64.00/1M tokens | $0.40/1M tokens | — |
| gpt-realtime-translate | — | — | — | $0.034/min |
| gpt-realtime-whisper | — | — | — | $0.017/min |

## Appendix B: Supported Languages — GPT-Realtime-Translate

**Input (70+):** Arabic, Bengali, Bulgarian, Chinese (Simplified/Traditional), Croatian, Czech, Danish, Dutch, English, Estonian, Finnish, French, German, Greek, Hebrew, Hindi, Hungarian, Indonesian, Italian, Japanese, Korean, Latvian, Lithuanian, Malay, Norwegian, Polish, Portuguese, Romanian, Russian, Serbian, Slovak, Slovenian, Spanish, Swedish, Tamil, Telugu, Thai, Turkish, Ukrainian, Urdu, Vietnamese, and more.

**Output (13):** Arabic, Chinese (Mandarin), Dutch, English, French, German, Hindi, Italian, Japanese, Korean, Polish, Portuguese, Spanish.

## Appendix C: NV oOS Naming Convention Reference

| Convention | Example |
|-----------|---------|
| PHP Classes | `WP_MCP_AI_OpenAI_Realtime_Translate_Client` |
| PHP Functions | `wp_mcp_ai_get_realtime_session()` |
| PHP Hooks | `wp_mcp_ai_openai_realtime_session_payload` |
| JS Modules | `window.wpMcpAiWebRTC` |
| CSS Classes | `.wp-mcp-ai-chat__voice-realtime--active` |
| REST Routes | `/mcp-ai/v1/realtime/token` |
| Option Keys | `wp_mcp_ai_realtime_reasoning_effort` |
| Transient Keys | `wp_mcp_ai_realtime_ephemeral_{id}_{user}` |

---

*Implementation begins with Epic 1, Story 1.1 — the GA session format migration. All subsequent epics depend on this foundation.*
