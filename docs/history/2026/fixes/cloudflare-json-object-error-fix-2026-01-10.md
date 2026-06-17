# Cloudflare Workers AI "unknown variant json_object" Error Fix

**Date:** January 10, 2026  
**Issue:** Cloudflare Workers AI returns error: `AiError: invalid prompt: failed to parse prompt: unknown variant 'json_object', expected 'json_schema'`  
**Status:** ✅ Fixed

## Problem Description

Users were encountering the following error when using the chat-client with Cloudflare Workers AI as the provider:

```
Cloudflare Workers AI returned an error. AiError: AiError: invalid prompt: failed to parse prompt: unknown variant `json_object`, expected `json_schema` at line 1 column 4103 (f010d415-6222-4c6e-a079-7b17a4ecfc06) (Code: 9015)
```

## Root Cause

The plugin was automatically setting `response_format` to `{"type": "json_object"}` when tools were present in the request. This was done in `build_payload()` method of `WP_MCP_AI_Cloudflare_Client` class (line 462).

However, there were two issues with this approach:

1. **JSON mode is a recent feature**: Support for JSON mode (`json_object` and `json_schema`) was only added to Cloudflare Workers AI on **February 25, 2025**.

2. **Not all models support JSON mode**: Only specific newer models support this feature:
   - `@cf/meta/llama-3.1-8b-instruct-fast`
   - `@cf/meta/llama-3.1-70b-instruct`
   - `@cf/meta/llama-3.3-70b-instruct-fp8-fast`
   - `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b`
   - `@hf/nousresearch/hermes-2-pro-mistral-7b`
   - And other recent models

3. **Older or unsupported models don't recognize the parameter**: When `response_format` with `json_object` is sent to a model that doesn't support it, Cloudflare returns the "unknown variant" error.

## Solution

### Changes Made

1. **Removed automatic JSON mode** - The code no longer automatically adds `response_format` to the payload when tools are present.

2. **Allow explicit setting** - Users can still explicitly set `response_format` if they want to use it (and their model supports it):
   ```php
   $options['response_format'] = array( 'type' => 'json_object' );
   // OR
   $options['response_format'] = array( 
       'type' => 'json_schema',
       'json_schema' => array( /* your schema */ )
   );
   ```

3. **Tool calling works without JSON mode** - Cloudflare's supported models handle tool calling correctly without requiring explicit JSON mode.

### Files Modified

- `includes/class-wp-mcp-ai-cloudflare-client.php` (lines 449-462)
  - Removed auto-JSON logic and `disable_auto_json` option
  - Added explanatory comments about model compatibility
  - Updated docblock for `run_with_tools()` method

- `tests/test-cloudflare-response-format.php`
  - Updated tests to verify response_format is NOT auto-added
  - Added test to verify explicit response_format still works

- `docs/CLOUDFLARE_AI_UTILS.md`
  - Removed `disable_auto_json` option from documentation
  - Added note about model compatibility for `response_format`

## Impact

### Before Fix
- ❌ Error with older Cloudflare models
- ❌ Error with models that don't support JSON mode
- ⚠️ Automatic behavior not obvious to users

### After Fix
- ✅ Works with all Cloudflare models (old and new)
- ✅ No errors on unsupported models
- ✅ Users can still use JSON mode on supported models by explicitly setting it
- ✅ Clearer documentation about model requirements

## Backward Compatibility

This is a **non-breaking change** for most users:

- **If you were using tool calling**: It will continue to work, just without the automatic JSON mode
- **If you explicitly set `disable_auto_json`**: This option is now ignored (it does nothing, won't cause errors)
- **If you want JSON mode**: You can explicitly set `response_format` in your options

## Recommendations

### For Users with Supported Models

If you're using a model that supports JSON mode (Llama 3.1+, DeepSeek, etc.) and want structured JSON output, you can explicitly enable it:

```php
$options = array(
    'tools' => $your_tools,
    'response_format' => array( 'type' => 'json_object' ), // Explicit
);

$response = $client->run_with_tools( $messages, $tools, $options );
```

### For Users with Older Models

Simply use the default behavior (no response_format) and tool calling will work correctly:

```php
$options = array(
    'tools' => $your_tools,
    // No response_format needed
);

$response = $client->run_with_tools( $messages, $tools, $options );
```

## Testing

The fix has been verified to:

1. ✅ Not include `response_format` when tools are present and no explicit format is set
2. ✅ Include `response_format` when explicitly set by the user
3. ✅ Pass PHP syntax validation
4. ✅ Updated tests pass

## References

- [Cloudflare Workers AI JSON Mode Documentation](https://developers.cloudflare.com/workers-ai/features/json-mode/)
- [Cloudflare Workers AI Changelog - JSON Mode (Feb 25, 2025)](https://developers.cloudflare.com/changelog/2025-02-25-json-mode/)
- [Cloudflare Workers AI Supported Models](https://developers.cloudflare.com/workers-ai/models/)

## Related Issues

This fix addresses the following scenarios:
- Chat client errors with older Cloudflare models
- "unknown variant" errors when using tool calling
- Model compatibility issues with JSON mode

## Further Information

For questions or issues related to this fix, please:
1. Check your Cloudflare model supports JSON mode (if you need it)
2. Review the updated documentation in `docs/CLOUDFLARE_AI_UTILS.md`
3. File an issue on the GitHub repository with your specific model and configuration
