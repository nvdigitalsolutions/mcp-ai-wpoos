# Chat Button Communication Flow & File Management

## Overview
This document explains the complete back-and-forth communication flow when users interact with chat buttons (voice chat, transcribe) and how files are managed throughout the process.

## Communication Flow for Transcribe Button

### Step 1: User Clicks Transcribe Button
```
User clicks button
  → handleTranscribeButtonClick(state)
    → Checks supportsAudioRecording()
      → Shows confirm dialog (record vs upload file)
```

### Step 2A: Record Audio Path
```
User chooses to record
  → startTranscribeRecording(state)
    → navigator.mediaDevices.getUserMedia({audio: true})
      → state.mediaRecorder = new MediaRecorder(stream)
        → state.mediaRecorder.addEventListener('dataavailable', ...)
          → Collects audio chunks in state.recordedChunks[]
```

**Communication State:**
- `state.isRecording = true`
- `state.recordingShouldProcess = true`
- Button class: `wp-mcp-ai-chat__transcribe--recording`
- Status: "Recording... tap to stop"

### Step 2B: Upload File Path
```
User chooses to upload file
  → state.transcribeInput.click()
    → handleTranscribeFileSelection(event, state)
      → Validates file (MAX_TRANSCRIBE_BYTES = 25MB)
        → transcribeAudioFile(state, file)
```

### Step 3: Stop Recording (if recording)
```
User clicks button again
  → stopTranscribeRecording(state)
    → state.mediaRecorder.stop()
      → 'stop' event fires
        → Creates Blob from state.recordedChunks
          → blob = new Blob(chunks, {type: 'audio/webm'})
```

### Step 4: Upload Audio to WordPress
```
transcribeAudioFile(state, blob)
  → uploadAudioForTranscription(state, file)
    → POST state.config.uploadEndpoint
      Headers:
        - X-WP-Nonce: globalConfig.nonce
        - Content-Disposition: attachment; filename="audio"
        - Content-Type: audio/webm
      Body: file (binary)
    → Response: WordPress attachment object
      → normaliseUploadResponse(data, file)
        Returns: {
          id: 123,
          fileId: 'wp-attachment-123',
          name: 'audio.webm',
          url: 'https://example.com/wp-content/uploads/...',
          mime: 'audio/webm',
          size: 12345
        }
```

**File Storage:**
- File uploaded to WordPress Media Library
- Attachment ID returned
- Added to `state.attachmentLibrary[fileId] = record`

### Step 5: Request Transcription
```
requestTranscription(state, record)
  → POST state.config.toolsEndpoint
    Payload: {
      assistant_id: state.config.assistantId,
      tool: 'transcribe_openai_audio',
      arguments: {
        attachment_id: record.id
      }
    }
  → Server calls OpenAI Whisper API
    → Returns transcription result
```

**Back-End Communication:**
1. WordPress receives tool request
2. Tool registry executes `transcribe_openai_audio` tool
3. Tool fetches attachment from WordPress
4. Tool sends audio to OpenAI Whisper API
5. Returns transcription text

### Step 6: Display Result
```
extractTranscriptionResult(response)
  → insertTranscriptionResult(state, result, record)
    → state.textarea.value = result.text
    → Renders metadata (language, duration, segments)
```

**Final State:**
- Transcribed text inserted into textarea
- User can edit before sending
- File reference stored in attachment library

## Communication Flow for Voice Chat Button

### Step 1: User Clicks Voice Chat Button
```
User clicks button
  → handleVoiceChatButtonClick(state)
    → navigator.mediaDevices.getUserMedia({audio: true})
      → state.voiceChatRecorder = new MediaRecorder(stream)
```

**Communication State:**
- `state.isVoiceChatRecording = true`
- `state.voiceChatModeActive = true`
- Button class: `wp-mcp-ai-chat__voice-chat--recording`
- Status: "Recording... tap to stop"

### Step 2: Stop Recording
```
User clicks button again
  → state.voiceChatRecorder.stop()
    → Creates Blob from state.voiceChatChunks
      → processVoiceChatAudio(state, blob)
```

### Step 3: Upload & Transcribe
```
processVoiceChatAudio(state, blob)
  → uploadAudioForTranscription(state, file)
    (same as transcribe flow)
  → requestTranscription(state, record)
    (same as transcribe flow)
  → Automatically sends message with transcribed text
```

**Key Difference:**
- Voice chat **automatically sends** the message
- Transcribe button **inserts into textarea** for editing

### Step 4: Auto-Send Message
```
state.textarea.value = result.text
state.voiceChatModeActive = true
form.dispatchEvent(new Event('submit'))
```

### Step 5: Auto-Play Response
```
When assistant response arrives:
  → appendMessage(..., { voiceChatModeActive: true })
    → attachSpeechButton(...) 
      → Auto-triggers speech button after 300ms
        → Assistant response is spoken aloud
```

## File Management Architecture

### Client-Side File Storage

#### 1. Pending Attachments
```javascript
state.pendingAttachments = [
    {
        id: 123,
        fileId: 'wp-attachment-123',
        name: 'document.pdf',
        url: 'https://...',
        mime: 'application/pdf',
        size: 123456,
        isImage: false
    }
];
```

**Purpose**: Files uploaded but not yet sent in a message

#### 2. Attachment Library
```javascript
state.attachmentLibrary = {
    'wp-attachment-123': { /* attachment record */ },
    'wp-attachment-456': { /* attachment record */ }
};
```

**Purpose**: 
- Quick lookup by fileId
- Persist across page loads (localStorage)
- Share between chat instances
- Reuse attachments without re-uploading

