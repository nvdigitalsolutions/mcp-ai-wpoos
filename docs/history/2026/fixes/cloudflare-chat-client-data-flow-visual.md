# Cloudflare Chat Client Data Flow - Visual Guide

## Complete Request/Response Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            FRONTEND (Browser)                                │
│                          assets/js/chat.js                                   │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      │ User clicks "Send"
                                      │ handleSubmit() → sendChat()
                                      ↓
                        ┌─────────────────────────────┐
                        │   Build Frontend Payload    │
                        │   (Lines 10950-10977)       │
                        ├─────────────────────────────┤
                        │ • assistant_id              │
                        │ • messages (conversation)   │
                        │ • session_key               │
                        │ • professional_prompt (opt) │
                        │ • save_transcript           │
                        │                             │
                        │ ❌ NO system_prompt         │
                        │ ❌ NO tools                 │
                        └─────────────────────────────┘
                                      │
                                      │ POST /wp-json/mcp-ai/v1/chat
                                      ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                        BACKEND - REST API LAYER                              │
│              includes/rest/class-wp-mcp-ai-rest-chat-controller.php         │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                        handle_chat_request() (Line 2248)
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Load Assistant Config      │
                        │  (Line 2288)                │
                        ├─────────────────────────────┤
                        │ get_assistant_configuration()│
                        │   ↓                         │
                        │ Returns:                    │
                        │ • system_prompt             │
                        │ • tool_ids                  │
                        │ • provider                  │
                        │ • model                     │
                        │ • temperature               │
                        └─────────────────────────────┘
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Merge Professional Prompt  │
                        │  (Lines 2295-2306)          │
                        ├─────────────────────────────┤
                        │ If professional_prompt:     │
                        │   prepend to system_prompt  │
                        └─────────────────────────────┘
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Build Tools Payload        │
                        │  (Line 2329)                │
                        ├─────────────────────────────┤
                        │ build_tools_payload()       │
                        │   ↓                         │
                        │ Converts tool_ids to        │
                        │ OpenAI function format      │
                        └─────────────────────────────┘
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Build Options Array        │
                        │  (Lines 2308-2383)          │
                        ├─────────────────────────────┤
                        │ options = {                 │
                        │   system_prompt: "...",     │
                        │   tools: [...],             │
                        │   provider: "cloudflare",   │
                        │   model: "@cf/meta/...",    │
                        │   temperature: 1.0          │
                        │ }                           │
                        └─────────────────────────────┘
                                      │
                                      │ Pass to LM Router
                                      ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                      LANGUAGE MODEL ROUTER                                   │
│              includes/class-wp-mcp-ai-language-model-router.php             │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                        create_chat_completion()
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Route by Provider          │
                        ├─────────────────────────────┤
                        │ if provider == "cloudflare":│
                        │   → cloudflare_client       │
                        └─────────────────────────────┘
                                      │
                                      ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                      CLOUDFLARE CLIENT                                       │
