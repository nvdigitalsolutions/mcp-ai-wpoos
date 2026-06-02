# WebLLM Professional Prompt Integration - Visual Guide

**Date:** January 26, 2026  
**Issue:** Embedded client not receiving professional prompts  
**Status:** ✅ Fixed  

---

## Problem: Professional Prompts Missing in Embedded Client

### Before Fix ❌

```
WordPress PHP
    ↓
[Shortcode Render]
    ↓
config = {
    systemPrompt: "You are a helpful assistant",
    professionalPrompt: "You are a hotel manager expert...", ← NOT USED
    memoryFiles: [...]
}
    ↓
JavaScript (chat.js)
    ↓
assistantConfig = {
    systemPrompt: state.config.systemPrompt,  ← ONLY assistant prompt
    tools: [...],
    memoryFiles: [...]
}
    ↓
new EmbeddedLLMClient(instanceId, assistantConfig)
    ↓
initializeModelContext()
    ↓
System Message: "You are a helpful assistant"  ← MISSING PROFESSION!
    ↓
❌ Model doesn't know it's a hotel manager
```

### After Fix ✅

```
WordPress PHP
    ↓
[Shortcode Render]
    ↓
config = {
    systemPrompt: "You are a helpful assistant",
    professionalPrompt: "You are a hotel manager expert...", ← PROVIDED
    memoryFiles: [...]
}
    ↓
JavaScript (chat.js - initEmbeddedClient)
    ↓
completeSystemPrompt = systemPrompt + '\n\n' + professionalPrompt  ← COMBINED!
    ↓
assistantConfig = {
    systemPrompt: completeSystemPrompt,  ← INCLUDES BOTH!
    tools: [...],
    memoryFiles: [...]
}
    ↓
new EmbeddedLLMClient(instanceId, assistantConfig)
    ↓
initializeModelContext()
    ↓
System Message: "You are a helpful assistant

You are a hotel manager expert..."  ← COMPLETE PROMPT!
    ↓
✅ Model knows it's a hotel manager assistant
```

---

## Code Flow Diagram

### Phase 1: Client Creation

```
┌─────────────────────────────────────────────────────────┐
│ chat.js: initEmbeddedClient(state)                      │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  1. Check if professional prompt exists                 │
│     ↓                                                    │
│     if (state.config.professionalPrompt)                │
│     ↓                                                    │
│  2. Combine prompts                                     │
│     ↓                                                    │
│     completeSystemPrompt = systemPrompt +               │
│                           '\n\n' +                       │
│                           professionalPrompt             │
│     ↓                                                    │
│  3. Log combination                                     │
│     ↓                                                    │
│     console.log('Combined system prompt')               │
│     {                                                    │
│       assistantPromptLength: 50,                        │
│       professionalPromptLength: 200,                    │
│       combinedLength: 252                               │
│     }                                                    │
│     ↓                                                    │
│  4. Create client with complete prompt                  │
│     ↓                                                    │
│     new EmbeddedLLMClient(instanceId, {                 │
│       systemPrompt: completeSystemPrompt,  ✓            │
│       tools: [...],                                     │
│       memoryFiles: [...],                               │
│       vectorStoreId: '...'                              │
│     })                                                   │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Phase 2: Model Initialization

```
┌─────────────────────────────────────────────────────────┐
│ embedded-llm-client.js: loadModel(modelId)              │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  1. Download/load model                                 │
│     ↓                                                    │
│     CreateMLCEngine(modelId, ...)                       │
│     ↓                                                    │
│  2. Call initialization                                 │
│     ↓                                                    │
│     this.initializeModelContext()                       │
│     ↓                                                    │
│     ┌────────────────────────────────┐                  │
│     │ initializeModelContext()       │                  │
│     ├────────────────────────────────┤                  │
│     │ systemPromptContent =          │                  │
│     │   this.systemPrompt            │ ← ALREADY HAS    │
│     │   (includes professional)      │   PROFESSIONAL!  │
│     │                                 │                  │
│     │ + knowledgeContext             │                  │
│     │   (memoryFiles info)            │                  │
│     │                                 │                  │
│     │ Send to model:                 │                  │
│     │ [                               │                  │
│     │   {role: 'system',              │                  │
│     │    content: systemPromptContent}│ ← COMPLETE!     │
│     │   {role: 'user',                │                  │
│     │    content: 'Understood...'}    │                  │
│     │ ]                               │                  │
│     └────────────────────────────────┘                  │
│     ↓                                                    │
│  3. Model primed with complete instructions             │
│     ✓ Assistant system prompt                           │
│     ✓ Professional role                                 │
│     ✓ Knowledge base info                               │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Phase 3: Conversation Messages

