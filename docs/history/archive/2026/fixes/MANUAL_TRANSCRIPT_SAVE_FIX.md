# Manual Transcript Save Fix - Implementation Guide

## Overview

This document explains the fix for manual conversation saves to CCT (Custom Content Type) and how to integrate it with the frontend.

## Problem Statement

When users clicked the "plus" button to manually save a conversation to CCT, the following issues occurred:

1. **Lost Assistant Messages**: The response payload was created with an empty `choices` array, causing assistant messages to be lost when retrieving the transcript later.
2. **Missing Usage Data**: Token usage and other response metadata were not preserved.

## Solution

### Backend Changes

#### 1. Response Payload Construction

**File**: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

The `handle_chat_transcript_save()` method now:
- Constructs a proper response payload with assistant messages in the `choices` array
- Preserves tool calls in assistant messages
- Supports an optional `response_metadata` parameter for usage data

#### 2. New Helper Method

```php
private function build_response_from_messages( array $messages, $model, array $response_metadata = array() )
```

This method:
- Extracts all assistant messages from the conversation
- Formats them according to OpenAI API response schema
- Adds them to the `choices` array with proper structure:
  ```php
  [
      'index' => 0,
      'message' => [
          'role' => 'assistant',
          'content' => 'Assistant message text',
          'tool_calls' => [...] // If present
      ],
      'finish_reason' => 'stop'
  ]
  ```
- Optionally merges response metadata (usage, provider, etc.)

### API Changes

#### POST /mcp-ai/v1/chat-transcripts

**New Optional Parameter**: `response_metadata`

**Type**: Object

**Purpose**: Preserve response metadata (usage data, provider info, etc.) when manually saving a conversation.

**Allowed Fields**:
- `usage` (object) - Token usage data
- `provider` (string) - Provider identifier
- `id` (string) - Response ID from the API
- `created` (integer) - Timestamp from the API response
- `object` (string) - Object type from the API
- `service_tier` (string) - Service tier information  
- `system_fingerprint` (string) - System fingerprint from the API

**Example Request**:
```json
{
  "assistant_id": 123,
  "session_key": "wp-mcp-ai-abc123",
  "messages": [
    {
      "role": "user",
      "content": "Hello"
    },
    {
      "role": "assistant",
      "content": "Hi! How can I help?"
    }
  ],
  "response_metadata": {
    "usage": {
      "prompt_tokens": 22619,
      "completion_tokens": 103,
      "total_tokens": 22722,
      "prompt_tokens_details": {
        "cached_tokens": 9728,
        "audio_tokens": 0
      },
      "completion_tokens_details": {
        "reasoning_tokens": 0,
        "audio_tokens": 0,
        "accepted_prediction_tokens": 0,
        "rejected_prediction_tokens": 0
      }
    },
    "provider": "openai",
    "id": "chatcmpl-CfACL72FQ1oefKXTo0v1O0g0JDKVn",
    "created": 1763926685,
    "service_tier": "default",
    "system_fingerprint": "fp_433e8c8649"
  }
}
```

## Frontend Integration

### Without Response Metadata (Minimal)

The fix works without any frontend changes. Assistant messages are automatically preserved:

```javascript
// Existing code - works as-is
saveConversationToCCT(state, { silent: false });
```

### With Response Metadata (Recommended)

To preserve usage data and other metadata, you need to:

#### Option 1: Store Metadata with Each Message

Store response metadata when receiving chat responses:

```javascript
// When receiving a chat response
function handleChatResponse(response) {
    const assistantMessage = {
        role: 'assistant',
        content: response.choices[0].message.content,
        // Store metadata with the message
        _metadata: {
            usage: response.usage,
            provider: response.provider,
            id: response.id,
            created: response.created,
            service_tier: response.service_tier,
            system_fingerprint: response.system_fingerprint
        }
    };
    
    state.conversation.push(assistantMessage);
}
```

Then extract it when saving:

```javascript
// Extract the most recent response metadata
function getLastResponseMetadata(conversation) {
    // Find the last assistant message with metadata
    for (let i = conversation.length - 1; i >= 0; i--) {
        if (conversation[i].role === 'assistant' && conversation[i]._metadata) {
            return conversation[i]._metadata;
        }
    }
    return null;
}

// When saving
function saveWithMetadata(state) {
    const metadata = getLastResponseMetadata(state.conversation);
    
    const payload = {
        assistant_id: state.config.assistantId,
        session_key: state.config.sessionKey,
        messages: cleanMessages(state.conversation), // Remove _metadata before sending
        response_metadata: metadata // Add if available
    };
    
    fetch(state.config.transcriptsEndpoint, {
        method: 'POST',
        headers: buildJsonHeaders(state),
        body: JSON.stringify(payload)
    });
}

// Clean messages before sending
function cleanMessages(messages) {
    return messages.map(msg => {
        const { _metadata, ...cleanMsg } = msg;
        return cleanMsg;
    });
}
```

#### Option 2: Store Session-Level Metadata

Store metadata at the session level:

