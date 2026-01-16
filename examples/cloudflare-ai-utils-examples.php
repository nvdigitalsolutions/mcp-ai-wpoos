<?php
/**
 * Example: Cloudflare Workers AI with Embedded Function Calling
 *
 * This example demonstrates how to use the Cloudflare Workers AI client
 * with embedded function calling (ai-utils style) in WordPress.
 *
 * @package WP_MCP_AI
 */

// Ensure WordPress is loaded.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'WordPress not loaded.' );
}

/**
 * Example 1: Basic Weather Tool
 *
 * This example shows how to create a simple tool that the AI can call
 * to get weather information.
 */
function wp_mcp_ai_example_basic_weather_tool() {
	// Initialize the Cloudflare client.
	$client = new WP_MCP_AI_Cloudflare_Client();

	// Define a simple weather tool.
	$weather_tool = array(
		'name'        => 'get-weather',
		'description' => 'Gets current weather information for a specific city',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'city' => array(
					'type'        => 'string',
					'description' => 'The name of the city',
				),
			),
			'required'   => array( 'city' ),
		),
		'function'    => function( $args ) {
			$city = sanitize_text_field( $args['city'] );

			// In a real implementation, you would call a weather API here.
			// For this example, we'll return mock data.
			$mock_weather_data = array(
				'Mumbai'   => 'Sunny, 28°C, humidity 65%',
				'New York' => 'Cloudy, 15°C, light rain expected',
				'London'   => 'Overcast, 12°C, windy',
				'Tokyo'    => 'Clear, 22°C, pleasant',
			);

			if ( isset( $mock_weather_data[ $city ] ) ) {
				return "Current weather in {$city}: {$mock_weather_data[$city]}";
			}

			return "Weather data not available for {$city}. Try Mumbai, New York, London, or Tokyo.";
		},
	);

	// Define the conversation.
	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'What is the weather like in Mumbai?',
		),
	);

	// Execute with the tool.
	$response = $client->run_with_tools(
		$messages,
		array( $weather_tool ),
		array(
			'verbose'          => true,
			'strictValidation' => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'error'   => true,
			'message' => $response->get_error_message(),
		);
	}

	return array(
		'success' => true,
		'content' => $response['choices'][0]['message']['content'],
	);
}

/**
 * Example 2: WordPress Database Query Tool
 *
 * This example shows how to create a tool that queries the WordPress database.
 */
function wp_mcp_ai_example_database_query_tool() {
	// Initialize the Cloudflare client.
	$client = new WP_MCP_AI_Cloudflare_Client();

	// Define a database query tool.
	$db_query_tool = array(
		'name'        => 'query-posts',
		'description' => 'Search WordPress posts by keyword',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'keyword' => array(
					'type'        => 'string',
					'description' => 'The keyword to search for in post titles',
				),
				'limit'   => array(
					'type'        => 'number',
					'description' => 'Maximum number of results to return',
				),
			),
			'required'   => array( 'keyword' ),
		),
		'function'    => function( $args ) {
			// Check user capabilities.
			if ( ! current_user_can( 'read' ) ) {
				return 'Permission denied: You do not have permission to query posts.';
			}

			$keyword = sanitize_text_field( $args['keyword'] );
			$limit   = isset( $args['limit'] ) ? absint( $args['limit'] ) : 5;
			$limit   = min( $limit, 20 ); // Cap at 20 results.

			// Query posts.
			$query_args = array(
				's'              => $keyword,
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'relevance',
			);

			$posts = get_posts( $query_args );

			if ( empty( $posts ) ) {
				return "No posts found matching '{$keyword}'.";
			}

			$results = array();
			foreach ( $posts as $post ) {
				$results[] = array(
					'title'   => $post->post_title,
					'excerpt' => wp_trim_words( $post->post_excerpt ? $post->post_excerpt : $post->post_content, 20 ),
					'url'     => get_permalink( $post->ID ),
				);
			}

			return "Found " . count( $posts ) . " posts matching '{$keyword}':\n\n" . wp_json_encode( $results, JSON_PRETTY_PRINT );
		},
	);

	// Define the conversation.
	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'Search for posts about "WordPress development"',
		),
	);

	// Execute with the tool.
	$response = $client->run_with_tools(
		$messages,
		array( $db_query_tool ),
		array(
			'verbose'          => true,
			'strictValidation' => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'error'   => true,
			'message' => $response->get_error_message(),
		);
	}

	return array(
		'success' => true,
		'content' => $response['choices'][0]['message']['content'],
	);
}

/**
 * Example 3: Multiple Tools with Auto-Trimming
 *
 * This example demonstrates using multiple tools with automatic relevance-based trimming.
 */
