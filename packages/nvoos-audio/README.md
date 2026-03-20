# @nvdigitalsolutions/nvoos-audio

Browser audio I/O for AI chat: **TTS (text-to-speech)**, **STT (speech-to-text / transcription)**, **audio translation**, and **voice chat with Voice Activity Detection** — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

**Zero external dependencies.** Uses only standard browser APIs: `MediaRecorder`, `Web Audio API`, `SpeechSynthesis`, `Fetch`.

## Why this package?

Every conversational AI UI needs audio I/O. This package bundles the full stack in one place:

| Feature | API |
|---------|-----|
| Text-to-speech playback | `attachSpeechButton()` |
| Stop/resume speech | `stopSpeechPlayback()` |
| Microphone → transcription | `handleTranscribeButtonClick()` |
| File upload → transcription | `handleTranscribeFileSelection()` |
| Audio → English translation | `handleTranslateButtonClick()` |
| Voice chat with VAD | `handleVoiceChatButtonClick()` |
| Feature detection | `supportsAudioRecording()` |

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-audio
```

## Quick Start

### Text-to-Speech

```javascript
import { attachSpeechButton } from '@nvdigitalsolutions/nvoos-audio';

// Attach a play/stop button to any message element
const bubble = document.querySelector('.ai-message');
const chatState = {
  config: {
    toolsEndpoint: 'https://your-api.com/wp-json/mcp-ai/v1/tools',
    nonce: 'your-nonce',
  },
  speechCache: {},
};
attachSpeechButton(bubble, null, chatState);
```

### Microphone Transcription

```javascript
import { handleTranscribeButtonClick, supportsAudioRecording } from '@nvdigitalsolutions/nvoos-audio';

if (supportsAudioRecording()) {
  const btn = document.querySelector('#transcribe-btn');
  const state = { config: { toolsEndpoint: '...', nonce: '...' } };
  const helpers = { setStatus: (container, msg) => console.log(msg) };

  btn.addEventListener('click', () => {
    handleTranscribeButtonClick(btn, state, helpers);
  });
}
```

### Customise CSS Class Names

```javascript
import { configure } from '@nvdigitalsolutions/nvoos-audio';

configure({
  speechButtonClass: 'my-tts-btn',
  speechEnabledClass: 'my-tts-enabled',
  transcribeRecordingClass: 'my-recording',
  voiceChatRecordingClass: 'my-voice-recording',
});
```

## API

### `configure(options)`

Override default CSS class names. Call once at startup before using any other function.

| Option | Default |
|--------|---------|
| `speechButtonClass` | `'wp-mcp-ai-speech-button'` |
| `speechEnabledClass` | `'wp-mcp-ai-speech-enabled'` |
| `speechErrorClass` | `'wp-mcp-ai-speech-button--error'` |
| `transcribeRecordingClass` | `'wp-mcp-ai-chat__transcribe--recording'` |
| `translateRecordingClass` | `'wp-mcp-ai-chat__translate--recording'` |
| `voiceChatRecordingClass` | `'wp-mcp-ai-chat__voice-chat--recording'` |
| `voiceChatProcessingClass` | `'wp-mcp-ai-chat__voice-chat--processing'` |
| `voiceChatListeningClass` | `'wp-mcp-ai-chat__voice-chat--listening'` |

### Object URL Management

```javascript
import { registerObjectUrl, revokeObjectUrls } from '@nvdigitalsolutions/nvoos-audio';

// Register blob URLs so they can be bulk-revoked on unmount
registerObjectUrl(blobUrl);

// On component teardown / page unload
revokeObjectUrls();
```

### `supportsAudioRecording()`

Returns `true` if the browser supports `MediaRecorder` and `getUserMedia`.

### `attachSpeechButton(bubble, text?, state, helpers?)`

Appends a play/stop TTS button to `bubble`. Handles caching, playback lifecycle, and error state internally.

### `updateSpeechButtonIcon(button, stateName)`

Programmatically set the button icon: `'idle'` | `'loading'` | `'playing'`.

### `stopSpeechPlayback(state, button)`

Stop ongoing audio playback and reset the button to idle.

### `handleTranscribeButtonClick(button, state, helpers?)`

Toggle microphone recording. On stop, uploads the audio and calls the transcription tool endpoint. Integrates with `wpMcpAiChatTranscription.insertTranscriptionResult()`.

### `handleTranscribeFileSelection(input, state, helpers?)`

Handle an `<input type="file">` change event for audio transcription.

### `extractTranscriptionResult(response)`

Extract the transcription result from a tool API response object.

### `handleTranslateButtonClick / handleTranslateFileSelection`

Same as transcription equivalents but calls the translation endpoint (output is always English).

### `handleVoiceChatButtonClick(button, state, helpers?)`

Toggle voice chat mode. Uses VAD (Voice Activity Detection) to detect end-of-speech, then submits the transcript as a chat message automatically.

### `updateVoiceChatButtonState(state)`

Sync the voice chat button's visual state with `state.voiceChatRecording` / `state.voiceChatProcessing`.

### `wpMcpAiChatTranscription`

The transcription service object, exported for direct use:

```javascript
import { wpMcpAiChatTranscription } from '@nvdigitalsolutions/nvoos-audio';

wpMcpAiChatTranscription.insertTranscriptionResult(state, result, record, formatDuration);
```

## VAD Configuration

Voice Activity Detection constants can be tuned via the `state.config` object passed to `handleVoiceChatButtonClick`:

```javascript
const state = {
  config: {
    toolsEndpoint: '...',
    nonce: '...',
    vadSilenceThresholdMs: 700,    // silence duration before stopping (ms)
    vadMinSpeechDurationMs: 300,   // minimum speech duration to process (ms)
    vadAudioLevelThreshold: -50,   // dB level considered "speech"
  }
};
```

## Browser Support

- Chrome/Edge 79+ (MediaRecorder + Web Audio)
- Firefox 90+
- Safari 15+

## TypeScript

Full TypeScript definitions included:

```typescript
import type { AudioConfig } from '@nvdigitalsolutions/nvoos-audio';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
