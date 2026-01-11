# Voice Agent Pattern Implementation

**Date**: January 11, 2026  
**Status**: ✅ IMPLEMENTED - Full OpenAI Voice Agent pattern support

## Overview

This plugin implements the OpenAI Voice Agent pattern, providing seamless voice-to-voice AI conversations. The implementation follows the model chaining approach recommended in the [OpenAI Voice Agents Guide](https://platform.openai.com/docs/guides/voice-agents).

## Architecture

### Model Chain

```
User Voice Input
      ↓
[Step 1: Speech-to-Text]
whisper-1 (OpenAI Whisper)
      ↓
Transcribed Text
      ↓
[Step 2: AI Processing]
gpt-4.1, gpt-4o, gpt-4o-mini (configurable)
      ↓
AI Response Text
      ↓
[Step 3: Text-to-Speech]
tts-1 (standard) or tts-1-hd (high quality)
      ↓
Audio Response → Auto-play
```

### User Experience Flow

1. 🎤 **User clicks voice chat button** → Recording starts
2. 🎤 **User clicks again** → Recording stops
3. 🔄 **System automatically:**
   - Transcribes audio
   - Sends to AI
   - Generates speech
   - **Plays response audio** (no manual intervention)
4. ✅ **Done!** Seamless voice conversation

## Implementation Details

### Frontend Implementation

**File**: `assets/js/chat-audio-service.js`

**Key Components**:

1. **Voice Chat Button Handler** (Line 1021):
   ```javascript
   function handleVoiceChatButtonClick(state, helpers)
   ```
   - Starts/stops recording
   - Manages recording state

2. **Audio Processing** (Line 1241):
   ```javascript
   function processVoiceChatAudio(state, blob, helpers)
   ```
   - Uploads audio for transcription
   - **Sets `state.voiceChatModeActive = true`** (critical for auto-playback)
   - Triggers form submission
   - Displays status messages

3. **Auto-playback Logic** (in `chat.js` lines 11284-11293):
   ```javascript
   if (state.voiceChatModeActive && streamingMessageElement) {
       setTimeout(function() {
           const speechButton = streamingMessageElement.querySelector('.wp-mcp-ai-speech-button');
           if (speechButton && speechButton.dataset.speechText) {
               handleSpeechButtonClick(state, speechButton);
           }
           state.voiceChatModeActive = false;
       }, 300);
   }
   ```

### Backend Configuration

**Admin Settings**: Settings → NV oOS → Providers → OpenAI

**Transcription Settings**:
- Model: `openai_transcribe_model`
  - `whisper-1` - OpenAI Whisper model (official)
- Response Format: `openai_transcribe_response_format`
- Language: `openai_transcribe_language` (optional)
- Temperature: `openai_transcribe_temperature` (optional)

**Chat Settings**:
- Model: `default_model`
  - Examples: `gpt-4.1`, `gpt-4o`, `gpt-4o-mini`, `claude-3-5-sonnet-20241022`

**TTS Settings**:
- Model: `openai_speech_model`
  - `tts-1` - Standard quality (default)
  - `tts-1-hd` - High quality audio
- Voice: `openai_speech_voice` (alloy, echo, fable, onyx, nova, shimmer)
- Format: `openai_speech_format` (mp3, aac, flac, opus, pcm, wav)

### Tools Integration

**Transcription Tool**: `transcribe_openai_audio`
- **File**: `includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php`
- **Endpoint**: `/v1/audio/transcriptions`
- **Max file size**: 25MB
- **Supported formats**: MP3, WAV, WebM, OGG, M4A, FLAC, MP4, AMR, Opus

**Speech Tool**: `generate_openai_speech`
- **File**: `includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php`
- **Endpoint**: `/v1/audio/speech`
- **Output**: WordPress Media Library attachment
- **Caching**: Client-side audio caching for performance

## State Management

### Critical State Variables

1. **`state.voiceChatModeActive`** (Boolean)
   - Set to `true` after voice transcription completes
   - Enables auto-playback of AI response
   - Reset to `false` after playback starts

2. **`state.isVoiceChatRecording`** (Boolean)
   - Indicates recording in progress
   - Used for UI state management

3. **`state.voiceChatProcessing`** (Boolean)
   - Indicates processing (transcription) in progress
   - Disables button during processing

4. **`state.voiceChatShouldProcess`** (Boolean)
   - Flag to indicate if recording should be processed
   - Prevents processing if user cancels

### State Transitions

```
Idle → Recording → Processing → Sending → Waiting → Playing → Idle
  ↓                                                      ↓
Cancel ─────────────────────────────────────────────────→
```

## UI/UX Design

### Button States

**Voice Chat Button** (`.wp-mcp-ai-chat__voice-chat`):
- **Idle**: Default microphone icon
- **Recording**: Pulsing red animation (`.wp-mcp-ai-chat__voice-chat--recording`)
- **Processing**: Blue background with spinner (`.wp-mcp-ai-chat__voice-chat--processing`)
- **Disabled**: Grayed out (during chat busy state)

### Status Messages

1. **Recording**: "Recording… tap to stop and send."
2. **Processing**: "Processing your voice message…"
3. **Sending**: "Sending your message…"
4. **Error States**:
   - "Microphone access was denied."
   - "Voice chat failed. Please try again or type your message."
   - "Voice chat service is temporarily unavailable."

### CSS Classes

```css
.wp-mcp-ai-chat__voice-chat { /* Base button styles */ }
.wp-mcp-ai-chat__voice-chat--recording { /* Red pulsing animation */ }
.wp-mcp-ai-chat__voice-chat--processing { /* Blue background */ }
.wp-mcp-ai-speech-button { /* Speech playback button */ }
.wp-mcp-ai-speech-button--error { /* Error state */ }
```

## Error Handling

### Microphone Permissions

```javascript
navigator.mediaDevices.getUserMedia({ audio: true })
    .catch(function(error) {
        // Handle permission denied
        helpers.setStatus(
            state.container,
            'Microphone access was denied.'
        );
    });
```

### Network Errors

- **404**: Service unavailable message
- **Tools endpoint unavailable**: Configuration error message
- **Transcription failed**: Generic error with retry option

### Audio Processing Errors

- **No audio recorded**: "No audio was recorded."
- **File too large**: "The recorded audio is too large. Please try a shorter message."
- **No text transcribed**: Caught and reported to user

## Performance Optimizations

1. **Audio Caching**: Speech audio cached client-side in `state.speechCache`
2. **Object URL Management**: Automatic cleanup of blob URLs
3. **Debouncing**: 300ms delay before auto-playing response
4. **Stream Cleanup**: MediaRecorder tracks properly stopped and released

## Security Considerations

1. **Capability Checks**: Voice chat requires `upload_files` capability or guest access
2. **File Size Limits**: 25MB maximum to prevent abuse
3. **MIME Type Validation**: Only audio files accepted
4. **Nonce Verification**: WordPress REST API nonces required
5. **Attachment Storage**: Files stored as WordPress Media Library items

## Testing Guide

### Manual Testing Checklist

- [ ] Click voice chat button → See recording indicator
- [ ] Record 3-5 seconds of audio
- [ ] Click again to stop → See "Processing" message
- [ ] Verify transcription appears in chat
- [ ] Verify message auto-sends to AI
- [ ] Verify AI response appears as text
- [ ] **Verify AI response plays as audio automatically**
- [ ] Test microphone permission denial
- [ ] Test with network disconnected
- [ ] Test canceling during recording

### Browser Compatibility

**Supported**:
- Chrome/Edge 49+ (MediaRecorder API)
- Firefox 25+ (MediaRecorder API)
- Safari 14.1+ (MediaRecorder API)

**Fallback**:
- Browsers without MediaRecorder: Voice chat button hidden
- Users can still use file upload for transcription

## Comparison with OpenAI Realtime API

### This Implementation vs OpenAI Realtime API

**This Plugin (HTTP + REST)**:
- ✅ Simpler to implement and maintain
- ✅ Works with any OpenAI model (including GPT-4.1)
- ✅ Full control over each step
- ✅ WordPress-native architecture
- ⚠️ Higher latency (~2-4 seconds total)
- ⚠️ No true real-time streaming

**OpenAI Realtime API (WebSocket)**:
- ✅ Very low latency (<1 second)
- ✅ True streaming audio
- ✅ Voice Activity Detection (VAD)
- ⚠️ More complex implementation
- ⚠️ Limited to gpt-4o-realtime models
- ⚠️ Requires WebSocket management

### When to Use This Implementation

✅ **Use this implementation when:**
- You want to use GPT-4.1 or other non-realtime models
- Simplicity and maintainability are priorities
- 2-4 second latency is acceptable
- You need WordPress integration
- You want to leverage existing WordPress features

❌ **Consider Realtime API when:**
- Sub-second latency is critical
- True conversational interruptions needed
- Phone/voice call replacement required
- Budget allows for premium pricing

## Future Enhancements

### Potential Improvements

1. **Streaming TTS**: Use server-sent events for faster audio playback
2. **Voice Activity Detection**: Auto-stop recording when user finishes speaking
3. **Multi-language Support**: Detect and translate languages automatically
4. **Custom Wake Words**: "Hey Assistant" style activation
5. **Conversation Context**: Maintain multi-turn voice conversations
6. **Voice Profiles**: Remember user voice preferences

## References

- [OpenAI Voice Agents Guide](https://platform.openai.com/docs/guides/voice-agents)
- [OpenAI Realtime Agents GitHub](https://github.com/openai/openai-realtime-agents)
- [OpenAI Audio API Documentation](https://platform.openai.com/docs/guides/speech-to-text)
- [MDN MediaRecorder API](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder)

## Changelog

### 2026-01-11: Voice Agent Pattern Implementation & Model Corrections
- **CORRECTED**: Updated to use official OpenAI model names (`tts-1`, `whisper-1`)
- Fixed auto-playback by setting `voiceChatModeActive` flag
- Improved form submission and status messages
- Rebuilt JavaScript bundles with fixes
- Created comprehensive documentation

### 2025-11-23: Initial Audio Features
- Implemented speech synthesis (TTS)
- Implemented audio transcription (STT)
- Implemented voice chat recording
- Created audio service module

---

**Maintained by**: NV Digital Solutions  
**Last Updated**: January 11, 2026
