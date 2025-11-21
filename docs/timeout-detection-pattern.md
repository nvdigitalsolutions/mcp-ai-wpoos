# Timeout Detection and Async Fallback Pattern

## Overview

This document describes the timeout detection and async fallback pattern that long-running tools should implement to prevent PHP timeouts and provide graceful degradation.

## When to Use

Implement this pattern if your tool has any of these capability flags:
- `may-timeout` - Tool operations may exceed typical PHP timeouts
- `long-running` - Tool performs operations that take significant time (30+ seconds)
- `async` - Tool supports asynchronous execution

Common use cases:
- Video/audio generation (60-120+ seconds)
- Large file processing
- Multiple external API calls with polling
- Batch operations on many items
- Complex AI model inference

## How It Works

1. **Start Tracking**: Create timeout detector at the beginning of long operation
2. **Monitor During Execution**: Check detector in processing loops
3. **Fallback on Threshold**: When approaching timeout (default: 10 seconds before max_execution_time), queue the operation for async completion
4. **Return Job ID**: Return async job info to client for status checking

```
Start Operation → Track Time → Approaching Timeout? → Queue Async → Return job_id
                      ↓              ↓ No
                 Continue Loop → Complete → Return Result
```

## Implementation Guide

### Step 1: Add Capability Flags

Ensure your tool declares the appropriate capability flags:

```php
public function get_capability_flags() {
    return array(
        'may-timeout',     // Tool may exceed PHP timeout
        'long-running',    // Operation takes 30+ seconds
        'async',           // Supports async execution
        // ... other flags
    );
}
```

### Step 2: Initialize Timeout Detector

At the start of your long-running operation (e.g., in your service class):

```php
// Load timeout detection service.
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

// Create detector with 10 second safety buffer (default).
$timeout_detector = new WP_MCP_AI_Timeout_Detection_Service( 10 );

// Or conditionally create based on tool flags:
$detector = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( 
    $tool->get_capability_flags() 
);
```

### Step 3: Check in Processing Loop

In your polling or processing loop, check if approaching timeout:

```php
while ( $attempts < $max_attempts ) {
    ++$attempts;
    
    // Check timeout BEFORE doing expensive work.
    if ( $timeout_detector && $timeout_detector->is_approaching_timeout() ) {
        // Fall back to async mode.
        WP_MCP_AI_Logger::log_event(
            'timeout_async_fallback',
            sprintf( 'Approaching timeout after %.2fs, falling back to async', 
                $timeout_detector->get_elapsed_time() 
            ),
            $timeout_detector->get_metadata()
        );
        
        return $this->queue_for_async_completion( $operation_data, $args );
    }
    
    // Continue processing...
    $result = $this->do_expensive_work();
    
    if ( $result->is_complete() ) {
        return $result;
    }
    
    sleep( 5 ); // Wait before next attempt.
}
```

### Step 4: Implement Async Queueing

Your service should have a method to queue operations for async completion:

```php
protected function queue_for_async_completion( $operation_data, $args ) {
    // Generate unique job ID.
    $job_id = 'operation_' . uniqid( '', true );
    
    // Store operation metadata in transient.
    $metadata = array(
        'job_id'         => $job_id,
        'operation_data' => $operation_data,
        'args'           => $args,
        'status'         => 'pending',
        'queued_at'      => time(),
    );
    
    set_transient( 'wp_mcp_ai_async_' . $job_id, $metadata, DAY_IN_SECONDS );
    
    // Schedule cron job to continue processing.
    wp_schedule_single_event( 
        time() + 1, 
        'wp_mcp_ai_continue_operation', 
        array( $job_id ) 
    );
    
    // Return async response.
    return array(
        'async'   => true,
        'job_id'  => $job_id,
        'status'  => 'pending',
        'message' => __( 'Operation queued for background processing.', 'wp-mcp-ai' ),
    );
}
```

### Step 5: Handle Tool Response

