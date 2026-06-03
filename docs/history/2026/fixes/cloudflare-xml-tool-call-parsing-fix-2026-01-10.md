# Cloudflare XML Tool Call Parsing Fix

**Date**: January 10, 2026  
**Issue**: Cloudflare Worker AI models output XML-formatted tool calls instead of proper tool_calls array  
**Status**: ⚠️ **IMPLEMENTATION NEEDED**

---

## Problem Description

When using Cloudflare Worker AI with certain models (especially qwen2.5-coder-32b-instruct), the model outputs tool calls as XML-formatted text in the `content` field instead of using the proper OpenAI-compatible `tool_calls` array format.

### Observed Behavior

**Model Output**:
```
<name>get_system_logs</name>
<arguments>{"activity_limit": 10, "activity_types": [], "error_limit": 20...}</arguments>
```

This XML is displayed directly to the user, and:
1. The agentic loop doesn't recognize it as a tool call
2. Tools are never executed
3. No final response is generated
4. User sees raw XML instead of tool execution results

### Root Cause

Certain Cloudflare models (particularly code-focused models like qwen/qwen2.5-coder-32b-instruct) are trained to output tool calls in XML format rather than using the proper JSON `tool_calls` array. The backend only checks for `result.tool_calls` array and doesn't detect XML-formatted tool calls in `result.response`.

---

## Solution

### Implementation Steps

The fix requires modifying `includes/class-wp-mcp-ai-cloudflare-client.php` to:

1. **Detect XML tool calls in response content**
2. **Parse XML to extract tool information**
3. **Convert to OpenAI-compatible format**
4. **Pass to agentic loop for execution**

### Code Changes

#### 1. Add Helper Methods (at end of class, before closing `}`)

```php
/**
 * Check if content contains XML-formatted tool calls.
 *
 * Some Cloudflare models (especially code-focused models like qwen2.5-coder)
 * output tool calls as XML text instead of using the proper tool_calls array.
 *
 * Pattern examples:
 * - <name>tool_name</name><arguments>{...}</arguments>
 * - ```xml\n<name>tool_name</name>\n<arguments>{...}</arguments>\n```
 *
 * @since 1.0.0
 *
 * @param string $content Response content to check.
 * @return bool True if content contains XML tool call pattern.
 */
protected function contains_xml_tool_call( $content ) {
	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return false;
	}

	// Check for XML tool call pattern: <name>...</name> followed by <arguments>...</arguments>
	// Allow for optional whitespace, newlines, and code block markers.
	$pattern = '/<name>\s*([^<]+)\s*<\/name>\s*<arguments>\s*(\{[^}]*\}|\[[^\]]*\])\s*<\/arguments>/is';
	
	return (bool) preg_match( $pattern, $content );
}

/**
 * Parse XML-formatted tool calls from content.
 *
 * Extracts tool name and arguments from XML text and converts to OpenAI format.
 *
 * @since 1.0.0
 *
 * @param string $content Response content containing XML tool calls.
 * @return array Array of tool calls in OpenAI format, or empty array if parsing fails.
 */
protected function parse_xml_tool_calls( $content ) {
	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return array();
	}

	$tool_calls = array();
	
	// Pattern to match XML tool calls.
	// Captures: <name>tool_name</name><arguments>{...}</arguments>
	$pattern = '/<name>\s*([^<]+)\s*<\/name>\s*<arguments>\s*(\{[^}]*\}|\[[^\]]*\])\s*<\/arguments>/is';
	
	$matches = array();
	if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$tool_name      = trim( $match[1] );
			$arguments_json = trim( $match[2] );
			
			// Validate tool name.
			if ( empty( $tool_name ) ) {
				WP_MCP_AI_Logger::log_event(
					'cloudflare_xml_tool_call_parse_error',
					'Found XML tool call but tool name is empty',
					array(
						'raw_match' => $match[0],
					)
				);
				continue;
			}
			
			// Validate and parse JSON arguments.
			$arguments_array = json_decode( $arguments_json, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_event(
					'cloudflare_xml_tool_call_parse_error',
					'Found XML tool call but arguments JSON is invalid',
					array(
						'tool_name'       => $tool_name,
						'arguments_json'  => $arguments_json,
						'json_error'      => json_last_error_msg(),
					)
				);
				continue;
			}
			
			// Convert to OpenAI format.
			$tool_calls[] = array(
				'id'       => 'call_xml_' . uniqid(),
				'type'     => 'function',
				'function' => array(
					'name'      => sanitize_text_field( $tool_name ),
					'arguments' => wp_json_encode( $arguments_array ),
				),
			);
			
			WP_MCP_AI_Logger::log_event(
				'cloudflare_xml_tool_call_parsed',
				'Successfully parsed XML tool call',
				array(
					'tool_name' => $tool_name,
					'arguments' => $arguments_array,
				)
			);
		}
	}
	
	return $tool_calls;
}
```

