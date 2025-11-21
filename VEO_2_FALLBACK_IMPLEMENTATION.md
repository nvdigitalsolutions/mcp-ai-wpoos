# Veo 2.0 Fallback Implementation Summary

## Overview
Implemented automatic fallback from Veo 3.1 to Veo 2.0 for video generation when Veo 3.1 is unavailable or quota limits are reached.

## Changes Made

### 1. Service Layer (`includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`)

#### New Constants
- `VEO_2_MODEL = 'veo-2.0-generate-001'` - Veo 2.0 model identifier
- `VEO_2_MIN_DURATION = 5` - Minimum duration for Veo 2.0 (5 seconds vs 4 for Veo 3.1)

#### New/Modified Methods

**`generate_video()` - Main Entry Point**
- Now tries Veo 3.1 first
- Automatically falls back to Veo 2.0 when quota/availability issues detected
- Supports explicit model selection via `$args['model']` parameter
- Returns metadata about which model was used and whether fallback occurred

**`generate_video_with_model()` - Model-Specific Generation** (NEW)
- Handles generation for a specific model (Veo 3.1 or 2.0)
- Maintains SoC by delegating to appropriate methods
- Stores model information for async operations

**`should_fallback_to_veo_2()` - Error Detection** (NEW)
- Detects quota/rate limit errors: "quota", "rate limit", "resource exhausted"
- Detects availability errors: "not available", "unavailable", "not found", "not supported"
- Checks HTTP status codes: 403 (Forbidden), 429 (Too Many Requests), 503 (Service Unavailable)
- Returns `false` for non-retryable errors (validation, authentication, etc.)

**`build_generation_payload()` - Model-Aware Payload Building**
- Now accepts optional `$model` parameter
- Enforces Veo 2-specific constraints:
  - Minimum duration of 5 seconds (vs 4 for Veo 3.1)
  - Automatically downgrades 1080p to 720p for Veo 2.0
  - Logs downgrade events for monitoring
- Maintains Veo 3.1 support for 1080p with 8-second requirement

**`submit_generation_request()` - Model-Aware API Calls**
- Now accepts optional `$model` parameter
- Uses correct model in API endpoint URL
- Defaults to Veo 3.1 if not specified

**`process_completed_video()` - Model Metadata**
- Now accepts and returns model information
- Ensures proper attribution of which model generated the video

**`queue_async_polling()` - Async Model Tracking**
- Stores model information in async metadata
- Ensures async completion uses correct model for processing

**`poll_video_async()` - Async Model Handling**
- Retrieves and uses stored model from metadata
- Passes model to `process_completed_video()`

### 2. Tool Layer (`includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`)

#### Updated Documentation
- Class docblock now mentions automatic fallback functionality
- `get_description()` updated to explain:
  - Veo 3.1 preferred with fallback to Veo 2.0
  - Veo 3.1: up to 1080p, 4-8 seconds
  - Veo 2.0: up to 720p, 5-8 seconds
  - Automatic fallback on quota/availability issues

#### Updated Parameters Schema
- Added `model` parameter (optional):
  - Type: string
  - Enum: ['veo-3.1', 'veo-2.0']
  - Description explains automatic fallback behavior
- Updated `duration` description to mention model-specific ranges
- Updated `resolution` description to explain 1080p is Veo 3.1 only

### 3. Tests (`tests/test-veo-2-fallback.php`)

Comprehensive test coverage for:
- ✅ Veo 2 constants defined correctly
- ✅ Minimum duration enforcement (5 seconds for Veo 2)
- ✅ Fallback detection for quota errors
- ✅ Fallback detection for availability errors
- ✅ Fallback detection for HTTP status codes (403, 429, 503)
- ✅ Non-fallback on validation/auth errors (400, etc.)
- ✅ Resolution downgrade (1080p → 720p for Veo 2)
- ✅ 1080p support maintained for Veo 3.1
- ✅ Async metadata includes model information
- ✅ Tool description mentions fallback
- ✅ Tool schema includes model parameter

