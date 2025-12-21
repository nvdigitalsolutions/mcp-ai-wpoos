# Veo 2.0 Fallback Guide

## Overview

The WP oOS plugin now includes automatic fallback support for video generation. When using the Veo video generation tool, the system will automatically try Veo 2.0 if Veo 3.1 is unavailable or quota limits are reached.

## How It Works

### Automatic Fallback

By default, the plugin will:

1. **Try Veo 3.1 first** - The preferred model with higher quality (up to 1080p)
2. **Detect failures** - Automatically detects quota limits, rate limits, or availability issues
3. **Fall back to Veo 2.0** - Seamlessly switches to Veo 2.0 without user intervention
4. **Return results** - Delivers the generated video with metadata about which model was used

### When Fallback Occurs

The system automatically falls back to Veo 2.0 when it encounters:

- **Quota exceeded** - Your Veo 3.1 quota has been exhausted
- **Rate limits** - Too many requests to Veo 3.1 in a short time
- **Model unavailable** - Veo 3.1 is temporarily unavailable
- **HTTP errors** - 403 (Forbidden), 429 (Too Many Requests), or 503 (Service Unavailable) responses

### When Fallback Does NOT Occur

Fallback is NOT triggered for:

- Invalid parameters (bad prompts, invalid settings)
- Authentication errors (missing or invalid API key)
- Other client-side errors (400 Bad Request)

These errors indicate issues that need to be fixed by the user, not solved by trying a different model.

## Model Differences

| Feature | Veo 3.1 (Preferred) | Veo 2.0 (Fallback) |
|---------|-------------------|-------------------|
| **Max Resolution** | 1080p (16:9 only) | 720p |
| **Min Duration** | 4 seconds | 5 seconds |
| **Max Duration** | 8 seconds | 8 seconds |
| **Aspect Ratios** | 16:9, 9:16 | 16:9, 9:16 |
| **Quality** | Higher quality | Good quality |

### Automatic Adjustments

When falling back to Veo 2.0, the system automatically:

- **Downgrades 1080p to 720p** - Veo 2.0 doesn't support 1080p
- **Adjusts duration if needed** - Ensures minimum 5 seconds for Veo 2.0
- **Logs the downgrade** - Records the change in system logs

## Usage Examples

### Basic Usage (Automatic Fallback)

```php
// Will try Veo 3.1, fall back to Veo 2.0 if needed
$result = $tool->execute(
    array(
        'prompt'       => 'A beautiful sunset over the ocean',
        'duration'     => 6,
        'aspect_ratio' => '16:9',
        'resolution'   => '720p',
    ),
    array( 'user_id' => get_current_user_id() )
);

// Check which model was used
if ( ! is_wp_error( $result ) ) {
    echo 'Video generated with: ' . $result['model'];
}
```

### Explicit Model Selection

If you want to force a specific model:

```php
// Force Veo 3.1 only (no fallback)
$result = $tool->execute(
    array(
        'prompt'   => 'A beautiful sunset',
        'model'    => 'veo-3.1',
        'duration' => 4, // 4 seconds OK for Veo 3.1
    ),
    $context
);

// Force Veo 2.0 directly
$result = $tool->execute(
    array(
        'prompt'   => 'A beautiful sunset',
        'model'    => 'veo-2.0',
        'duration' => 5, // 5 seconds minimum for Veo 2.0
    ),
    $context
);
```

### Checking Fallback in Results

```php
$result = $tool->execute( $args, $context );

if ( ! is_wp_error( $result ) ) {
    // Check if fallback was used
    if ( isset( $result['fallback_used'] ) && $result['fallback_used'] ) {
        echo 'Veo 3.1 failed: ' . $result['primary_model_error'];
        echo 'Successfully used Veo 2.0 as fallback';
    }
    
    // Always check which model was actually used
    echo 'Model used: ' . $result['model'];
}
```

## Best Practices

### 1. Use Default Behavior

In most cases, let the automatic fallback work:

```php
// ✅ Good: Let the system handle fallback
$result = $tool->execute(
    array(
        'prompt'     => 'My video description',
        'resolution' => '720p', // Works with both models
        'duration'   => 5,      // Works with both models
    ),
    $context
);
```

### 2. Design for 720p When Possible

If you don't specifically need 1080p, design for 720p to ensure compatibility with both models:

```php
// ✅ Good: Compatible with both models
$args = array(
    'prompt'       => 'Test video',
    'resolution'   => '720p',
    'duration'     => 6,
    'aspect_ratio' => '16:9',
);
```

### 3. Handle Both Success Cases