```javascript
// In your state object
const state = {
    conversation: [],
    sessionMetadata: {
        totalUsage: {
            prompt_tokens: 0,
            completion_tokens: 0,
            total_tokens: 0
        },
        provider: 'openai',
        lastResponseId: null
    }
};

// Update on each response
function handleChatResponse(response) {
    // Add message
    state.conversation.push(response.choices[0].message);
    
    // Aggregate usage
    if (response.usage) {
        state.sessionMetadata.totalUsage.prompt_tokens += response.usage.prompt_tokens || 0;
        state.sessionMetadata.totalUsage.completion_tokens += response.usage.completion_tokens || 0;
        state.sessionMetadata.totalUsage.total_tokens += response.usage.total_tokens || 0;
    }
    
    // Store other metadata
    state.sessionMetadata.lastResponseId = response.id;
    if (response.provider) {
        state.sessionMetadata.provider = response.provider;
    }
}

// When saving
function saveWithMetadata(state) {
    const payload = {
        assistant_id: state.config.assistantId,
        session_key: state.config.sessionKey,
        messages: state.conversation,
        response_metadata: {
            usage: state.sessionMetadata.totalUsage,
            provider: state.sessionMetadata.provider,
            id: state.sessionMetadata.lastResponseId
        }
    };
    
    fetch(state.config.transcriptsEndpoint, {
        method: 'POST',
        headers: buildJsonHeaders(state),
        body: JSON.stringify(payload)
    });
}
```

## Database Storage

### What Gets Saved

#### response_payload Field

Contains the complete response in OpenAI format:

```json
{
    "model": "gpt-4",
    "choices": [
        {
            "index": 0,
            "message": {
                "role": "assistant",
                "content": "Hello! How can I help you?"
            },
            "finish_reason": "stop"
        }
    ],
    "usage": {
        "prompt_tokens": 22619,
        "completion_tokens": 103,
        "total_tokens": 22722
    },
    "provider": "openai",
    "id": "chatcmpl-xyz"
}
```

#### metadata Field

Contains extracted metadata for quick access:

```json
{
    "provider": "openai",
    "response_id": "chatcmpl-xyz",
    "finish_reasons": ["stop"],
    "usage": {
        "prompt_tokens": 22619,
        "completion_tokens": 103,
        "total_tokens": 22722
    },
    "session_key_raw": "wp-mcp-ai-abc123"
}
```

## Testing

### Running Tests

```bash
# Run specific test file
vendor/bin/phpunit tests/test-chat-transcript-response-payload-construction.php

# Run all transcript tests
vendor/bin/phpunit tests/test-chat-transcript*.php
```

### Test Coverage

The fix includes comprehensive tests for:

1. ✅ Response payload contains assistant messages in choices array
2. ✅ Tool calls are preserved in assistant messages
3. ✅ Non-assistant messages are excluded from choices
4. ✅ Response metadata is properly merged when provided
5. ✅ Usage data is preserved
6. ✅ All allowed metadata fields are sanitized correctly

## Migration Notes

### Existing Transcripts

Transcripts saved before this fix may have:
- Empty `choices` arrays in response_payload
- Missing assistant messages when retrieved

**Impact**: These old transcripts cannot be automatically fixed. They will show incomplete conversation history when loaded.

**Recommendation**: Inform users that conversations saved before this fix may be incomplete.

### Forward Compatibility

All new transcripts saved after this fix will:
- ✅ Preserve assistant messages correctly
- ✅ Support tool calls and agentic workflows
- ✅ Optionally preserve usage data
- ✅ Work correctly when retrieved

## Backward Compatibility

The fix is **fully backward compatible**:

- ✅ The `response_metadata` parameter is optional
- ✅ Existing save code continues to work
- ✅ No breaking changes to the API
- ✅ Frontend changes are optional (but recommended for full functionality)

## Security Considerations

### Sanitization

The implementation sanitizes all metadata fields:

- `usage` - Validated as array
- `provider` - Sanitized with `sanitize_key()`
- `id`, `object`, `service_tier`, `system_fingerprint` - Sanitized with `sanitize_text_field()`
- `created` - Cast to integer with `absint()`

### Allowed Fields

Only specific whitelisted fields are accepted in `response_metadata` to prevent injection of arbitrary data.

## Troubleshooting

### Assistant Messages Not Appearing

**Symptom**: Saved conversation shows user messages but no assistant responses.

**Cause**: Transcript was saved before this fix.

**Solution**: This is expected. The conversation needs to be re-saved with the new implementation.

### Usage Data Not Saved

**Symptom**: Transcript saves successfully but usage data is missing in CCT.

**Cause**: Frontend is not sending `response_metadata` parameter.

**Solution**: Implement one of the frontend integration options described above.

### Invalid Metadata Warning

**Symptom**: Save succeeds but some metadata fields are missing.

**Cause**: Field names don't match the allowed list or values are incorrectly formatted.

**Solution**: Check that field names match exactly: `usage`, `provider`, `id`, `created`, `object`, `service_tier`, `system_fingerprint`.

## Next Steps

1. **Code Review**: Review the changes in the pull request
2. **Testing**: Run the test suite to verify all tests pass
3. **Frontend Integration**: Decide on metadata storage strategy (per-message or session-level)
4. **User Communication**: Inform users about the fix and any limitations with old transcripts
5. **Monitoring**: Monitor transcript saves to ensure usage data is being preserved correctly

## Questions?

For questions or issues with this implementation, please refer to:
- Pull Request: [Link to PR]
- Test File: `tests/test-chat-transcript-response-payload-construction.php`
- Implementation: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`
