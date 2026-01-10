<?php
/**
 * Example: Using run_with_tools() with different AI providers
 *
 * This example demonstrates how to use the new run_with_tools() method
 * that is now available across OpenAI, Gemini, and Anthropic clients.
 *
 * @package WP_MCP_AI
 */

// Example tool definitions
$tools = array(
	array(
		'name'        => 'get_weather',
		'description' => 'Get the current weather for a location',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'location' => array(
					'type'        => 'string',
					'description' => 'The city and state, e.g. San Francisco, CA',
				),
				'unit' => array(
					'type' => 'string',
					'enum' => array( 'celsius', 'fahrenheit' ),
				),
			),
			'required' => array( 'location' ),
		),
		// The actual function to execute
		'function' => function( $args ) {
			// Mock weather API call
			$location = $args['location'] ?? 'Unknown';
			$unit     = $args['unit'] ?? 'fahrenheit';
			
			return array(
				'location'    => $location,
				'temperature' => 72,
				'unit'        => $unit,
				'conditions'  => 'sunny',
			);
		},
	),
	array(
		'name'        => 'search_web',
		'description' => 'Search the web for information',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'The search query',
				),
			),
			'required' => array( 'query' ),
		),
		'function' => function( $args ) {
			// Mock web search
			$query = $args['query'] ?? '';
			
			return array(
				'query'   => $query,
				'results' => array(
					array(
						'title' => 'Example Result',
						'url'   => 'https://example.com',
						'snippet' => 'This is an example search result...',
					),
				),
			);
		},
	),
);

// Example conversation
$messages = array(
	array(
		'role'    => 'user',
		'content' => 'What\'s the weather like in San Francisco?',
	),
);

// Configuration options
$options = array(
	'model'                 => 'gpt-4',  // or 'gemini-1.5-pro', 'claude-3-5-sonnet-20241022'
	'temperature'           => 0.7,
	'maxRecursiveToolRuns'  => 5,    // Maximum recursion depth
	'strictValidation'      => true,  // Validate tool arguments
	'verbose'               => true,  // Enable verbose logging
	'autoTrimTools'         => false, // Auto-trim tools by relevance
);

// Example 1: Using with OpenAI
$openai_client = new WP_MCP_AI_OpenAI_Client();
$response = $openai_client->run_with_tools( $messages, $tools, $options );

if ( is_wp_error( $response ) ) {
	// Handle error
	echo 'Error: ' . $response->get_error_message();
} else {
	// Get the final response
	$final_message = $response['choices'][0]['message']['content'];
	echo 'OpenAI Response: ' . $final_message;
}

// Example 2: Using with Gemini
$gemini_client = new WP_MCP_AI_Gemini_Client();
$gemini_options = array_merge( $options, array( 'model' => 'gemini-1.5-pro' ) );
$response = $gemini_client->run_with_tools( $messages, $tools, $gemini_options );

if ( ! is_wp_error( $response ) ) {
	$final_message = $response['choices'][0]['message']['content'];
	echo 'Gemini Response: ' . wp_json_encode( $final_message );
}

// Example 3: Using with Anthropic
$anthropic_client = new WP_MCP_AI_Anthropic_Client();
$anthropic_options = array_merge( $options, array( 
	'model'      => 'claude-3-5-sonnet-20241022',
	'max_tokens' => 1024, // Required by Anthropic
) );
$response = $anthropic_client->run_with_tools( $messages, $tools, $anthropic_options );

if ( ! is_wp_error( $response ) ) {
	$final_message = $response['choices'][0]['message']['content'];
	echo 'Anthropic Response: ' . wp_json_encode( $final_message );
}

// Example 4: Tool execution flow
/*
 * The run_with_tools() method handles the following automatically:
 * 
 * 1. Send initial message with tool definitions
 * 2. If model requests tool calls:
 *    a. Validate tool arguments (if strictValidation enabled)
 *    b. Execute each tool function
 *    c. Add tool results to conversation
 *    d. Send updated conversation back to model
 * 3. Repeat step 2 until:
 *    - Model doesn't request any more tools, OR
 *    - Max recursion depth reached
 * 4. Return final response
 */

// Example 5: Error handling
$result = $openai_client->run_with_tools( $messages, $tools, $options );

if ( is_wp_error( $result ) ) {
	$error_code = $result->get_error_code();
	$error_data = $result->get_error_data();
	
	switch ( $error_code ) {
		case 'wp_mcp_ai_no_tools':
			echo 'No tools were provided';
			break;
		case 'wp_mcp_ai_max_tool_recursion':
			echo 'Maximum recursion depth reached';
			echo 'Final messages: ' . wp_json_encode( $error_data['final_messages'] );
			break;
		case 'wp_mcp_ai_missing_required_param':
			echo 'Required parameter missing: ' . $error_data['parameter'];
			break;
		case 'wp_mcp_ai_invalid_param_type':
			echo 'Invalid parameter type: ' . $error_data['parameter'];
			break;
		default:
			echo 'Error: ' . $result->get_error_message();
	}
}