#### 3. Message Attachments
```javascript
{
    role: 'user',
    content: [
        { type: 'text', text: 'Here is the audio' },
        {
            type: 'input_file',
            attachment_id: 123,
            url: 'https://...',
            display_name: 'audio.webm'
        }
    ]
}
```

**Purpose**: Files included in sent messages

### Server-Side File Flow

#### 1. Upload Endpoint (`/files`)
```
POST /wp-json/mcp-ai/v1/files
  → WP_MCP_AI_REST_Files_Controller::upload()
    → wp_handle_upload()
      → Creates WordPress attachment
      → Returns attachment object
```

#### 2. Tool Execution (`/tools`)
```
POST /wp-json/mcp-ai/v1/tools
  → WP_MCP_AI_Tool_Transcribe_OpenAI_Audio::execute()
    → Gets attachment by ID
    → Downloads file content
    → Sends to OpenAI API
    → Returns transcription
```

#### 3. Chat Message (`/chat-client`)
```
POST /wp-json/mcp-ai/v1/chat-client
  → Message includes attachment_id
    → AI provider receives file reference
      → For vision: Downloads and encodes image
      → For tools: Passes attachment_id to tool
```

## Helper Functions Needed

### Current Gap Analysis

The communication flow requires these helper functions that are **missing**:

### 1. File Upload Progress Tracking
```javascript
/**
 * Track file upload progress with visual feedback
 * 
 * @param {Object} state - Chat state
 * @param {File} file - File being uploaded
 * @param {Function} onProgress - Progress callback
 * @return {Promise} Upload promise
 */
function uploadFileWithProgress(state, file, onProgress) {
    // Uses XMLHttpRequest for progress events
    // Updates UI with progress percentage
}
```

### 2. Audio Recording State Management
```javascript
/**
 * Centralized audio recording state manager
 * 
 * @param {Object} state - Chat state
 * @return {Object} Recording controller
 */
function createRecordingController(state) {
    return {
        start: function() { /* ... */ },
        stop: function() { /* ... */ },
        pause: function() { /* ... */ },
        resume: function() { /* ... */ },
        getState: function() { /* ... */ }
    };
}
```

### 3. Attachment Validation
```javascript
/**
 * Validate file before upload
 * 
 * @param {File} file - File to validate
 * @param {Object} constraints - Validation constraints
 * @return {Object} Validation result
 */
function validateAttachment(file, constraints) {
    return {
        valid: true/false,
        errors: ['File too large', 'Invalid type'],
        warnings: ['File may take long to process']
    };
}
```

### 4. Attachment Library Management
```javascript
/**
 * Add attachment to library with deduplication
 * 
 * @param {Object} state - Chat state
 * @param {Object} attachment - Attachment record
 * @return {string} File ID
 */
function addToAttachmentLibrary(state, attachment) {
    // Check for duplicates (same hash/size)
    // Add to library
    // Persist to localStorage
    // Return fileId
}

/**
 * Get attachment from library
 * 
 * @param {Object} state - Chat state
 * @param {string} fileId - File identifier
 * @return {Object|null} Attachment record
 */
function getFromAttachmentLibrary(state, fileId) {
    return state.attachmentLibrary[fileId] || null;
}

/**
 * Remove attachment from library
 * 
 * @param {Object} state - Chat state
 * @param {string} fileId - File identifier
 */
function removeFromAttachmentLibrary(state, fileId) {
    delete state.attachmentLibrary[fileId];
    delete state.pendingAttachments[fileId];
    // Update UI
    // Persist to localStorage
}
```

### 5. Recording Timer Display
```javascript
/**
 * Display recording time counter
 * 
 * @param {Element} element - Element to update
 * @param {number} startTime - Recording start timestamp
 * @return {Function} Cleanup function
 */
function displayRecordingTimer(element, startTime) {
    const interval = setInterval(function() {
        const elapsed = Date.now() - startTime;
        const seconds = Math.floor(elapsed / 1000);
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        element.textContent = minutes + ':' + String(secs).padStart(2, '0');
    }, 1000);
    
    return function() {
        clearInterval(interval);
    };
}
```

### 6. Audio Waveform Visualization
```javascript
/**
 * Display audio waveform during recording
 * 
 * @param {Element} canvas - Canvas element
 * @param {MediaStream} stream - Audio stream
 * @return {Function} Cleanup function
 */
function displayAudioWaveform(canvas, stream) {
    const audioContext = new AudioContext();
    const analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(stream);
    source.connect(analyser);
    
    // ... animation logic
    
    return function() {
        source.disconnect();
        audioContext.close();
    };
}
```

## Recommended Implementation

Add these helper functions to `chat-ui-utilities-service.js`:

### Priority 1 (Critical for Communication Flow)
1. ✅ `validateAttachment()` - Prevent upload errors
2. ✅ `addToAttachmentLibrary()` - Centralized file storage
3. ✅ `getFromAttachmentLibrary()` - File retrieval
4. ✅ `removeFromAttachmentLibrary()` - File cleanup

### Priority 2 (Enhanced UX)
5. ⚠️ `uploadFileWithProgress()` - Visual feedback
6. ⚠️ `displayRecordingTimer()` - Time tracking

### Priority 3 (Nice to Have)
7. ❌ `createRecordingController()` - Advanced state management
8. ❌ `displayAudioWaveform()` - Visual feedback (requires canvas)

## Conclusion

The chat button communication flow involves:
1. **User Interaction** → Button click
2. **Permission Request** → Microphone access
3. **Recording** → MediaRecorder API
4. **File Creation** → Blob from chunks
5. **Upload** → WordPress Media Library
6. **Tool Execution** → Transcription service
7. **Result Display** → Text in textarea or auto-send

**Missing Helper Functions:**
- File validation before upload
- Attachment library management
- Upload progress tracking
- Recording timer display

These helpers will improve the communication flow and file management experience.
