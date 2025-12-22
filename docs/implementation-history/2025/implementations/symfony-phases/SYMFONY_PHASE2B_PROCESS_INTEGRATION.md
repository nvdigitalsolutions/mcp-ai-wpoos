# Symfony Phase 2B: Process Integration

**Date:** December 9, 2025  
**Status:** ✅ **COMPLETE** (See [SYMFONY_PHASE2B_COMPLETION_SUMMARY.md](SYMFONY_PHASE2B_COMPLETION_SUMMARY.md))  
**Purpose:** Replace direct `exec()` calls with Symfony Process component in Pro addon tools

---

## Overview

Phase 2B focuses on migrating 6 Pro addon tools and 2 supporting services from direct `exec()` calls to the Symfony Process component. This provides better process management, timeout handling, error handling, and security.

## Scope

### Tools to Migrate (6)
1. **check_jukebox_status** - Meta AI Jukebox status checking
2. **check_wp_cli** - WP-CLI availability and execution
3. **extract_video_frames** - FFmpeg frame extraction
4. **generate_jukebox_music** - Meta AI Jukebox music generation
5. **get_video_metadata** - FFmpeg metadata extraction
6. **remove_background** - Python rembg background removal

### Services to Migrate (2)
1. **WP_MCP_AI_Jukebox_Service** - 3 exec calls (Python/conda, jukebox execution)
2. **WP_MCP_AI_Video_Frame_Extractor_Service** - 4 exec calls (FFmpeg operations)

### Total exec() Calls to Replace
- Tools: 10 exec calls
- Services: 4 exec calls (shared by tools)
- **Total: 14 exec calls**

---

## Phase 2B Architecture

### Process Service Wrapper

**File:** `includes/services/class-wp-mcp-ai-process-service.php`

The Process Service provides a WordPress-friendly wrapper around Symfony Process with the following features:

#### Key Methods

1. **`run( $command, $options )`**
   - Executes command and throws WP_Error on failure
   - Returns array with output, error, exit_code, success
   - Use for operations that must succeed

2. **`run_silent( $command, $options )`**
   - Executes command without exceptions
   - Returns result array even on failure
   - Use for checking command availability or optional operations

3. **`is_command_available( $command )`**
   - Checks if a command exists on the system
   - Returns boolean
   - Example: `is_command_available('ffmpeg')`

4. **`get_command_path( $command )`**
   - Gets full path to command
   - Returns string path or false
   - Example: `/usr/bin/ffmpeg`

5. **`run_with_callback( $command, $callback, $options )`**
   - Streams output to callback in real-time
   - Useful for long-running processes with progress updates

#### Options

```php
$options = array(
    'timeout' => 60,              // Timeout in seconds (default: 60)
    'cwd'     => '/path/to/dir',  // Working directory (default: null)
    'env'     => array(           // Environment variables (default: null)
        'PATH' => '/custom/path'
    ),
    'input'   => 'stdin data',    // Input to send to process (default: null)
);
```

---

## Migration Pattern

### Before (Direct exec)

```php
// Old pattern - using exec() directly
$command = sprintf(
    'ffmpeg -i %s -vframes 1 -f image2 %s 2>&1',
    escapeshellarg( $video_path ),
    escapeshellarg( $output_path )
);

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
exec( $command, $output, $return_code );

if ( 0 !== $return_code ) {
    return new WP_Error(
        'ffmpeg_failed',
        'FFmpeg extraction failed',
        array( 'output' => implode( "\n", $output ) )
    );
}
```

### After (Symfony Process)

```php
// New pattern - using Process Service
$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

$result = $process_service->run(
    array(
        'ffmpeg',
        '-i', $video_path,
        '-vframes', '1',
        '-f', 'image2',
        $output_path
    ),
    array( 'timeout' => 120 )
);

if ( is_wp_error( $result ) ) {
    return $result; // Automatically formatted WP_Error with details
}

// Success - process output
$output = $result['output'];
```

---

## Benefits

### 1. Security
- **Proper Escaping**: Symfony Process handles all argument escaping
- **No Shell Injection**: Command arrays bypass shell interpretation
- **Process Isolation**: Better process sandboxing

### 2. Error Handling
- **Structured Errors**: Consistent WP_Error format
- **Exit Code Tracking**: Proper exit code capture and handling
- **Output Capture**: Both stdout and stderr captured separately

### 3. Timeout Management
- **Automatic Timeout**: Built-in timeout with configurable duration
- **Timeout Exceptions**: Clear timeout errors with partial output
- **No Hanging Processes**: Processes terminated on timeout

### 4. Real-time Output
- **Streaming Support**: Callback-based output streaming
- **Progress Updates**: Real-time progress for long operations
- **Async Support**: Future support for background processes

