# Testing Embedded Chat Client Console Logging

This document explains how to verify that console logging for the embedded chat client is working correctly and that prompts are properly initialized.

## Overview

Console logging has been enhanced for the embedded chat client to help diagnose initialization issues, particularly around system prompts, professional prompts, and base knowledge not being properly passed to the AI assistant.

## Prerequisites

1. WordPress site with NV oOS plugin installed
2. An assistant configured with:
   - Provider: `embedded` (WebLLM)
   - Model: Any WebLLM model (e.g., `Llama-3.2-1B-Instruct-q4f16_1-MLC`)
   - System Prompt: Any custom instructions
   - Optional: Professional prompt, tools, or knowledge base files
3. Browser with developer console open (F12)

## Test Steps

### 1. Open Browser Console

1. Open your WordPress site in Chrome, Edge, or Safari
2. Press F12 (or Cmd+Option+I on Mac) to open Developer Tools
3. Click on the "Console" tab
4. Clear the console (click the 🚫 icon or press Ctrl+L)

### 2. Load the Chat Widget

1. Navigate to a page with the embedded chat shortcode `[mcp_ai_chat assistant_id="XXX"]`
2. Or use the Elementor widget with an embedded provider assistant

### 3. Verify Initial Logging

You should immediately see logs showing the embedded client was created:

```
[NV oOS Embedded Client] Created new instance: {
  instanceId: "chat-123-1234567890-abcdefghi",
  hasSystemPrompt: true,
  systemPromptLength: 156,
  systemPromptPreview: "You are a helpful assistant...",
  hasTools: false,
  toolCount: 0,
  hasKnowledge: false,
  memoryFileCount: 0,
  hasVectorStore: false
}
```

**Verify:**
- ✅ `hasSystemPrompt: true` if you configured a system prompt
- ✅ `systemPromptLength` matches your system prompt length
- ✅ `systemPromptPreview` shows the first 150 characters of your system prompt
- ✅ `hasTools: true` and correct `toolCount` if tools are configured
- ✅ `hasKnowledge: true` if memory files or vector store is configured

### 4. Send the First Message

Type a message in the chat and press send. You should see:

#### Model Initialization Logs (First Time Only)

```
[NV oOS Embedded Client] ===== STARTING MODEL CONTEXT INITIALIZATION =====
[NV oOS Embedded Client] Initializing model context for instance: {
  instanceId: "chat-123-...",
  hasSystemPrompt: true,
  systemPromptLength: 156,
  hasKnowledge: false,
  ...
}
```

```
[NV oOS Embedded Client] Full system prompt for initialization: {
  systemPromptLength: 156,
  systemPromptPreview: "You are a helpful assistant...",
  systemPromptFull: "You are a helpful assistant with specialized knowledge..."
}
```

```
[NV oOS Embedded Client] Sending initialization messages to model: {
  messageCount: 2,
  hasSystemMessage: true,
  triggerMessage: "Understood. I am ready to assist.",
  temperature: 0.3,
  maxTokens: 50
}
```

```
[NV oOS Embedded Client] ===== MODEL CONTEXT INITIALIZATION COMPLETE =====
```

**Verify:**
- ✅ `systemPromptFull` contains your complete system prompt
- ✅ `messageCount: 2` (system message + trigger message)
- ✅ Initialization completes successfully

#### Message Preparation Logs

```
[NV oOS] ===== PREPARING SYSTEM PROMPT FOR EMBEDDED CLIENT =====
[NV oOS] Prepended system prompt from assistant config: {
  systemPromptLength: 156,
  systemPromptPreview: "You are a helpful assistant...",
  systemPromptFull: "You are a helpful assistant with specialized knowledge...",
  hasKnowledgeContext: false,
  hasProfessionalPrompt: false,
  assistantId: 123
}
```

```
[NV oOS] ===== FORMATTED MESSAGES FOR EMBEDDED CLIENT =====
[NV oOS] Formatted messages for embedded client: {
  messageCount: 2,
  hasSystemPrompt: true,
  systemPromptLength: 156,
  messageRoles: ["system", "user"],
  lastMessageRole: "user",
  lastMessagePreview: "Hello, how are you?"
}
```

**Verify:**
- ✅ `systemPromptFull` contains the complete system prompt
- ✅ If you have a professional prompt, `hasProfessionalPrompt: true`
- ✅ If you have knowledge files, `hasKnowledgeContext: true`
- ✅ `messageRoles` starts with `"system"`
- ✅ `hasSystemPrompt: true`

#### Streaming Completion Logs

```
[NV oOS Embedded Client] ===== STARTING STREAMING COMPLETION =====
[NV oOS Embedded Client] Request details: {
  instanceId: "chat-123-...",
  messageCount: 2,
  messageRoles: ["system", "user"],
  temperature: 0.7,
  maxTokens: 2048,
  hasTools: false
}
```

```
[NV oOS Embedded Client] System prompt detected in messages: {
  hasSystemPrompt: true,
  systemPromptLength: 156,
  systemPromptPreview: "You are a helpful assistant...",
  systemPromptFull: "You are a helpful assistant with specialized knowledge...",
  matchesStoredPrompt: true,
  storedPromptLength: 156
}
```

**Verify:**
- ✅ `systemPromptFull` is logged (shows the exact prompt sent to the model)
- ✅ `matchesStoredPrompt: true` (confirms no mismatch between stored and sent prompts)
- ✅ `messageRoles` includes `"system"` as the first element

### 5. Send Subsequent Messages

For subsequent messages, you'll see the same "PREPARING SYSTEM PROMPT" and "STREAMING COMPLETION" logs, but NOT the initialization logs (those only run once when the model is first loaded).

