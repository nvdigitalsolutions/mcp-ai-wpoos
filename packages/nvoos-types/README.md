# @nvdigitalsolutions/nvoos-types

Canonical TypeScript type definitions for the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).

**Zero runtime code** — this package contains only `.d.ts` type declarations. Use it to get full IDE autocompletion and type safety when building on top of NV oOS REST APIs, chat surfaces, tool registries, or SSE streams.

## Installation

```bash
npm install --save-dev @nvdigitalsolutions/nvoos-types
```

## What's Included

| Module | Types |
|--------|-------|
| **AI Providers** | `AiProvider` union (openai, gemini, anthropic, deepseek, openrouter, ollama, lmstudio, huggingface, cloudflare, baseten, kimi, nvidia) |
| **Chat** | `ChatMessage`, `ChatInstanceState`, `ChatMessageContent`, `ChatMessageDisplay`, `GlobalChatConfig` |
| **Tools** | `ToolDefinition`, `ToolRiskLevel`, `ToolResult`, `ToolExecutionPayload`, `ToolMessagePayload` |
| **SSE / Streaming** | `SseEvent`, `StreamDelta`, `ToolCallStarted`, `ToolCallCompleted`, `SseStatusMessage` |
| **Attachments** | `PendingAttachment`, `AttachmentRecord`, `AttachmentLibraryEntry`, `DisplayAttachment` |
| **Speech** | `SpeechCacheEntry` |
| **History** | `HistorySession`, `HistorySessionDetail` |
| **Async Jobs** | `PendingAsyncTool`, `PendingCrawlTask` |
| **Memory** | `MemoryEvent` |
| **Multi-Agent** | `AgentStatus`, `DelegationNotice` |
| **Message Bundling** | `MessageBundleEntry` |

## Usage

```typescript
import type {
  ChatMessage,
  GlobalChatConfig,
  ToolResult,
  SseEvent,
  PendingAttachment,
  AiProvider,
} from '@nvdigitalsolutions/nvoos-types';

// Build typed chat payloads
function buildPayload(config: GlobalChatConfig, messages: ChatMessage[]) {
  return {
    assistant_id: config.originalAssistantId,
    messages,
  };
}

// Handle typed SSE events
function handleSseEvent(event: SseEvent) {
  switch (event.type) {
    case 'message_delta':
      // event is typed as StreamDelta
      break;
    case 'tool_call_started':
      // event is typed as ToolCallStarted
      break;
  }
}

// Use the AiProvider union for provider-specific logic
function getProviderColor(provider: AiProvider): string {
  const colors: Record<AiProvider, string> = {
    openai: '#10a37f',
    gemini: '#4285f4',
    // ... all 12 providers required
  };
  return colors[provider];
}
```

## Updating

These types mirror the canonical source in the NV oOS TypeScript layer (`assets/js/src/shared/types.ts`). When the upstream types change, rebuild:

```bash
npm run build
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