#### 2. Modify `normalize_response()` Method (around line 655)

Replace the existing tool_calls check:

```php
// Check for tool_calls in the result.
// Cloudflare may return tool_calls when the model decides to use a tool/function.
// We need to validate that tool_calls is not just present but also properly formatted.
if ( isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) && ! empty( $result['tool_calls'] ) ) {
```

With:

```php
// Check for tool_calls in the result (OpenAI format).
// Cloudflare may return tool_calls when the model decides to use a tool/function.
// We need to validate that tool_calls is not just present but also properly formatted.
$tool_calls_found = false;

if ( isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) && ! empty( $result['tool_calls'] ) ) {
	$tool_calls_found = true;
}

// Some Cloudflare models (e.g., qwen2.5-coder) output tool calls as XML text instead of using
// the proper tool_calls array. Detect and parse this format.
// Pattern: <name>tool_name</name><arguments>{...}</arguments>
if ( ! $tool_calls_found && ! empty( $content ) && $this->contains_xml_tool_call( $content ) ) {
	$parsed_tool_calls = $this->parse_xml_tool_calls( $content );
	
	if ( ! empty( $parsed_tool_calls ) ) {
		// Use the parsed tool calls as if they came from the API properly formatted.
		$result['tool_calls'] = $parsed_tool_calls;
		$tool_calls_found = true;
		
		// Remove XML from content since it's now converted to tool_calls.
		$message['content'] = '';
		
		WP_MCP_AI_Logger::log_event(
			'cloudflare_xml_tool_calls_parsed',
			'Detected and parsed XML-formatted tool calls from Cloudflare model response',
			array(
				'model'            => $model,
				'tool_call_count'  => count( $parsed_tool_calls ),
				'tool_names'       => array_map(
					function( $tc ) {
						return isset( $tc['function']['name'] ) ? $tc['function']['name'] : 'unknown';
					},
					$parsed_tool_calls
				),
				'original_content' => substr( $content, 0, 500 ),
			)
		);
	}
}

if ( $tool_calls_found ) {
```

---

## Testing

### Test Case 1: XML Tool Call Detection

**Input**: Model returns XML-formatted tool call
```php
$decoded = array(
	'result' => array(
		'response' => '<name>get_system_logs</name><arguments>{"activity_limit": 10}</arguments>',
	),
);
```

**Expected**: Tool call is detected, parsed, and converted to OpenAI format
```php
$result['tool_calls'] = array(
	array(
		'id' => 'call_xml_...',
		'type' => 'function',
		'function' => array(
			'name' => 'get_system_logs',
			'arguments' => '{"activity_limit":10}',
		),
	),
);
```

### Test Case 2: Regular Tool Calls Still Work

**Input**: Model returns proper tool_calls array  
**Expected**: No XML parsing, uses array as-is

### Test Case 3: Mixed Content

**Input**: Response with both text and XML tool call  
**Expected**: XML is removed, tool call is extracted

---

## Benefits

1. **Backward Compatible**: Doesn't affect models that already use proper tool_calls format
2. **Automatic Conversion**: Transparently handles XML format without user intervention
3. **Full Agentic Loop Support**: Parsed tool calls are executed and responded to normally
4. **Comprehensive Logging**: All parsing steps are logged for debugging

---

## Alternative Solutions Considered

1. **Model-specific handling**: Could check model name and only parse XML for qwen models
   - **Rejected**: Other models may also use XML format

2. **Prompt engineering**: Try to force model to use JSON format
   - **Rejected**: Model behavior is hard-coded in training

3. **Frontend parsing**: Parse XML in JavaScript
   - **Rejected**: Backend agentic loop needs to execute tools

---

## Related Issues

- Cloudflare system prompt format fix (PR #2770)
- Cloudflare tool choice implementation
- Cloudflare message normalization

---

## Status

**Implementation Status**: Ready for implementation  
**Testing**: Needs validation with qwen2.5-coder model  
**Documentation**: Complete