## Common Issues and Solutions

### Issue: No System Prompt in Messages

If you see:
```
[NV oOS Embedded Client] WARNING: No system prompt in messages for instance: {
  instanceId: "chat-123-...",
  hasStoredPrompt: true,
  storedPromptLength: 156,
  messageCount: 1
}
```

**Cause:** The system prompt was not added to the messages before calling the embedded client.

**Solution:** Check that the assistant has `_wp_mcp_ai_system_prompt` meta set, and that the chat.js code is properly prepending it to `formattedMessages`.

### Issue: System Prompt Mismatch

If you see:
```
matchesStoredPrompt: false
```

**Cause:** The system prompt sent in messages differs from the one stored in the embedded client instance.

**Investigation:** Compare `systemPromptFull` in the message with the `systemPromptPreview` shown when the instance was created. They should match.

### Issue: Missing Professional Prompt

If you configured a professional prompt but see:
```
hasProfessionalPrompt: false
```

**Cause:** The professional prompt was not properly combined with the system prompt.

**Solution:** Check that the assistant has the professional prompt configured and that chat.js is combining it with the system prompt before sending.

### Issue: Missing Knowledge Context

If you configured memory files but see:
```
hasKnowledge: false
hasKnowledgeContext: false
```

**Cause:** Memory files were not properly passed to the embedded client.

**Solution:** Verify the assistant has `_wp_mcp_ai_memory_files` or `_wp_mcp_ai_vector_store_id` meta set, and check that the config is passed correctly when creating the embedded client instance.

## Log Filtering

To filter logs in the browser console:

1. **Show only embedded client logs:** Type `[NV oOS Embedded Client]` in the filter box
2. **Show only initialization logs:** Type `INITIALIZATION` in the filter box
3. **Show only system prompt logs:** Type `system prompt` in the filter box
4. **Show warnings only:** Click the "Warnings" filter in the console toolbar

## Expected Log Flow

Here's the complete expected flow for the first message:

```
1. [NV oOS Embedded Client] Created new instance: {...}
2. [NV oOS] sendChatEmbeddedInternal called with client instance: chat-123-...
3. [NV oOS] Model not loaded, loading model: Llama-3.2-1B-Instruct-q4f16_1-MLC
4. [NV oOS Embedded Client] Loading model for instance: {...}
5. [NV oOS Embedded Client] Model loaded successfully for instance: {...}
6. [NV oOS Embedded Client] ===== STARTING MODEL CONTEXT INITIALIZATION =====
7. [NV oOS Embedded Client] Initializing model context for instance: {...}
8. [NV oOS Embedded Client] Full system prompt for initialization: {...}
9. [NV oOS Embedded Client] Sending initialization messages to model: {...}
10. [NV oOS Embedded Client] ===== MODEL CONTEXT INITIALIZATION COMPLETE =====
11. [NV oOS] Model loaded successfully, generating completion
12. [NV oOS] Starting embedded completion generation (iteration 0)
13. [NV oOS] ===== PREPARING SYSTEM PROMPT FOR EMBEDDED CLIENT =====
14. [NV oOS] Prepended system prompt from assistant config: {...}
15. [NV oOS] ===== FORMATTED MESSAGES FOR EMBEDDED CLIENT =====
16. [NV oOS] Formatted messages for embedded client: {...}
17. [NV oOS] Calling generateStreamingCompletion with options: {...}
18. [NV oOS Embedded Client] ===== STARTING STREAMING COMPLETION =====
19. [NV oOS Embedded Client] Request details: {...}
20. [NV oOS Embedded Client] System prompt detected in messages: {...}
21. [NV oOS Embedded Client] Starting streaming completion for instance: {...}
22. [NV oOS Embedded Client] Chunk received for instance: {...} (repeated)
23. [NV oOS Embedded Client] Streaming completed for instance: {...}
```

## Debugging Tips

1. **Use browser's find feature:** Press Ctrl+F in the console and search for keywords like "system prompt", "initialization", "WARNING"
2. **Expand objects:** Click the ▶ arrow next to objects to see all properties
3. **Copy log data:** Right-click on a log object and select "Copy object" to save for later analysis
4. **Check timestamps:** Logs include timestamps - verify that initialization happens before message sending
5. **Compare prompts:** Copy the `systemPromptFull` values from different log entries and compare them in a text editor to ensure they match

## Success Criteria

The embedded chat client is working correctly when:

1. ✅ Client instance shows correct configuration on creation
2. ✅ Model context initialization completes successfully (first message only)
3. ✅ System prompt is logged in full during initialization
4. ✅ System prompt is present in every message sent to the model
5. ✅ No warnings about missing system prompts
6. ✅ `matchesStoredPrompt: true` in all streaming requests
7. ✅ Professional prompts and knowledge context are included when configured
8. ✅ The assistant responds with knowledge of its instructions

## Related Documentation

- [QUICK_REFERENCE.md](QUICK_REFERENCE.md) - Quick reference for the plugin
- [assistant-configuration.md](assistant-configuration.md) - How to configure assistants
- [embedded-provider.md](embedded-provider.md) - Embedded provider documentation

## Troubleshooting

If logs are not appearing:

1. Ensure browser console is open before loading the chat
2. Check that console.log is not filtered/hidden in your browser
3. Verify that the assistant is using the `embedded` provider
4. Clear browser cache and reload the page
5. Check browser compatibility (Chrome, Edge, Safari on macOS)

## Reporting Issues

When reporting issues with embedded chat initialization, include:

1. Screenshot of the browser console showing all logs
2. Assistant configuration (provider, model, system prompt length)
3. Browser and version
4. Any warnings or errors shown in red
5. The complete log sequence from client creation to first response