### 5. Testability
- **Mockable**: Process service can be mocked in tests
- **Deterministic**: Consistent behavior across environments
- **Test Isolation**: No side effects from process execution

---

## Implementation Status

### Completed
- [x] Install Symfony Process component (v6.4.26)
- [x] Create Process Service wrapper
  - [x] Singleton pattern
  - [x] run() method with exception handling
  - [x] run_silent() method without exceptions
  - [x] is_command_available() helper
  - [x] get_command_path() helper
  - [x] run_with_callback() for streaming
  - [x] Configurable default timeout
  - [x] WP_Error integration
- [x] Create comprehensive test suite (16 test methods)

### In Progress
- [ ] Register service in plugin initialization
- [ ] Migrate Video Frame Extractor Service
- [ ] Migrate Jukebox Service
- [ ] Migrate individual tools

### Pending
- [ ] Performance benchmarking
- [ ] Production testing with Pro addon
- [ ] Documentation updates
- [ ] Code review and feedback

---

## Testing

### Test Coverage

**File:** `tests/test-process-service.php`

#### Test Methods (16)
1. `test_process_service_is_singleton` - Singleton pattern
2. `test_run_basic_command` - Basic command execution
3. `test_run_shell_command` - Shell command execution
4. `test_run_silent_no_exception_on_failure` - Silent mode
5. `test_timeout_handling` - Timeout management
6. `test_is_command_available_existing` - Command availability check
7. `test_is_command_available_nonexisting` - Non-existing command
8. `test_get_command_path_existing` - Command path lookup
9. `test_get_command_path_nonexisting` - Path for non-existing command
10. `test_set_default_timeout` - Custom timeout setting
11. `test_error_output_capture` - Error output (stderr) capture
12. `test_run_with_callback` - Callback-based execution
13. `test_run_with_custom_cwd` - Custom working directory
14. `test_run_failed_command_returns_wp_error` - Error handling

### Running Tests

```bash
# Run Process Service tests
vendor/bin/phpunit tests/test-process-service.php

# Run all tests
composer test
```

---

## Usage Examples

### Example 1: Check if FFmpeg is Available

```php
$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

if ( ! $process_service->is_command_available( 'ffmpeg' ) ) {
    return new WP_Error(
        'ffmpeg_not_found',
        __( 'FFmpeg is not installed on this system.', 'mcp-ai-wpoos' )
    );
}

$ffmpeg_path = $process_service->get_command_path( 'ffmpeg' );
// Returns: /usr/bin/ffmpeg
```

### Example 2: Extract Video Frame with Timeout

```php
$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

$result = $process_service->run(
    array(
        'ffmpeg',
        '-i', $video_path,
        '-ss', '00:00:05',
        '-vframes', '1',
        $output_path
    ),
    array(
        'timeout' => 120,  // 2 minutes max
    )
);

if ( is_wp_error( $result ) ) {
    // Handle timeout or failure
    return $result;
}

// Success
$frame_path = $output_path;
```

### Example 3: Run Python Script with Real-time Output

```php
$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

$callback = function( $type, $buffer ) {
    // Type: 'out' (stdout) or 'err' (stderr)
    error_log( "Process output: {$buffer}" );
};

$result = $process_service->run_with_callback(
    array( 'python3', 'script.py', '--input', $file_path ),
    $callback,
    array( 'timeout' => 300 )  // 5 minutes
);
```

### Example 4: Check Command Version (Silent Mode)

```php
$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

// Use run_silent to avoid exceptions
$result = $process_service->run_silent(
    array( 'python3', '--version' ),
    array( 'timeout' => 5 )
);

if ( $result['success'] ) {
    $version = trim( $result['output'] );
    // "Python 3.11.5"
} else {
    // Python not available or failed
}
```

---

## Next Steps

### Immediate (Week 1)
1. Register Process Service in plugin initialization
2. Migrate `WP_MCP_AI_Video_Frame_Extractor_Service`
3. Migrate `WP_MCP_AI_Jukebox_Service`
4. Update 2 tools that depend on migrated services

### Short-term (Week 2)
5. Migrate remaining 4 Pro tools
6. Run comprehensive test suite
7. Performance benchmarking
8. Code review

### Medium-term (Week 3-4)
9. Production testing with Pro addon
10. Gather user feedback
11. Document best practices
12. Update tool documentation

---

## References

- **Symfony Process Documentation**: https://symfony.com/doc/current/components/process.html
- **Phase 2 Plan**: `docs/SYMFONY_PHASE2_IMPLEMENTATION_PLAN.md`
- **Session Summary**: `docs/SYMFONY_SESSION_2025-12-09.md`
- **Integration Guide**: `docs/SYMFONY_INTEGRATION_GUIDE.md`

---

**Last Updated:** December 9, 2025  
**Status:** Phase 2B In Progress  
**Completion:** 30% (Infrastructure complete, migrations pending)