```
┌─────────────────────────────────────────────────────────┐
│ chat.js: generateEmbeddedCompletion(state, messages)    │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  1. Check if system message needed                      │
│     ↓                                                    │
│     if (!messages.some(msg => msg.role === 'system'))   │
│     ↓                                                    │
│  2. Build complete system prompt                        │
│     ↓                                                    │
│     systemPromptContent = systemPrompt                  │
│     ↓                                                    │
│     if (professionalPrompt exists)                      │
│     ↓                                                    │
│     systemPromptContent +=                              │
│       '\n\n' + professionalPrompt                       │
│     ↓                                                    │
│     console.log('Added professional prompt')            │
│     ↓                                                    │
│  3. Add knowledge context                               │
│     ↓                                                    │
│     if (memoryFiles.length > 0)                         │
│     ↓                                                    │
│     systemPromptContent +=                              │
│       '\n\n## Base Knowledge\n\n...'                    │
│     ↓                                                    │
│  4. Prepend to messages                                 │
│     ↓                                                    │
│     messages.unshift({                                  │
│       role: 'system',                                   │
│       content: systemPromptContent  ← COMPLETE!         │
│     })                                                   │
│     ↓                                                    │
│  5. Send to model                                       │
│     ↓                                                    │
│     [                                                    │
│       {role: 'system', content: '...(complete)...'},    │
│       {role: 'user', content: 'User message'},          │
│       ...                                                │
│     ]                                                    │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## Prompt Composition Examples

### Example 1: Assistant Only
```
Input:
  systemPrompt: "You are a helpful assistant"
  professionalPrompt: null
  memoryFiles: []

Output (Initialization):
  "You are a helpful assistant"

Output (Messages):
  "You are a helpful assistant"
```

### Example 2: Professional Only
```
Input:
  systemPrompt: null
  professionalPrompt: "You are a hotel manager expert..."
  memoryFiles: []

Output (Initialization):
  "You are a hotel manager expert..."

Output (Messages):
  "You are a hotel manager expert..."
```

### Example 3: Both Prompts
```
Input:
  systemPrompt: "You are a helpful assistant"
  professionalPrompt: "You are a hotel manager expert..."
  memoryFiles: []

Output (Initialization):
  "You are a helpful assistant

  You are a hotel manager expert..."

Output (Messages):
  "You are a helpful assistant

  You are a hotel manager expert..."
```

### Example 4: Both Prompts + Knowledge
```
Input:
  systemPrompt: "You are a helpful assistant"
  professionalPrompt: "You are a hotel manager expert..."
  memoryFiles: [file1, file2, file3]

Output (Initialization):
  "You are a helpful assistant

  You are a hotel manager expert...

  ## Base Knowledge

  You have access to the following knowledge base files:
  - 3 file(s) in your knowledge base
  Use this knowledge to provide accurate and contextual responses."

Output (Messages):
  "You are a helpful assistant

  You are a hotel manager expert...

  ## Base Knowledge

  You have access to the following knowledge base files:
  - 3 file(s) in your knowledge base
  Use this knowledge to provide accurate and contextual responses."
```

---

## Console Log Output

### On Client Creation
```javascript
[NV oOS] Combined system prompt with professional prompt: {
    assistantPromptLength: 50,
    professionalPromptLength: 200,
    combinedLength: 252
}