Your tool's execute method should detect and pass through async responses:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... validation ...
    
    $result = $this->service->perform_operation( $arguments );
    
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    
    // Check if result is async fallback.
    if ( isset( $result['async'] ) && $result['async'] ) {
        return $result; // Pass through to client.
    }
    
    // Normal synchronous result processing.
    return $this->format_result( $result );
}
```

## Complete Example: Veo Video Generation

Here's how the Veo video generation service implements this pattern:

```php
protected function poll_for_completion( $operation, $args = array() ) {
    $operation_name = $operation['operation_name'];
    $attempts = 0;
    
    // Initialize timeout detector (10 seconds before PHP timeout).
    require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';
    $timeout_detector = new WP_MCP_AI_Timeout_Detection_Service( 10 );
    
    while ( $attempts < self::MAX_POLLING_ATTEMPTS ) {
        ++$attempts;
        
        if ( $attempts > 1 ) {
            sleep( self::POLLING_INTERVAL );
        }
        
        // Check timeout BEFORE polling.
        if ( $timeout_detector->is_approaching_timeout() ) {
            WP_MCP_AI_Logger::log_event(
                'veo_timeout_async_fallback',
                sprintf( 'Approaching timeout after %.2fs', 
                    $timeout_detector->get_elapsed_time() 
                ),
                $timeout_detector->get_metadata()
            );
            
            // Queue for async polling and return job info.
            return $this->queue_async_polling( $operation, $args );
        }
        
        // Poll for completion.
        $response = wp_remote_get( $endpoint, $request_args );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset( $data['done'] ) && $data['done'] ) {
            return $data; // Completed successfully.
        }
    }
    
    // Max attempts reached - also fall back to async.
    return $this->queue_async_polling( $operation, $args );
}
```

## Configuration

### Safety Buffer

The default safety buffer is 10 seconds before `max_execution_time`. Adjust based on your operation's needs:

```php
// Conservative: 15 seconds before timeout.
$detector = new WP_MCP_AI_Timeout_Detection_Service( 15 );

// Aggressive: 5 seconds before timeout.
$detector = new WP_MCP_AI_Timeout_Detection_Service( 5 );
```

### PHP max_execution_time

The detector automatically reads `ini_get('max_execution_time')`:
- Default web requests: 30 seconds
- WP-CLI: Often 0 (unlimited), detector defaults to 30
- Can be overridden via `ini_set()` or php.ini

## Testing

Test your timeout detection implementation:

```php
public function test_timeout_detection() {
    // Set short timeout for testing.
    ini_set( 'max_execution_time', '2' );
    
    $detector = new WP_MCP_AI_Timeout_Detection_Service( 1 );
    
    // Simulate long operation.
    $result = $this->service->perform_operation( $args );
    
    // Should fall back to async.
    $this->assertTrue( $result['async'] );
    $this->assertArrayHasKey( 'job_id', $result );
}
```

## Best Practices

1. **Check Early**: Check timeout BEFORE expensive operations, not after
2. **Log Fallbacks**: Always log when falling back to async for debugging
3. **Provide Context**: Include detector metadata in logs
4. **Test Timeouts**: Test with short max_execution_time values
5. **Document Behavior**: Note in tool description that async fallback may occur
6. **Handle Both Modes**: Tool should handle both sync and async results
7. **Clean Up**: Use transients with expiration for async job data

## Orchestration Integration

This pattern works alongside the orchestration layer:

- **Orchestrator**: Routes long-running tools to async executor immediately
- **Timeout Detection**: Provides additional safety net if sync execution occurs
- **Double Protection**: Tools with both orchestrator async AND timeout detection are most reliable

When orchestrator routes to async:
- Tool receives `in_async_executor=true` in context
- Tool should NOT create its own async queue (prevent double-async)
- Timeout detection still protects against long polling loops

## Tools That Should Implement This Pattern

Current tools with `may-timeout` or `long-running` flags:
- ✅ `generate_veo_video` - Implemented
- 🔄 `generate_video_caption` - Should implement
- 🔄 `analyze_video` - Should implement
- 🔄 `extract_video_frames` - Should implement
- 🔄 Other video/image generation tools

## Related Documentation

- `docs/orchestration-layer.md` - Orchestration async routing
- `docs/tool-capability-flags.md` - Complete flag reference
- `docs/async-execution.md` - Async tool execution guide
- `tests/test-timeout-detection-service.php` - Test examples
