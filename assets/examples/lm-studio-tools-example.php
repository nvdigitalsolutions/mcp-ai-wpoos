<?php
/**
 * Example: Using Tools with LM Studio Provider
 *
 * This example demonstrates how to use function calling/tools with LM Studio
 * and models like qwen/qwen3-coder-30b:2.
 *
 * @package WP_MCP_AI
 */

// Example 1: Direct API call with tools
function example_lm_studio_with_tools() {
	// Initialize the LM Studio client
	$client = new WP_MCP_AI_LM_Studio_Client();

	// Define the tools available to the AI
	$tools = array(
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'search_products',
				'description' => 'Search the product catalog by various criteria. Use this whenever a customer asks about product availability, pricing, or specifications.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array(
							'type'        => 'string',
							'description' => 'Search terms or product name',
						),
						'category'  => array(
							'type'        => 'string',
							'description' => 'Product category to filter by',
							'enum'        => array( 'electronics', 'clothing', 'home', 'outdoor' ),
						),
						'max_price' => array(
							'type'        => 'number',
							'description' => 'Maximum price in dollars',
						),
					),
					'required'   => array( 'query' ),
				),
			),
		),
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'get_product_details',
				'description' => 'Get detailed information about a specific product',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'string',
							'description' => 'The unique product identifier',
						),
					),
					'required'   => array( 'product_id' ),
				),
			),
		),
	);

	// Prepare the messages
	$messages = array(
		array(
			'role'    => 'system',
			'content' => 'You are a helpful shopping assistant. Use the available tools to help customers find products.',
		),
		array(
			'role'    => 'user',
			'content' => 'What dell products do you have under $50 in electronics?',
		),
	);

	// Make the request with tools
	$response = $client->create_chat_completion(
		$messages,
		array(
			'tools'       => $tools,
			'temperature' => 0.7,
			'max_tokens'  => 1000,
		)
	);

	// Check if the response contains tool calls
	if ( ! is_wp_error( $response ) && isset( $response['choices'][0]['message']['tool_calls'] ) ) {
		$tool_calls = $response['choices'][0]['message']['tool_calls'];

		foreach ( $tool_calls as $tool_call ) {
			$function_name = $tool_call['function']['name'];
			$arguments     = json_decode( $tool_call['function']['arguments'], true );

			// Execute the tool
			$tool_result = execute_tool( $function_name, $arguments );

			// Add the assistant's tool call to the conversation
			$messages[] = array(
				'role'       => 'assistant',
				'tool_calls' => array( $tool_call ),
			);

			// Add the tool result to the conversation
			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $tool_call['id'],
				'content'      => wp_json_encode( $tool_result ),
			);
		}

		// Make another request with the tool results
		$final_response = $client->create_chat_completion(
			$messages,
			array(
				'tools'       => $tools,
				'temperature' => 0.7,
				'max_tokens'  => 1000,
			)
		);

		return $final_response;
	}

	return $response;
}

// Example 2: Using the chat service (handles tools automatically)
function example_lm_studio_with_chat_service() {
	// The chat service handles tool execution automatically
	$chat_service = new WP_MCP_AI_Chat_Service();

	$result = $chat_service->send_message(
		'What dell products do you have under $50 in electronics?',
		123, // assistant_id
		array(
			'provider' => 'lm_studio',
			'model'    => 'qwen/qwen3-coder-30b:2',
		)
	);

	// The service will:
	// 1. Send message to LM Studio with tools
	// 2. Detect tool_calls in response
	// 3. Execute the tools
	// 4. Send results back to LM Studio
	// 5. Return final response

	return $result;
}

// Helper function to execute tools (simplified example)
function execute_tool( $function_name, $arguments ) {
	switch ( $function_name ) {
		case 'search_products':
			// Search products in your database
			$query     = $arguments['query'] ?? '';
			$category  = $arguments['category'] ?? '';
			$max_price = $arguments['max_price'] ?? null;

			// Your product search logic here
			$products = array(
				array(
					'id'       => 'DELL-123',
					'name'     => 'Dell Wireless Mouse',
					'price'    => 29.99,
					'category' => 'electronics',
				),
				array(
					'id'       => 'DELL-456',
					'name'     => 'Dell USB-C Hub',
					'price'    => 45.99,
					'category' => 'electronics',
				),
			);

			return array(
				'products' => $products,
				'count'    => count( $products ),
			);

		case 'get_product_details':
			$product_id = $arguments['product_id'] ?? '';

			// Fetch product details
			return array(
				'id'          => $product_id,
				'name'        => 'Dell Wireless Mouse',
				'price'       => 29.99,
				'category'    => 'electronics',
				'description' => 'Ergonomic wireless mouse with 2.4GHz connectivity',
				'in_stock'    => true,
			);

		default:
			return array( 'error' => 'Unknown function' );
	}
}

// Example 3: Configuration check
function check_lm_studio_tool_support() {
	$client = new WP_MCP_AI_LM_Studio_Client();

	// Test connection
	$connection = $client->test_connection();

	if ( is_wp_error( $connection ) ) {
		return array(
			'status'  => 'error',
			'message' => 'LM Studio not accessible: ' . $connection->get_error_message(),
		);
	}

	// List available models
	$models = $client->list_models();

	if ( is_wp_error( $models ) ) {
		return array(
			'status'  => 'error',
			'message' => 'Cannot list models: ' . $models->get_error_message(),
		);
	}

	// Check for function calling capable models
	$function_calling_models = array();
	foreach ( $models as $model ) {
		$model_id = $model['id'] ?? '';
		// Common patterns for function calling models
		if ( stripos( $model_id, 'qwen' ) !== false ||
		     stripos( $model_id, 'llama-3.1' ) !== false ||
		     stripos( $model_id, 'function' ) !== false ) {
			$function_calling_models[] = $model_id;
		}
	}

	return array(
		'status'                    => 'success',
		'total_models'              => count( $models ),
		'function_calling_models'   => $function_calling_models,
		'supports_tools'            => ! empty( $function_calling_models ),
		'recommended_models'        => array_slice( $function_calling_models, 0, 3 ),
	);
}

// Example output format for tool_calls response
/*
{
  "id": "chatcmpl-gb1t1uqzefudice8ntxd9i",
  "object": "chat.completion",
  "created": 1730913210,
  "model": "qwen/qwen3-coder-30b:2",
  "choices": [
    {
      "index": 0,
      "logprobs": null,
      "finish_reason": "tool_calls",
      "message": {
        "role": "assistant",
        "tool_calls": [
          {
            "id": "365174485",
            "type": "function",
            "function": {
              "name": "search_products",
              "arguments": "{\"query\":\"dell\",\"category\":\"electronics\",\"max_price\":50}"
            }
          }
        ]
      }
    }
  ],
  "usage": {
    "prompt_tokens": 263,
    "completion_tokens": 34,
    "total_tokens": 297
  },
  "system_fingerprint": "qwen/qwen3-coder-30b:2"
}
*/