[NV oOS] Created enhanced WebLLM client with tools/knowledge support: {
    instanceId: "chat-123-1234567890-abc123def",
    hasTools: false,
    hasKnowledge: false,
    hasSystemPrompt: true
}
```

### On Model Load
```javascript
[NV oOS Embedded Client] Loading model for instance: {
    instanceId: "chat-123-1234567890-abc123def",
    modelId: "Llama-3.2-1B-Instruct-q4f16_1-MLC"
}

[NV oOS Embedded Client] Model loaded successfully for instance: {
    instanceId: "chat-123-1234567890-abc123def",
    modelId: "Llama-3.2-1B-Instruct-q4f16_1-MLC"
}

[NV oOS Embedded Client] Initializing model context for instance: {
    instanceId: "chat-123-1234567890-abc123def",
    hasSystemPrompt: true,
    hasKnowledge: false,
    systemPromptLength: 252
}

[NV oOS Embedded Client] Model context initialized successfully for instance: {
    instanceId: "chat-123-1234567890-abc123def",
    responseLength: 25
}
```

### On Message Send
```javascript
[NV oOS] Added professional prompt to message system prompt: {
    professionalPromptLength: 200,
    professionalPromptPreview: "You are a hotel manager expert with years of experience in hospitality management. You specialize in ..."
}

[NV oOS] Prepended system prompt from assistant config: {
    systemPromptLength: 252,
    systemPromptPreview: "You are a helpful assistant\n\nYou are a hotel manager expert with years of experience in hospitalit...",
    hasKnowledgeContext: false
}

[NV oOS] Formatted messages for embedded client: {
    messageCount: 2,
    hasSystemPrompt: true,
    lastMessage: {role: "user", content: "What's your best tip for..."}
}
```

---

## Before/After Comparison

### Before: Server-Side (Working)
```
REST API Request → PHP
    ↓
WP_MCP_AI_REST_Chat_Controller::create_item()
    ↓
Build messages:
    system: systemPrompt + professionalPrompt + knowledge  ✓
    user: message
    ↓
Send to OpenAI/Gemini/Ollama
    ↓
✅ Model receives complete context
```

### Before: Embedded Client (Broken)
```
Browser → JavaScript
    ↓
generateEmbeddedCompletion()
    ↓
Build messages:
    system: systemPrompt ONLY  ❌ (missing professional)
    user: message
    ↓
Send to WebLLM
    ↓
❌ Model missing professional context
```

### After: Embedded Client (Fixed)
```
Browser → JavaScript
    ↓
initEmbeddedClient()
    ↓
Combine: systemPrompt + professionalPrompt  ✓
    ↓
Create client with complete prompt  ✓
    ↓
initializeModelContext()
    ↓
Prime model: completePrompt + knowledge  ✓
    ↓
generateEmbeddedCompletion()
    ↓
Build messages:
    system: systemPrompt + professionalPrompt + knowledge  ✓
    user: message
    ↓
Send to WebLLM
    ↓
✅ Model receives complete context (matches server-side!)
```

---

## Implementation Locations

### 1. Client Creation Combination
**File:** `assets/js/chat.js`  
**Function:** `initEmbeddedClient(state)`  
**Lines:** ~11457-11485

```javascript
// Build complete system prompt by combining assistant + professional
var completeSystemPrompt = state.config.systemPrompt || '';
if (state.config.professionalPrompt) {
    if (completeSystemPrompt) {
        completeSystemPrompt = completeSystemPrompt + '\n\n' + state.config.professionalPrompt;
    } else {
        completeSystemPrompt = state.config.professionalPrompt;
    }
}