function wp_mcp_ai_example_multiple_tools_with_trimming() {
	// Initialize the Cloudflare client.
	$client = new WP_MCP_AI_Cloudflare_Client();

	// Define multiple tools.
	$tools = array(
		// Weather tool.
		array(
			'name'        => 'get-weather',
			'description' => 'Gets weather information for a city',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'city' => array( 'type' => 'string' ),
				),
				'required'   => array( 'city' ),
			),
			'function'    => function( $args ) {
				return "Weather in {$args['city']}: Sunny, 25°C";
			},
		),
		// User info tool.
		array(
			'name'        => 'get-user-info',
			'description' => 'Gets WordPress user information',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'number' ),
				),
				'required'   => array( 'user_id' ),
			),
			'function'    => function( $args ) {
				$user = get_userdata( absint( $args['user_id'] ) );
				if ( ! $user ) {
					return 'User not found';
				}
				return "User #{$user->ID}: {$user->display_name} ({$user->user_email})";
			},
		),
		// Post count tool.
		array(
			'name'        => 'count-posts',
			'description' => 'Counts WordPress posts by status',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'publish', 'draft', 'pending', 'private' ),
					),
				),
				'required'   => array( 'status' ),
			),
			'function'    => function( $args ) {
				$count  = wp_count_posts();
				$status = $args['status'];
				return "There are {$count->$status} posts with status '{$status}'.";
			},
		),
		// Time tool.
		array(
			'name'        => 'get-current-time',
			'description' => 'Gets the current WordPress server time',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'format' => array(
						'type'        => 'string',
						'description' => 'PHP date format string',
					),
				),
			),
			'function'    => function( $args ) {
				$format = isset( $args['format'] ) ? $args['format'] : 'Y-m-d H:i:s';
				return 'Current time: ' . current_time( $format );
			},
		),
	);

	// Define the conversation - asking about weather.
	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'What is the weather like right now?',
		),
	);

	// Execute with auto-trimming enabled.
	$response = $client->run_with_tools(
		$messages,
		$tools,
		array(
			'autoTrimTools'        => true,   // Enable auto-trimming.
			'maxTools'             => 3,      // Limit to 3 most relevant tools.
			'verbose'              => true,   // Enable logging.
			'maxRecursiveToolRuns' => 5,      // Allow up to 5 tool execution rounds.
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'error'   => true,
			'message' => $response->get_error_message(),
		);
	}

	return array(
		'success' => true,
		'content' => $response['choices'][0]['message']['content'],
	);
}

/**
 * Example 4: Calculator Tool with Complex Validation
 *
 * This example shows how to create a tool with complex parameter validation.
 */
function wp_mcp_ai_example_calculator_tool() {
	// Initialize the Cloudflare client.
	$client = new WP_MCP_AI_Cloudflare_Client();

	// Define a calculator tool with strict validation.
	$calculator_tool = array(
		'name'        => 'calculate',
		'description' => 'Performs mathematical calculations',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'operation' => array(
					'type'        => 'string',
					'description' => 'The mathematical operation to perform',
					'enum'        => array( 'add', 'subtract', 'multiply', 'divide', 'power', 'modulo' ),
				),
				'operand_a' => array(
					'type'        => 'number',
					'description' => 'First operand',
				),
				'operand_b' => array(
					'type'        => 'number',
					'description' => 'Second operand',
				),
			),
			'required'   => array( 'operation', 'operand_a', 'operand_b' ),
		),
		'function'    => function( $args ) {
			$a  = floatval( $args['operand_a'] );
			$b  = floatval( $args['operand_b'] );
			$op = $args['operation'];

			switch ( $op ) {
				case 'add':
					return $a + $b;
				case 'subtract':
					return $a - $b;
				case 'multiply':
					return $a * $b;
				case 'divide':
					if ( 0 == $b ) {
						throw new Exception( 'Division by zero is not allowed' );
					}
					return $a / $b;
				case 'power':
					return pow( $a, $b );
				case 'modulo':
					if ( 0 == $b ) {
						throw new Exception( 'Modulo by zero is not allowed' );
					}
					return fmod( $a, $b );
				default:
					throw new Exception( "Unknown operation: {$op}" );
			}
		},
	);

	// Define the conversation.
	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'Calculate 15 multiplied by 7, then add 3 to the result',
		),
	);

	// Execute with strict validation.
	$response = $client->run_with_tools(
		$messages,
		array( $calculator_tool ),
		array(
			'strictValidation'     => true,   // Enable strict validation.
			'verbose'              => true,   // Enable logging.
			'maxRecursiveToolRuns' => 3,      // Allow multiple calculations.
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'error'   => true,
			'message' => $response->get_error_message(),
		);
	}

	return array(
		'success' => true,
		'content' => $response['choices'][0]['message']['content'],
	);
}

/**
 * Example Usage
 *
 * Uncomment the example you want to test.
 */

// Example 1: Basic weather tool.
// $result = wp_mcp_ai_example_basic_weather_tool();
// print_r( $result );

// Example 2: WordPress database query.
// $result = wp_mcp_ai_example_database_query_tool();
// print_r( $result );

// Example 3: Multiple tools with auto-trimming.
// $result = wp_mcp_ai_example_multiple_tools_with_trimming();
// print_r( $result );

// Example 4: Calculator with complex validation.
// $result = wp_mcp_ai_example_calculator_tool();
// print_r( $result );
