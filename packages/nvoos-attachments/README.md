# @nvdigitalsolutions/nvoos-attachments

File attachment helpers for AI chat surfaces — extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

A zero-dependency utility belt for handling user-uploaded files in chat: type detection, validation, MIME-aware iconography, URL safety, normalisation of upload responses, and OpenAI-style content-segment builders.

**Zero external dependencies.** Pure functions over plain data — no DOM, no fetch, no globals.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-attachments
```

## Quick Start

```javascript
import {
  getFileExtension,
  isFileTypeAllowed,
  isRealAttachmentUrl,
  getFileTypeInfo,
  buildFileDownloadUrl,
} from '@nvdigitalsolutions/nvoos-attachments';

// Validate against an allowlist
const ok = isFileTypeAllowed(file, { allowedFileTypes: ['pdf', 'txt'] });

// Show a friendly icon + label
const { icon, label } = getFileTypeInfo({ type: 'application/pdf', name: 'spec.pdf' });
//   → { icon: '📄', label: 'PDF' }

// Reject blob:/data:/javascript: URLs before forwarding to a model
if (!isRealAttachmentUrl(submitted)) throw new Error('Unsafe URL');

// Build a download URL from a state-driven endpoint config
const url = buildFileDownloadUrl(state, fileId);
```

## API surface (17 helpers)

| Helper | Purpose |
|--------|---------|
| `getFileExtension(file)` | Lowercase extension (no dot). Accepts `File` or string. |
| `isFileTypeAllowed(file, state)` | True if `state.allowedFileTypes` includes the extension. Empty list = allow all. |
| `isRealAttachmentUrl(url)` | True only for `http:` and `https:` — rejects `blob:`, `data:`, `javascript:`. |
| `getFileTypeInfo(attachment)` | `{ icon, label }` for 14+ MIME categories (image, video, audio, PDF, Word, Excel, PowerPoint, Markdown, CSV, JSON, XML, code, archive, plain text). |
| `isVideoAttachment(attachment)` | MIME-or-extension probe. |
| `isAudioAttachment(attachment)` | MIME-or-extension probe. |
| `normaliseUploadResponse(data, file)` | Reduce a server upload response to a flat attachment record. |
| `normaliseAttachmentRecord(raw)` | Coerce arbitrary record shapes to the canonical `{ id, name, type, size, url, ... }` form. |
| `buildAttachmentMeta(record)` | Strip a record down to a metadata-only object. |
| `buildDisplayAttachment(attachment, state)` | Build a UI-friendly object with `url` resolved via `buildFileDownloadUrl` if needed. |
| `buildFileDownloadUrl(state, fileId)` | Append `file_id=<id>` to `state.config.filesEndpoint`. |
| `getAttachmentUrlFromRecord(record, state)` | Best-effort URL resolution. |
| `stripSegmentDisplayData(segment)` | Remove `blob:`/`data:` URLs and other display-only fields before sending to the API. |
| `createSegmentFromAttachment(attachment)` | Build an OpenAI-style content segment. |
| `addAttachmentMetadataToSegment(segment, attachment)` | Attach file_size, file_name, etc. |
| `createContentDispositionHeader(filename)` | RFC-safe `Content-Disposition: attachment; filename="…"`. |

### Caller-provided state shape

Several helpers accept a chat-state object. Only these keys are read:

```ts
interface ChatStateLike {
  allowedFileTypes?: string[];
  config?: {
    filesEndpoint?: string;
  };
}
```

## License

MIT — see `LICENSE`.