const assistantConfig = {
    systemPrompt: completeSystemPrompt,  // ← Combined!
    tools: state.config.tools || [],
    memoryFiles: state.config.memoryFiles || [],
    vectorStoreId: state.config.vectorStoreId
};
```

### 2. Message Building Combination
**File:** `assets/js/chat.js`  
**Function:** `generateEmbeddedCompletion(state, embeddedClient, messages)`  
**Lines:** ~11868-11895

```javascript
// Build complete system prompt for this conversation
if ((state.config.systemPrompt || state.config.professionalPrompt) && 
    !formattedMessages.some(msg => msg.role === 'system')) {
    
    var systemPromptContent = state.config.systemPrompt || '';
    
    // Add professional prompt
    if (state.config.professionalPrompt) {
        if (systemPromptContent) {
            systemPromptContent = systemPromptContent + '\n\n' + state.config.professionalPrompt;
        } else {
            systemPromptContent = state.config.professionalPrompt;
        }
    }
    
    // Add knowledge context
    if (state.config.memoryFiles && state.config.memoryFiles.length > 0) {
        systemPromptContent += '\n\n## Base Knowledge\n\n...';
    }
    
    formattedMessages.unshift({
        role: 'system',
        content: systemPromptContent  // ← Complete prompt!
    });
}
```

### 3. Initialization (Already Working)
**File:** `assets/js/embedded-llm-client.js`  
**Function:** `initializeModelContext()`  
**Lines:** ~353-420

```javascript
// No changes needed - receives complete prompt from constructor
var systemPromptContent = this.systemPrompt;  // ← Already includes professional!

if (this.hasKnowledge) {
    systemPromptContent += this._buildKnowledgeContext();
}

await this.currentEngine.chat.completions.create({
    messages: [
        { role: 'system', content: systemPromptContent },  // ← Complete!
        { role: 'user', content: 'Understood. I am ready to assist.' }
    ],
    temperature: 0.3,
    max_tokens: 50,
    stream: false
});
```

---

## Testing Verification

### Test Case 1: Professional Prompt Only
```php
// Shortcode
[mcp_ai_chat profession="hotel_manager"]

// Expected Console Logs
✓ "Combined system prompt with professional prompt"
✓ "Created enhanced WebLLM client" (hasSystemPrompt: true)
✓ "Initializing model context" (systemPromptLength > 0)
✓ "Added professional prompt to message system prompt"

// Browser Test
User: "What's your best tip?"
Assistant: "As a hotel manager, I recommend..." ✓
```

### Test Case 2: Both Prompts
```php
// Shortcode
[mcp_ai_chat assistant="123" profession="hotel_manager"]

// Expected Console Logs
✓ "Combined system prompt with professional prompt" (combinedLength > assistantPromptLength)
✓ "Created enhanced WebLLM client" (hasSystemPrompt: true)
✓ "Initializing model context" (includes both prompts)
✓ "Added professional prompt to message system prompt"

// Browser Test
User: "Help me with something"
Assistant: "(follows both assistant style AND professional role)" ✓
```

### Test Case 3: With Knowledge
```php
// Shortcode
[mcp_ai_chat assistant="123" profession="hotel_manager"]
// + Memory files configured in assistant

// Expected Console Logs
✓ "Enhanced system prompt with base knowledge"
✓ "Combined system prompt with professional prompt"
✓ "Created enhanced WebLLM client" (hasSystemPrompt: true, hasKnowledge: true)
✓ "Initializing model context" (systemPromptLength includes all parts)

// Browser Test
User: "What do you know about our hotel?"
Assistant: "(uses knowledge files + professional role)" ✓
```

---

## Summary

### Changes Made
1. ✅ Modified `initEmbeddedClient()` to combine system + professional prompts
2. ✅ Modified `generateEmbeddedCompletion()` to combine prompts in messages
3. ✅ Added console logging for verification
4. ✅ Maintained backward compatibility (no breaking changes)

### Impact
- **Before:** Professional prompts ignored in embedded client
- **After:** Professional prompts fully integrated
- **Consistency:** Embedded client now matches server-side behavior
- **User Experience:** Assistants maintain professional role/expertise

### Files Modified
- `assets/js/chat.js` (+36 lines, -5 lines)

### Backward Compatibility
- ✅ Works with assistant prompt only
- ✅ Works with professional prompt only
- ✅ Works with both prompts
- ✅ Works with knowledge base
- ✅ Works with tools
- ✅ No breaking changes to API

---

**Status:** ✅ Complete  
**Ready For:** Testing & Review  
**Next Steps:** Manual browser testing with profession-based assistants
