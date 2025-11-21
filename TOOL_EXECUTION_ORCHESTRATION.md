# Tool Execution Orchestration Layer

## Overview

The Tool Execution Orchestrator is a service layer that intelligently routes tool execution between synchronous and asynchronous modes to prevent PHP timeouts and improve system stability.

## Problem Solved

Before the orchestration layer, long-running tools (video generation, image generation, web scraping, etc.) would execute synchronously even when they had async capabilities. This caused:

- **PHP Timeouts**: Tools taking 60+ seconds would exceed PHP execution limits
- **Poor User Experience**: Users saw "Tool timed out" errors even though the operation was still running
- **Resource Exhaustion**: Multiple concurrent long-running operations could exhaust server resources

## How It Works

### 1. Automatic Detection

The orchestrator inspects each tool's capability flags before execution:

```php
// Tools declare their characteristics via capability flags
public function get_capability_flags() {
    return array(
        'async',           // Can be executed asynchronously
        'long-running',    // Takes significant time (60+ seconds)
        'may-timeout',     // May exceed typical HTTP timeouts
    );
}
```

### 2. Intelligent Routing

When a tool is executed:

1. **Check Settings**: Is auto-async enabled in Orchestration settings?
2. **Check Context**: Does the context force sync or async mode?
3. **Check Flags**: Does the tool have async capability flags?
4. **Route Accordingly**:
   - **Async Route**: Queue via WordPress cron, return job_id immediately
   - **Sync Route**: Execute directly, return result

### 3. Background Execution

For async tools:

```php
// User calls: generate_veo_video
$result = $orchestrator->execute_tool('generate_veo_video', $args, $context);

// Immediate response (no waiting):
// {
//   "async": true,
//   "job_id": "async_generate_veo_video_12345",
//   "status": "pending",
//   "message": "Video generation started in background..."
// }

// Tool executes in background via cron
// User polls for status using job_id
// Result becomes available when complete
```

## Architecture

```
┌─────────────────┐
│  Chat Service   │
└────────┬────────┘
         │
         v
┌─────────────────────────┐
│ Tool Execution          │
│ Orchestrator            │
│                         │
│ - Check settings        │
│ - Check capability flags│
│ - Route sync/async      │
└────┬───────────────┬────┘
     │               │
     v               v
┌────────┐    ┌──────────────┐
│  Tool  │    │ Async        │
│Registry│    │ Executor     │
│(Sync)  │    │(Background)  │
└────────┘    └──────────────┘
```

## Configuration

### Settings Location

**WordPress Admin → Settings → WP oOS → Orchestration**

### Available Settings

#### 1. Enable Automatic Async Tool Execution (Default: ON)

When enabled, all tools with async capability flags are automatically queued in background.

```php
'enable_auto_async_execution' => true
```

**Benefits**:
- ✅ Prevents PHP timeouts
- ✅ Improves responsiveness
- ✅ Allows concurrent operations
- ✅ Better resource management

**When to Disable**:
- Debugging tool execution flow
- Testing sync behavior
- Site with specific execution requirements

#### 2. Enable Cron-Based Task Orchestration (Default: ON)

Required for async execution to work. Allows AI agents to create and manage scheduled background tasks.

```php
'enable_cron_orchestration' => true
```

## Tools Affected

The orchestration layer automatically applies to **15+ built-in tools**:

### Video Tools
- `generate_veo_video` - Video generation (60-120s typical)
- `analyze_video` - Video analysis
- `extract_video_frames` - Frame extraction
- `generate_video_caption` - Caption generation

### Image Tools
- `generate_gemini_image` - Gemini image generation
- `generate_openai_image` - OpenAI image generation (DALL-E)
- `edit_gemini_image` - Image editing

### Site Management Tools
- `install_and_activate_plugin` - Plugin installation
- `install_and_activate_theme` - Theme installation
- `site_creator` - Complete site creation
- `create_assistant` - Assistant creation

### Other Tools
- `run_crawl4ai_job` - Web crawling and scraping
- `web_search` - Web search operations
- Any custom tools with async flags

## Developer Guide

### Adding Async Support to Tools

To make your tool work with the orchestrator, add capability flags:

```php
class My_Custom_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
    
    public function get_capability_flags() {
        return array(
            'async',           // Can run asynchronously
            'long-running',    // Takes > 30 seconds
            'may-timeout',     // May exceed HTTP timeout
        );
    }
    
    // Tool implementation...
}
```

That's it! The orchestrator will automatically detect and route your tool appropriately.

### Forcing Execution Mode

You can override orchestration behavior via context:

