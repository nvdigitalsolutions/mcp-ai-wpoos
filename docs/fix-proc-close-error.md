# Fix for proc_close() Undefined Function Error

## Problem

After the recent PR that introduced the Symfony Process Service, the plugin was experiencing a fatal error on production servers:

```
Fatal error: Uncaught Error: Call to undefined function Symfony\Component\Process\proc_close() 
in /vendor/symfony/process/Process.php:1389
```

This error occurred because PHP's process control functions (`proc_open`, `proc_close`, `proc_terminate`) are disabled on some hosting environments for security reasons via the `disable_functions` directive in `php.ini`.

## Root Cause

The Symfony Process Service was being invoked during plugin initialization to check for Node.js availability:

```php
// addons/pro/includes/npm-integration-filters.php:27
function wp_mcp_ai_is_nodejs_available() {
    $process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
    $available = $process_service->is_command_available('node');
    return $available;
}
```

When `is_command_available()` tried to run a `which` command using the Process Service, it attempted to instantiate a Symfony `Process` object, which internally requires `proc_open()` and `proc_close()`. If these functions are disabled, a fatal error occurs.

## Solution

Added proactive checks for process function availability before attempting to use the Symfony Process library:

### 1. Detection Method

Added a private method to check if all required process functions are available:

```php
private function is_process_available() {
    return function_exists('proc_open') 
        && function_exists('proc_close') 
        && function_exists('proc_terminate');
}
```

### 2. Guard Clauses

Added guard clauses at the beginning of all process execution methods:

**In `run()` method:**
```php
if (!$this->is_process_available()) {
    return new \WP_Error(
        'process_unavailable',
        __('Process control functions (proc_open, proc_close) are disabled on this server. Please contact your hosting provider to enable these functions.', 'mcp-ai-wpoos')
    );
}
```

**In `run_silent()` method:**
```php
if (!$this->is_process_available()) {
    return array(
        'output'    => '',
        'error'     => 'Process control functions (proc_open, proc_close) are disabled on this server.',
        'exit_code' => -1,
        'success'   => false,
        'disabled'  => true,
    );
}
```

### 3. Helper Method Updates

Updated helper methods to handle the disabled state:

```php
public function is_command_available($command) {
    $result = $this->run_silent(array($check_command, $command), array('timeout' => 5));
    
    // If process functions are disabled, command checking is not available.
    if (isset($result['disabled']) && $result['disabled']) {
        return false;
    }
    
    return $result['success'] && !empty($result['output']);
}
```

## Behavior

### When Process Functions Are Available (Normal Operation)

- All process execution methods work as before
- Commands can be executed
- Command availability can be checked
- Full Symfony Process functionality available

### When Process Functions Are Disabled

- **No fatal errors** - Plugin loads successfully
- `is_command_available()` returns `false` (cannot check)
- `get_command_path()` returns `false` (cannot locate)
- `run()` returns `WP_Error` with helpful message
- `run_silent()` returns error array with `disabled` flag
- Node.js integration features gracefully disabled
- Rest of plugin functionality remains operational

## Impact

### Before Fix
```
✗ Fatal error: proc_close() undefined
✗ Plugin fails to load
✗ WordPress admin becomes inaccessible
✗ Requires manual plugin deactivation
```

### After Fix
```
✓ Plugin loads successfully
✓ Node.js features gracefully disabled
✓ Clear error messages in logs
✓ Rest of plugin works normally
✓ Users can continue using other features
```

## Testing

### Test Coverage
- Added test case for disabled process functions scenario
- Verified graceful degradation behavior
- Confirmed backward compatibility when functions are available

### Manual Verification
To verify the fix works on a server with disabled functions:

1. Check which functions are disabled:
   ```php
   $disabled = ini_get('disable_functions');
   echo $disabled;
   ```

2. Look for `proc_open`, `proc_close`, or `proc_terminate`

3. With the fix in place:
   - Plugin should load without errors
   - Check error logs for "Process control functions...disabled" messages
   - Verify other plugin features work normally

## Recommendations

For hosting providers or server administrators who encounter this issue:

### Option 1: Enable Process Functions (Recommended)

#### On Cloudways Hosting

Cloudways users can enable these functions through the Application Settings page:

1. Log in to your Cloudways platform
2. Go to **Servers** → Select your server
3. Go to **Settings & Packages** → **Application Settings**
4. Scroll to the **PHP FPM** tab
5. Find the `disable_functions` directive
6. Remove `proc_open`, `proc_close`, and `proc_terminate` from the disabled list
7. Save changes and restart PHP-FPM

**Before:**
```ini
disable_functions = proc_open,proc_close,proc_terminate,exec,shell_exec,...
```

**After:**
```ini
disable_functions = exec,shell_exec,...
```

#### On Standard Hosting / VPS

If your hosting environment allows, edit `php.ini` to enable the required functions:

```ini
; Before
disable_functions = proc_open,proc_close,proc_terminate,...

; After (remove these three)
disable_functions = ...
```

After making changes, restart your web server (Apache/Nginx) or PHP-FPM.

### Option 2: Use Plugin Without Process Features
The plugin will work without these functions, but features requiring external command execution (like Node.js integration) will not be available.

## Files Changed

- `includes/services/class-wp-mcp-ai-process-service.php` - Added safety checks
- `tests/test-process-service.php` - Added test for disabled functions scenario

## Related Issues

- Original issue: "Fatal error: Call to undefined function proc_close()"
- Previous PR: #3012 (introduced Process Service)
- This fix: Adds safety checks to prevent fatal errors

## Security Considerations

This fix does not compromise security:
- Still respects server's `disable_functions` configuration
- Does not attempt to bypass or circumvent disabled functions
- Gracefully degrades functionality when restrictions are in place
- Provides clear messaging about what features are unavailable
