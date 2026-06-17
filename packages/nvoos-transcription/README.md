# @nvdigitalsolutions/nvoos-transcription

**MediaRecorder-based audio recording + tool-call transcription pipeline** for AI chat surfaces — extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

A 13-method service that handles the full voice-input lifecycle:

1. **Recording** — `MediaRecorder` capture with auto-stop on size cap (25 MB), graceful fallback to a file-picker on browsers without `getUserMedia`.
2. **Upload** — POSTs the captured Blob to your file-upload endpoint and normalises the response.
3. **Transcribe** — issues a tool call (default name: `transcribe_openai_audio`) against your tool-execution endpoint.
4. **Insert** — appends the transcribed text into the chat textarea, preserving existing user input.

**Zero external dependencies.** Pairs naturally with [`@nvdigitalsolutions/nvoos-audio`](../nvoos-audio) (lower-level audio I/O) and [`@nvdigitalsolutions/nvoos-http-client`](../nvoos-http-client) (resilient HTTP).

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-transcription
```

## Quick Start

```javascript
import { TranscriptionService, configure } from '@nvdigitalsolutions/nvoos-transcription';

// Optional: align CSS class + selector with your DOM
configure({
  recordingClass:           'my-app-transcribe--recording',
  fileSelectTriggerSelector: '[data-file-trigger]',
});

// All methods accept (state, helpers) — bring your own state object.
const state = {
  transcribeButton: document.querySelector('.transcribe'),
  transcribeInput:  document.querySelector('input[type=file][accept^=audio]'),
  textarea:         document.querySelector('textarea'),
  container:        document.querySelector('.chat'),
  config: {
    filesEndpoint: '/api/files',
    toolsEndpoint: '/api/tools',
    nonce:         myNonce,
    assistantId:   42,
  },
  isRecording: false,
  transcribing: false,
  uploading: 0,
  busy: false,
  canUploadAttachments: true,
};

const helpers = {
  getString: (key, fallback) => fallback,
  setStatus: (container, msg) => { container.querySelector('.status').textContent = msg; },
  // The service composes its own helpers — these are forwarded back.
  transcribeAudioFile:        (s, f, h) => TranscriptionService.transcribeAudioFile(s, f, h),
  stopRecordingStream:        (s) => TranscriptionService.stopRecordingStream(s),
  setTranscribeRecordingState: (s, r, gs, ss) => TranscriptionService.setTranscribeRecordingState(s, r, gs, ss),
  updateTranscribeButtonState: (s) => TranscriptionService.updateTranscribeButtonState(s),
  startTranscribeRecording:   (s) => TranscriptionService.startTranscribeRecording(s, helpers),
};

state.transcribeButton.addEventListener('click', () => {
  TranscriptionService.handleTranscribeButtonClick(state, helpers);
});
```

## API surface (13 methods)

### Capability detection

| Method | Purpose |
|--------|---------|
| `supportsAudioRecording()` | True iff `navigator.mediaDevices.getUserMedia` and `MediaRecorder` are both available. |

### Recording lifecycle

| Method | Purpose |
|--------|---------|
| `handleTranscribeButtonClick(state, helpers)` | Top-level button click handler. Routes to recording or to the file-picker fallback. |
| `startTranscribeRecording(state, helpers)` | Acquires microphone, starts `MediaRecorder`, wires `dataavailable` + `stop` events. |
| `stopTranscribeRecording(state, helpers)` | Stops the recorder cleanly. |
| `setTranscribeRecordingState(state, recording, getString?, setStatus?)` | Toggles the configurable `recordingClass`, updates aria-label and status string. |
| `stopRecordingStream(state)` | Calls `track.stop()` on every track and nulls the stream. Always safe to call. |
| `updateTranscribeButtonState(state)` | Recomputes the disabled flag from `state.busy / uploading / transcribing / isRecording`. |

### Transcription pipeline

| Method | Purpose |
|--------|---------|
| `handleTranscribeFileSelection(event, state, transcribeAudioFile)` | `<input type="file">` change handler. |
| `transcribeAudioFile(state, file, helpers)` | Top-level pipeline: validates → uploads → transcribes → inserts. |
| `uploadAudioForTranscription(state, file, helpers)` | POSTs the audio Blob to `state.config.filesEndpoint`. Returns a normalised file record. |
| `requestTranscription(state, record, helpers)` | Issues a tool call to `state.config.toolsEndpoint` with the configured `TRANSCRIBE_TOOL_NAME`. |
| `extractTranscriptionResult(body)` | Pulls the transcribed text from any of several known tool-result shapes. |
| `insertTranscriptionResult(state, result, record, formatDuration)` | Appends the text to `state.textarea.value`, preserving user input. |

### Constants

| Constant | Value | Configurable |
|----------|-------|--------------|
| `TRANSCRIBE_TOOL_NAME` | `'transcribe_openai_audio'` | No (use a wrapper if you need a different tool). |
| `TRANSCRIBE_RECORDING_CLASS` | `'nvoos-transcribe--recording'` | Yes via `configure({ recordingClass })`. |
| `MAX_TRANSCRIBE_BYTES` | `26214400` (25 MB) | No. |

## State shape

The `state` argument is mutated directly. Required keys:

```ts
{
  transcribeButton: HTMLElement | null;
  transcribeInput:  HTMLInputElement | null;
  textarea:         HTMLTextAreaElement | null;
  container:        HTMLElement | null;

  isRecording: boolean;
  transcribing: boolean;
  uploading: number;
  busy: boolean;
  canUploadAttachments: boolean;

  // Populated by the service during recording:
  mediaRecorder?:        MediaRecorder | null;
  recordingStream?:      MediaStream | null;
  recordedChunks?:       Blob[];
  recordingShouldProcess?: boolean;

  // Endpoint configuration:
  config: {
    filesEndpoint: string;
    toolsEndpoint: string;
    nonce?:        string;
    assistantId?:  string | number;
  };
}
```

## License

MIT — see `LICENSE`.