```php
// Force async execution (even if setting is disabled)
$result = $orchestrator->execute_tool(
    'my_tool',
    $arguments,
    array( 'force_async' => true )
);

// Force sync execution (even for async-capable tools)
$result = $orchestrator->execute_tool(
    'my_tool',
    $arguments,
    array( 'force_sync' => true )
);
```

### Checking Tool Status

For async executions, use the `check_video_status` or generic async status checker:

```php
// Get job status
$status = $service->get_async_status( $job_id );

// Status structure:
// {
//   "job_id": "async_generate_veo_video_12345",
//   "status": "pending|running|completed|failed",
//   "poll_attempt": 3,
//   "max_attempts": 60,
//   "result": { ... }  // Available when completed
// }
```

## Race Condition Fix

The orchestrator also fixes a race condition in async job scheduling:

### Before (Race Condition)
```php
// Save metadata
set_transient( 'job_' . $job_id, $metadata );

// Schedule cron immediately
wp_schedule_single_event( time(), 'process_job', array( $job_id ) );
// ^ Cron may execute before transient is committed to DB!
```

### After (Fixed)
```php
// Save metadata
set_transient( 'job_' . $job_id, $metadata );

// Schedule cron with 1-second delay
wp_schedule_single_event( time() + 1, 'process_job', array( $job_id ) );
// ^ Ensures transient is committed before cron reads it
```

## Separation of Concerns

The orchestrator maintains clean separation:

| Layer | Responsibility | Does NOT Know About |
|-------|---------------|---------------------|
| **Settings** | Store configuration | How orchestrator works |
| **Orchestrator** | Route sync/async | Tool implementation details |
| **Async Executor** | Background execution | Which tools use it |
| **Tools** | Declare capabilities | Orchestration logic |
| **Tool Registry** | Tool lookup | Execution mode |

## Performance Benefits

### Before Orchestration
- Video generation: 60-120s blocking request
- Multiple concurrent requests: Server overload
- Frequent timeouts: Poor UX

### After Orchestration
- Video generation: <1s response (returns job_id)
- Multiple concurrent requests: Queued efficiently
- No timeouts: Jobs complete in background

## Monitoring

### Admin Dashboard

Check background job status in:
**WordPress Admin → Tools → Cron Manager**

Shows:
- ✅ Pending jobs
- ✅ Running jobs  
- ✅ Completed jobs
- ✅ Failed jobs with error messages

### Debug Logging

Enable logging in:
**WordPress Admin → Settings → WP oOS → Enable Logging**

Logs include:
- Orchestration routing decisions
- Tool execution start/complete
- Error details for failed jobs

## Troubleshooting

### Issue: Tools Still Timing Out

**Solution**: Check settings
1. Go to **Settings → WP oOS → Orchestration**
2. Verify **Enable Automatic Async Tool Execution** is checked
3. Verify **Enable Cron-Based Task Orchestration** is checked
4. Check **Tools → Cron Manager** to see if jobs are queued

### Issue: Jobs Never Complete

**Solution**: Check WordPress cron
```bash
# Check if WP-Cron is working
wp cron event list

# Run cron manually
wp cron event run --due-now
```

If using external cron, ensure it's hitting `wp-cron.php` regularly.

### Issue: Job Not Found Error

**Solution**: This indicates the race condition fix didn't apply
- Update to latest version
- Check that video generation service has the 1-second delay
- Check transient storage is working (not object cache issue)

## Best Practices

### For Plugin Developers

1. **Always Declare Flags**: Mark long-running tools with capability flags
2. **Test Both Modes**: Test your tool in sync and async modes
3. **Handle Errors**: Return WP_Error for failures
4. **Log Important Events**: Use WP_MCP_AI_Logger for debugging

### For Site Administrators

1. **Keep Auto-Async Enabled**: Unless you have specific requirements
2. **Monitor Cron Manager**: Regularly check for stuck jobs
3. **Enable Logging**: During initial testing and troubleshooting
4. **Set Retention Period**: Adjust based on your audit requirements

### For End Users

1. **Be Patient**: Long-running tasks now complete in background
2. **Check Status**: Use check_video_status or similar tools to poll
3. **Use Job IDs**: Save job_id for later status checks

## Future Enhancements

Potential improvements:

- [ ] Priority queue for high-priority operations
- [ ] Automatic retry with exponential backoff
- [ ] Real-time progress updates via WebSockets
- [ ] Resource-based throttling (CPU/memory aware)
- [ ] Multi-server distributed execution

## Conclusion

The Tool Execution Orchestrator is a foundational enhancement that:
- ✅ Prevents timeouts for 15+ long-running tools
- ✅ Maintains clean separation of concerns
- ✅ Requires zero changes to existing tools with flags
- ✅ Provides user control via settings
- ✅ Fixes race conditions in async execution
- ✅ Improves overall system stability

All while maintaining backward compatibility and following WordPress best practices.
