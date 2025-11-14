# Voice Conversation Button Widget

## Overview

The Voice Conversation Button Widget is an Elementor widget that enables 2-way voice conversations with AI assistants. It provides a complete "interview me" experience by orchestrating voice recording, transcription, AI response generation, and speech synthesis.

## Features

- **One-Click Voice Interaction**: Single button to start/stop recording
- **Real-Time Feedback**: Visual indicators for recording, processing, and playing states
- **Conversation Context**: Maintains conversation history for contextual responses
- **Transcript Display**: Optional on-screen transcript of the conversation
- **Customizable Styling**: Full control over button appearance via Elementor
- **Guest Access**: Optional support for unauthenticated users
- **Auto-Play Responses**: Configurable automatic playback of AI responses

## Architecture

The widget follows strict separation of concerns:

### Client Side (JavaScript)
- `assets/js/voice-conversation.js`: Handles browser-based recording and playback
- Uses Web Audio API for microphone access
- Manages UI states (idle, recording, processing, playing)
- Sends audio to server for orchestration

### Server Side (PHP)
- `includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php`: Elementor widget class
- `includes/rest/class-wp-mcp-ai-rest-voice-conversation-controller.php`: REST API orchestration
- `includes/class-wp-mcp-ai-voice-conversation-assets.php`: Asset management

### Orchestration Flow

1. **User Records**: JavaScript captures audio via MediaRecorder API
2. **Upload**: Audio blob uploaded to REST endpoint `/mcp-ai/v1/voice-conversation`
3. **Transcription**: Server uses `transcribe_openai_audio` tool to convert speech to text
4. **AI Response**: Server sends transcription to configured assistant for response
5. **Speech Synthesis**: Server uses `generate_openai_speech` tool to create audio
6. **Playback**: JavaScript receives audio URL and plays response

## Usage

### Adding the Widget

1. Install Elementor (free or Pro)
2. Ensure WP oOS plugin is active
3. In Elementor editor, search for "WP oOS Voice Conversation"
4. Drag widget to your page

### Widget Settings

#### Voice Conversation Settings Tab

- **Assistant**: Select which AI assistant to use (default uses global setting)
- **Button Text**: Customize the button label (default: "Start Voice Conversation")
- **Recording Text**: Text shown while recording (default: "Recording…")
- **Processing Text**: Text shown while processing (default: "Processing…")
- **Max Recording Duration**: Maximum recording time in seconds (5-300, default: 60)
- **Auto-play Response**: Automatically play AI audio response (default: Yes)
- **Show Transcript**: Display conversation transcript below button (default: Yes)
- **Allow Guests**: Enable guest access without authentication (default: No)

#### Button Style Tab

- **Typography**: Font family, size, weight, etc.
- **Normal State**: Text color, background color
- **Hover State**: Hover text and background colors
- **Padding**: Button padding
- **Border Radius**: Rounded corners

## Browser Requirements

- Modern browser with Web Audio API support
- HTTPS connection (required for microphone access)
- Supported audio formats: WebM, MP4, MP3, WAV, OGG

## Dependencies

### Required
- WordPress 6.0+
- PHP 7.4+
- Elementor (free or Pro)
- WP oOS plugin with:
  - `transcribe_openai_audio` tool enabled
  - `generate_openai_speech` tool enabled
  - OpenAI API key configured

### Optional
- JetEngine (for advanced assistant management)

## Security

- **Permission Checks**: Validates user capabilities or guest tokens
- **File Type Validation**: Only accepts audio formats
- **Nonce Verification**: CSRF protection on API requests
- **Capability Filtering**: Respects assistant capability settings
- **File Size Limits**: Enforces max audio file size

## API Endpoint

### POST `/wp-json/mcp-ai/v1/voice-conversation`

**Request**:
```
Content-Type: multipart/form-data

audio: <audio blob>
assistant_id: <int>
allow_guests: <"0"|"1">
conversation_history: <JSON array>
```

**Response**:
```json
{
  "success": true,
  "data": {
    "user_text": "Transcribed user speech",
    "assistant_text": "AI response text",
    "audio_url": "https://example.com/wp-content/uploads/...",
    "transcription": {
      "text": "...",
      "language": "en",
      "duration": 5.2
    }
  }
}
```

## Customization

### Custom Styling

Add custom CSS in Elementor or your theme:

```css
/* Change button appearance */
.wp-mcp-ai-voice-button {
  /* Your styles */
}

/* Customize transcript */
.wp-mcp-ai-voice-transcript {
  /* Your styles */
}
```

### Extending Functionality

The widget can be extended via WordPress hooks:

```php
// Filter before audio upload
add_filter( 'wp_mcp_ai_voice_before_upload', function( $file ) {
    // Modify file before processing
    return $file;
} );

// Action after conversation
add_action( 'wp_mcp_ai_voice_conversation_complete', function( $data ) {
    // Log, notify, or process conversation data
} );
```

## Troubleshooting

### Microphone Access Denied
- Ensure site is served over HTTPS
- Check browser permissions for microphone
- Verify no other application is using microphone

### Audio Not Playing
- Check browser console for errors
- Verify OpenAI API key is configured
- Ensure `generate_openai_speech` tool is enabled

### Transcription Fails
- Verify OpenAI API key has access to Whisper API
- Check audio file is within size limits (25MB)
- Ensure `transcribe_openai_audio` tool is enabled

### Empty Response
- Check assistant configuration
- Verify assistant has appropriate tools enabled
- Review WP oOS logs for errors

## Development

### Running Tests

```bash
composer test tests/test-voice-conversation.php
```

### Code Quality

```bash
# PHP linting
composer run lint includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php
composer run lint includes/rest/class-wp-mcp-ai-rest-voice-conversation-controller.php

# JavaScript linting
npm run lint:js assets/js/voice-conversation.js
```

## Credits

Developed as part of the WP Open Operator System (WP oOS) plugin.

## License

GPLv3 or later
