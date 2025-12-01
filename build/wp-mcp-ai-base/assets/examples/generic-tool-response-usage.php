<?php
/**
 * Example: Using the Generic Tool Response System
 *
 * This file demonstrates how to refactor code to use the new generic tool
 * response interface instead of directly parsing provider-specific responses.
 *
 * @package WP_MCP_AI
 */

/**
 * BEFORE: Direct parsing of provider-specific response
 * This approach requires different code for each provider.
 */
function example_old_approach_openai() {
	$openai_client = new WP_MCP_AI_OpenAI_Client();
	$messages      = array(
		array(
			'role'    => 'user',
			'content' => 'Hello, how are you?',
		),
	);

	$response = $openai_client->create_chat_completion( $messages, array() );

	// Old approach: Direct access to response structure.
	if ( is_wp_error( $response ) ) {
		return 'Error: ' . $response->get_error_message();
	}

	// OpenAI-specific structure.
	$content = $response['choices'][0]['message']['content'] ?? '';
	$usage   = $response['usage'] ?? array();
	$model   = $response['model'] ?? 'unknown';

	return array(
		'content' => $content,
		'usage'   => $usage,
		'model'   => $model,
	);
}

/**
 * BEFORE: Different code needed for Gemini
 */
function example_old_approach_gemini() {
	$gemini_client = new WP_MCP_AI_Gemini_Client();
	$messages      = array(
		array(
			'role'    => 'user',
			'content' => 'Hello, how are you?',
		),
	);

	$response = $gemini_client->create_chat_completion( $messages, array() );

	if ( is_wp_error( $response ) ) {
		return 'Error: ' . $response->get_error_message();
	}

	// Gemini responses are already normalized, but we still need to parse.
	$content = '';
	if ( isset( $response['choices'][0]['message']['content'] ) ) {
		$content_data = $response['choices'][0]['message']['content'];
		if ( is_array( $content_data ) ) {
			// Extract text from segments.
			foreach ( $content_data as $segment ) {
				if ( isset( $segment['type'] ) && 'text' === $segment['type'] ) {
					$content .= $segment['text'];
				}
			}
		} else {
			$content = $content_data;
		}
	}

	$usage = $response['usage'] ?? array();
	$model = $response['model'] ?? 'unknown';

	return array(
		'content' => $content,
		'usage'   => $usage,
		'model'   => $model,
	);
}

/**
 * AFTER: Unified approach using Generic Tool Response
 * This code works identically for all providers!
 *
 * @param string $provider The AI provider to use (openai, gemini, anthropic, ollama).
 * @return array|string Response data or error message.
 */
function example_new_approach_unified( $provider = 'openai' ) {
	// Get the appropriate client based on provider.
	switch ( $provider ) {
		case 'openai':
			$client = new WP_MCP_AI_OpenAI_Client();
			break;
		case 'gemini':
			$client = new WP_MCP_AI_Gemini_Client();
			break;
		case 'anthropic':
			$client = new WP_MCP_AI_Anthropic_Client();
			break;
		case 'ollama':
			$client = new WP_MCP_AI_Ollama_Client();
			break;
		default:
			return 'Unsupported provider';
	}

	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'Hello, how are you?',
		),
	);

	$raw_response = $client->create_chat_completion( $messages, array() );

	// NEW: Extract generic response using the adapter.
	$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider );

	// Unified interface works for all providers!
	if ( ! $generic_response->is_success() ) {
		$error = $generic_response->get_error();
		return 'Error: ' . $error['message'];
	}

	return array(
		'content'  => $generic_response->get_content(),
		'usage'    => $generic_response->get_usage(),
		'model'    => $generic_response->get_model(),
		'provider' => $generic_response->get_provider(),
	);
}

/**
 * Example: Working with tool calls
 *
 * @param array|WP_Error $raw_response Raw response from AI provider.
 * @param string         $provider Provider identifier.
 * @return array Results of tool execution or content.
 */
function example_handling_tool_calls( $raw_response, $provider ) {
	$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider );

	if ( ! $generic_response->is_success() ) {
		return array( 'error' => $generic_response->get_error() );
	}

	// Check if the model requested tool calls.
	$tool_calls = $generic_response->get_tool_calls();
	if ( null !== $tool_calls ) {
		$results = array();
		foreach ( $tool_calls as $tool_call ) {
			$function_name = $tool_call['function']['name'];
			$arguments     = json_decode( $tool_call['function']['arguments'], true );

			// Execute the tool.
			$result = execute_tool( $function_name, $arguments );

			$results[] = array(
				'tool'   => $function_name,
				'result' => $result,
			);
		}
		return $results;
	}

	// No tool calls, just return the text content.
	return array( 'content' => $generic_response->get_content() );
}

/**
 * Example: Checking token usage
 *
 * @param array|WP_Error $raw_response Raw response from AI provider.
 * @param string         $provider Provider identifier.
 * @return string|null Usage information or null if not available.
 */
function example_checking_usage( $raw_response, $provider ) {
	$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider );

	if ( ! $generic_response->is_success() ) {
		return null;
	}

	$usage = $generic_response->get_usage();
	if ( null === $usage ) {
		return 'No usage data available';
	}

	return sprintf(
		'Used %d prompt tokens, %d completion tokens, %d total tokens',
		$usage['prompt_tokens'] ?? 0,
		$usage['completion_tokens'] ?? 0,
		$usage['total_tokens'] ?? 0
	);
}

/**
 * Example: Debugging with original response
 *
 * @param array|WP_Error $raw_response Raw response from AI provider.
 * @param string         $provider Provider identifier.
 * @return WP_MCP_AI_Generic_Tool_Response Generic response object.
 */
function example_debugging( $raw_response, $provider ) {
	$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider );

	// Access the original response for debugging.
	$original = $generic_response->get_original_response();

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'Original response from ' . $provider . ': ' . wp_json_encode( $original ) );

	return $generic_response;
}

/**
 * Example: Checking provider support before making requests
 *
 * @param string $provider Provider identifier to validate.
 * @return WP_Error|bool WP_Error if unsupported, true if supported.
 */
function example_provider_validation( $provider ) {
	if ( ! wp_mcp_ai_is_provider_supported( $provider ) ) {
		return new WP_Error(
			'unsupported_provider',
			sprintf( 'The provider "%s" is not supported', $provider )
		);
	}

	// Provider is supported, proceed with request.
	return true;
}

/**
 * Placeholder function for demonstration.
 *
 * @param string $function_name Tool function name.
 * @param array  $arguments     Tool arguments.
 * @return mixed Tool execution result.
 */
function execute_tool( $function_name, $arguments ) {
	// In real code, this would call the actual tool registry.
	// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	return array( 'executed' => $function_name );
}