## Architecture & SoC Compliance

### Separation of Concerns Maintained
1. **Service Layer** (`WP_MCP_AI_Gemini_Video_Generation_Service`)
   - Handles all API communication
   - Manages fallback logic
   - Enforces model-specific constraints
   - Independent of WordPress tool system

2. **Tool Layer** (`WP_MCP_AI_Tool_Generate_Veo_Video`)
   - Provides user-facing interface
   - Validates user permissions
   - Delegates to service layer
   - No direct API knowledge

3. **Clear Boundaries**
   - Tool calls service via `generate_video()`
   - Service handles model selection and fallback transparently
   - Tool receives result without needing to know about fallback
   - Async operations maintain model context

## Error Handling Strategy

### Retryable Errors (Trigger Fallback)
- **Quota Issues**: "quota exceeded", "insufficient quota", "resource exhausted"
- **Rate Limits**: "rate limit exceeded", "too many requests"
- **Availability**: "model not available", "unavailable", "not found", "not supported"
- **HTTP Codes**: 403 (Forbidden/Quota), 429 (Rate Limited), 503 (Unavailable)

### Non-Retryable Errors (No Fallback)
- **Validation**: Invalid parameters, bad request (400)
- **Authentication**: Missing/invalid API key, auth failures (401)
- **Other Client Errors**: Malformed requests, unsupported features

## Fallback Flow

```
User Request
    ↓
Try Veo 3.1
    ↓
Success? → Return Result
    ↓
Retryable Error?
    ↓ Yes
Try Veo 2.0
    ↓
Success? → Return Result (with fallback metadata)
    ↓ No
Return Combined Error Message
```

## Model Differences Handled

| Feature | Veo 3.1 | Veo 2.0 |
|---------|---------|---------|
| Model ID | `veo-3.1-generate-preview` | `veo-2.0-generate-001` |
| Min Duration | 4 seconds | 5 seconds |
| Max Duration | 8 seconds | 8 seconds |
| Max Resolution | 1080p (16:9 only) | 720p |
| Aspect Ratios | 16:9, 9:16 | 16:9, 9:16 |
| API Endpoint | Same base URL | Same base URL |

## Logging & Monitoring

### New Log Events
- `veo_fallback_to_veo_2`: When fallback is triggered
- `veo_2_resolution_downgrade`: When 1080p is downgraded to 720p

### Existing Events Enhanced
- `veo_generation_request`: Now includes model information
- `veo_async_queued`: Includes model in metadata
- `veo_async_completed`: Result includes model used

## Backward Compatibility

✅ **Fully Backward Compatible**
- Existing calls without `model` parameter work unchanged
- Default behavior is Veo 3.1 (with fallback)
- API response structure unchanged
- No breaking changes to method signatures (optional parameters only)

## Future Enhancements

Potential future improvements:
1. User preference for default model
2. Cost-based model selection (Veo 2.0 may be cheaper)
3. Retry with exponential backoff for transient errors
4. Circuit breaker pattern for persistent failures
5. Model availability caching to avoid unnecessary retries

## Testing Recommendations

### Manual Testing
1. Test Veo 3.1 normal operation
2. Test explicit Veo 2.0 selection (`model: 'veo-2.0'`)
3. Test 1080p request (should use Veo 3.1 or downgrade)
4. Test duration edge cases (4s vs 5s minimum)
5. Test async operations with both models

### Load Testing
1. Trigger quota limits to verify fallback
2. Test concurrent requests
3. Verify async polling with model tracking

### Integration Testing  
1. Test with actual Gemini API (requires key)
2. Verify error detection patterns match real API responses
3. Test video quality differences between models

## Security Considerations

✅ **Security Maintained**
- No new security vulnerabilities introduced
- Model parameter validated via enum
- No changes to authentication/authorization
- Error messages don't leak sensitive info
- Logging follows existing patterns

## Performance Impact

**Minimal Impact**
- Fallback only triggered on errors (not normal path)
- Single additional method call overhead
- No database queries added
- Async operations handle model tracking efficiently
