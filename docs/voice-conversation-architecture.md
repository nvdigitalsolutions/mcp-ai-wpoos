# Voice Conversation Widget - Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        USER INTERACTION                                  │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │  Elementor Widget (Presentation Layer)                         │    │
│  │  • Button with configurable text                               │    │
│  │  • Style controls                                              │    │
│  │  • Settings (assistant, duration, guest access, etc.)          │    │
│  └────────────────────────────────────────────────────────────────┘    │
│                              ↓                                           │
└─────────────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                        CLIENT SIDE (Browser)                             │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │  JavaScript Handler (voice-conversation.js)                    │    │
│  │                                                                 │    │
│  │  1. RECORD (Web Audio API)                                     │    │
│  │     • Request microphone permission                            │    │
│  │     • MediaRecorder captures audio                             │    │
│  │     • Visual feedback (pulsing icon)                           │    │
│  │     • Max duration timer                                       │    │
│  │                                                                 │    │
│  │  2. UPLOAD                                                      │    │
│  │     • Create FormData with audio blob                          │    │
│  │     • Add conversation history                                 │    │
│  │     • POST to REST endpoint                                    │    │
│  │     • Show "Processing..." state                               │    │
│  │                                                                 │    │
│  │  3. PLAYBACK                                                    │    │
│  │     • Receive audio URL from server                            │    │
│  │     • Create Audio element                                     │    │
│  │     • Auto-play (if enabled)                                   │    │
│  │     • Update transcript display                                │    │
│  └────────────────────────────────────────────────────────────────┘    │
│                              ↓                                           │
└─────────────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                    SERVER SIDE (WordPress/PHP)                           │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │  REST Controller (Orchestration Layer)                         │    │
│  │  Endpoint: POST /wp-json/mcp-ai/v1/voice-conversation          │    │
│  │                                                                 │    │
│  │  ┌──────────────────────────────────────────────────────┐     │    │
│  │  │  ORCHESTRATION WORKFLOW                              │     │    │
│  │  │                                                       │     │    │
│  │  │  Step 1: upload_audio_file()                         │     │    │
│  │  │  ↓ Validate file type                                │     │    │
│  │  │  ↓ Save to Media Library                             │     │    │
│  │  │  ↓ Return attachment ID                              │     │    │
│  │  │                                                       │     │    │
│  │  │  Step 2: transcribe_audio()                          │     │    │
│  │  │  ↓ Get transcribe_openai_audio tool ────────────┐   │     │    │
│  │  │  ↓ Execute tool with attachment ID              │   │     │    │
│  │  │  ↓ Return transcribed text                       │   │     │    │
│  │  │                                                  │   │     │    │
│  │  │  Step 3: get_ai_response()                       │   │     │    │
│  │  │  ↓ Get assistant configuration                   │   │     │    │
│  │  │  ↓ Build messages with conversation history      │   │     │    │
│  │  │  ↓ Call Language Model Router ──────────────┐   │   │     │    │
│  │  │  ↓ Extract response text                    │   │   │     │    │
│  │  │                                              │   │   │     │    │
│  │  │  Step 4: generate_speech()                   │   │   │     │    │
│  │  │  ↓ Get generate_openai_speech tool ──────┐  │   │   │     │    │
│  │  │  ↓ Execute tool with response text        │  │   │   │     │    │
│  │  │  ↓ Return audio URL                       │  │   │   │     │    │
│  │  │                                            │  │   │   │     │    │
│  │  │  Step 5: Return coordinated result         │  │   │   │     │    │
│  │  │  ↓ User text (transcription)               │  │   │   │     │    │
│  │  │  ↓ Assistant text (AI response)            │  │   │   │     │    │
│  │  │  ↓ Audio URL (speech file)                 │  │   │   │     │    │
│  │  └──────────────────────────────────────────────────────┘     │    │
│  │                              │  │   │   │                      │    │
│  └──────────────────────────────┼──┼───┼───┼──────────────────────┘    │
│                                 │  │   │   │                            │
│                                 ↓  ↓   ↓   ↓                            │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │  Business Logic Layer (Existing Tools)                         │    │
│  │                                                                 │    │
│  │  ┌─────────────────────┐  ┌─────────────────────┐             │    │
│  │  │ transcribe_openai_  │  │ generate_openai_    │             │    │
│  │  │ audio Tool          │  │ speech Tool         │             │    │
│  │  │                     │  │                     │             │    │
│  │  │ • Whisper API call  │  │ • TTS API call      │             │    │
│  │  │ • Audio → Text      │  │ • Text → Audio      │             │    │
│  │  │ • Language detect   │  │ • Voice selection   │             │    │
│  │  └─────────────────────┘  └─────────────────────┘             │    │
│  │                                                                 │    │
│  │  ┌────────────────────────────────────────────┐                │    │
│  │  │ Language Model Router                      │                │    │
│  │  │                                             │                │    │
│  │  │ • OpenAI / Gemini / Ollama                 │                │    │
│  │  │ • Message history management               │                │    │
│  │  │ • Tool calling support                     │                │    │
│  │  │ • Response generation                      │                │    │
│  │  └────────────────────────────────────────────┘                │    │
│  └────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                        SEPARATION OF CONCERNS                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Presentation   → Elementor widget (settings, UI, rendering)            │
│  Client Logic   → JavaScript (recording, upload, playback)              │
│  Orchestration  → REST controller (workflow coordination)               │
│  Business Logic → Existing tools (transcription, AI, speech)            │
│                                                                          │
│  Each layer only knows about its immediate concerns.                    │
│  No layer performs tasks belonging to another layer.                    │
│  Orchestration delegates to tools, never implements their logic.        │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                        DATA FLOW                                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  User Speech → Audio Blob → Server Upload → Transcription Tool →        │
│  Text → Language Model → Response Text → Speech Tool → Audio URL →      │
│  Client Download → Audio Playback → User Hears Response                 │
│                                                                          │
│  Conversation history flows back to maintain context for next turn.     │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                        SECURITY LAYERS                                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  1. Permission Check    → Authenticated user OR guest token allowed     │
│  2. File Validation     → Audio MIME types only, size limits            │
│  3. CSRF Protection     → WordPress nonces                              │
│  4. Capability Filter   → Per-assistant access control                  │
│  5. Tool Access         → Only registered, enabled tools                │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Key Characteristics

### ✅ Orchestration
The REST controller **coordinates** the workflow but **delegates** all actual work:
- Does NOT transcribe audio (delegates to tool)
- Does NOT generate AI responses (delegates to router)
- Does NOT synthesize speech (delegates to tool)
- ONLY orchestrates the sequence and data flow

### ✅ Separation of Concerns
Each component has a single, well-defined responsibility:
- Widget: User interface and settings
- JavaScript: Browser capabilities (mic, playback)
- Controller: Workflow coordination
- Tools: Specific AI capabilities

### ✅ Reusability
All existing tools are reused without modification:
- `transcribe_openai_audio` used as-is
- `generate_openai_speech` used as-is
- Language Model Router used as-is

### ✅ Maintainability
Changes to one layer don't affect others:
- UI changes: Edit widget only
- Recording logic: Edit JS only
- Workflow order: Edit controller only
- AI capabilities: Edit tools only