```php
$result = $tool->execute( $args, $context );

if ( is_wp_error( $result ) ) {
    // Both models failed
    error_log( 'Video generation failed: ' . $result->get_error_message() );
} else {
    // Success (either model)
    $model_used = $result['model'];
    $video_url  = $result['url'];
    
    // Log for monitoring
    if ( 'veo-2.0-generate-001' === $model_used ) {
        error_log( 'Used Veo 2.0 fallback' );
    }
}
```

### 4. Monitor Fallback Usage

Track how often fallback is being used to understand your quota usage:

```php
add_action( 'wp_mcp_ai_log_event', function( $event_type, $message, $data ) {
    if ( 'veo_fallback_to_veo_2' === $event_type ) {
        // Log or alert about fallback usage
        error_log( 'Veo 2.0 fallback triggered: ' . wp_json_encode( $data ) );
    }
}, 10, 3 );
```

## Troubleshooting

### Both Models Fail

If both Veo 3.1 and Veo 2.0 fail, you'll receive an error message showing both failures:

```
Video generation failed. 
Veo 3.1: Quota exceeded for this model. 
Veo 2.0 fallback also failed: Quota exceeded for this model.
```

**Solutions:**
- Wait for quota to reset (usually daily)
- Upgrade your Google Cloud quota
- Check your Gemini API key configuration

### Resolution Downgraded

If you request 1080p but get 720p:

1. **Check the model used**: If `veo-2.0-generate-001`, fallback occurred
2. **Check logs**: Look for `veo_2_resolution_downgrade` events
3. **Solutions**:
   - Request 720p directly to avoid unexpected downgrades
   - Ensure sufficient Veo 3.1 quota
   - Use `model: 'veo-3.1'` to force Veo 3.1 (will fail if unavailable)

### Duration Adjusted

If your 4-second video becomes 5 seconds:

- Veo 2.0 requires minimum 5 seconds
- The system automatically adjusted the duration
- **Solution**: Request 5+ seconds to be compatible with both models

## Monitoring & Debugging

### Enable Logging

In WordPress admin: **Settings → WP oOS → Enable Logging**

Or via constant:
```php
define( 'WP_MCP_AI_DEBUG', true );
```

### Key Log Events

- `veo_fallback_to_veo_2` - Fallback was triggered
- `veo_2_resolution_downgrade` - Resolution was downgraded
- `veo_generation_request` - Video generation started (includes model)
- `veo_generation_complete` - Video generation succeeded

### Checking Logs

```php
// Get recent errors
$errors = get_option( 'wp_mcp_ai_recent_errors', array() );

// Get recent activity
$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

// Filter for Veo events
$veo_events = array_filter( $activity, function( $event ) {
    return false !== stripos( $event['type'], 'veo' );
} );
```

## API Reference

### Tool Parameter: `model`

**Type:** `string`  
**Optional:** Yes  
**Values:** `'veo-3.1'`, `'veo-2.0'`  
**Default:** Automatic (tries Veo 3.1, falls back to Veo 2.0)

**Examples:**

```php
// Automatic (recommended)
array( 'prompt' => 'Video description' )

// Force Veo 3.1
array( 'prompt' => 'Video description', 'model' => 'veo-3.1' )

// Force Veo 2.0
array( 'prompt' => 'Video description', 'model' => 'veo-2.0' )
```

### Result Metadata

Successful results include:

```php
array(
    'success'       => true,
    'attachment_id' => 123,              // WordPress media ID
    'url'           => 'https://...',    // Video URL
    'model'         => 'veo-3.1-generate-preview', // Model used
    'provider'      => 'gemini',
    'duration'      => 6,
    'aspect_ratio'  => '16:9',
    'resolution'    => '720p',
    
    // Only if fallback was used
    'fallback_used'        => true,
    'primary_model_error'  => 'Quota exceeded',
)
```

## Performance Considerations

### Fallback Impact

- **Negligible overhead** - Fallback only occurs on errors
- **Single retry** - Only one fallback attempt per request
- **No database queries** - All in-memory operations
- **Async compatible** - Works with async video generation

### Quota Management

- Monitor `veo_fallback_to_veo_2` events to track quota usage
- Consider requesting Veo 2.0 directly during high-demand periods
- Implement rate limiting in your application if needed

## Future Enhancements

Planned improvements:

- User preference for default model
- Cost-based automatic selection
- Retry with exponential backoff
- Circuit breaker for persistent failures
- Model availability caching

## Support

For issues or questions:

1. Check system logs for detailed error messages
2. Review the [main documentation](../../../)
3. Open an issue on GitHub
4. Contact support with log details