│              includes/class-wp-mcp-ai-cloudflare-client.php                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                        send_message() (Line 143)
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Build System Messages      │
                        │  (Lines 193-237)            │
                        ├─────────────────────────────┤
                        │ Extract from options:       │
                        │   system_prompt             │
                        │   memory_documents          │
                        │                             │
                        │ Create system messages:     │
                        │ system_messages = [         │
                        │   {                         │
                        │     role: "system",         │
                        │     content: system_prompt  │
                        │   }                         │
                        │ ]                           │
                        │                             │
                        │ Prepend to messages array   │
                        └─────────────────────────────┘
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Build Payload              │
                        │  build_payload()            │
                        │  (Lines 370-437)            │
                        ├─────────────────────────────┤
                        │ Step 1: Normalize messages  │
                        │ Step 2: Extract system msgs │
                        │                             │
                        │ foreach message:            │
                        │   if role == "system":      │
                        │     system_content += text  │
                        │   else:                     │
                        │     non_system_msgs.push()  │
                        │                             │
                        │ Step 3: Build payload       │
                        │ payload = {                 │
                        │   messages: non_system_msgs │
                        │ }                           │
                        │                             │
                        │ Step 4: Add system field    │
                        │ if system_content:          │
                        │   payload.system = content  │
                        │                             │
                        │ Step 5: Normalize tools     │
                        │ if tools:                   │
                        │   payload.tools =           │
                        │     normalise_tools(tools)  │
                        └─────────────────────────────┘
                                      │
                                      ↓
                        ┌─────────────────────────────┐
                        │  Final Payload Structure    │
                        ├─────────────────────────────┤
                        │ {                           │
                        │   "system": "You are...",   │ ← FIRST
                        │   "messages": [             │ ← SECOND
                        │     {                       │
                        │       "role": "user",       │
                        │       "content": "..."      │
                        │     }                       │
                        │   ],                        │
                        │   "tools": [                │ ← THIRD
                        │     {                       │
                        │       "name": "tool_name",  │
                        │       "description": "...", │
                        │       "parameters": {...}   │
                        │     }                       │
                        │   ],                        │
                        │   "temperature": 1.0,       │
                        │   "stream": true            │
                        │ }                           │
                        └─────────────────────────────┘
                                      │
                                      │ wp_remote_post()
                                      ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                      CLOUDFLARE WORKERS AI                                   │
│                  https://api.cloudflare.com/...                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                        Process Request in Order:
                                      │
                        ┌─────────────────────────────┐
                        │ 1. Apply System Prompt      │
                        │    (Establishes persona)    │
                        └─────────────────────────────┘
                                      ↓
                        ┌─────────────────────────────┐
                        │ 2. Process Messages         │
                        │    (Conversation context)   │
                        └─────────────────────────────┘
                                      ↓
                        ┌─────────────────────────────┐
                        │ 3. Enable Tool Calling      │
                        │    (Function capabilities)  │
                        └─────────────────────────────┘
                                      │
                                      ↓ Generate Response
                                      │
                        ┌─────────────────────────────┐
                        │ Response with Persona       │
                        │ "As YAAD-RELIEF, I can..."  │
                        └─────────────────────────────┘
```

## Key Points

### ✅ Correct Behavior

1. **Frontend**: Does NOT send system_prompt or tools
2. **Backend**: Retrieves from assistant configuration
3. **Cloudflare Client**: Extracts system messages and creates separate `system` field
4. **Payload**: Correct format with system, messages, tools in proper order
5. **Cloudflare AI**: Processes system field FIRST, then messages, then tools

### ❌ What Was Wrong (Before PR #2770)

1. System messages were sent in messages array (OpenAI format)
2. Cloudflare ignored system role messages in messages array
3. Result: Assistant responded generically without persona

### ✅ What Was Fixed (PR #2770)

1. System messages are extracted from messages array
2. Combined into single system content string
3. Added as separate `system` field in payload
4. Cloudflare now respects system prompt correctly

## Payload Comparison

### Before Fix (Broken)
```json
{
  "messages": [
    {"role": "system", "content": "You are YAAD-RELIEF..."},  ← Ignored by Cloudflare
    {"role": "user", "content": "Hello"}
  ],
  "tools": [...]
}
```

### After Fix (Working)
```json
{
  "system": "You are YAAD-RELIEF...",  ← Processed by Cloudflare
  "messages": [
    {"role": "user", "content": "Hello"}
  ],
  "tools": [...]
}
```

## Testing Example

### Configuration
```
Assistant ID: 372
Provider: cloudflare
Model: @cf/meta/llama-3.2-3b-instruct
System Prompt: "You are YAAD-RELIEF, a disaster relief GPT for Jamaica..."
Tools: list_jetengine_rest_routes, get_system_logs
```

### Before Fix
```
User: "what are some things you can do"
Assistant: "we can assist with content creation, AI research, web development..."
```
❌ Generic response, no Jamaica/disaster relief context

### After Fix
```
User: "what are some things you can do"
Assistant: "As YAAD-RELIEF, I can help you prepare for hurricanes in Jamaica, 
           provide disaster relief information, and connect you to emergency resources..."
```
✅ Persona-aware response with Jamaica context

## Date

January 10, 2026
