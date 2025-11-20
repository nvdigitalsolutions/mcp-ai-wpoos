<?php
/**
 * Example: Using Gemini with JSON Schema Response
 *
 * This example demonstrates how to use the new JSON schema feature
 * with the Gemini API to extract structured data from text.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example: Extract recipe from text using JSON schema.
 *
 * This demonstrates the new response_mime_type and response_json_schema
 * options added to the Gemini client.
 */
function wp_mcp_ai_example_extract_recipe_with_schema() {
	$client = new WP_MCP_AI_Gemini_Client();

	$prompt = 'Please extract the recipe from the following text.
The user wants to make delicious chocolate chip cookies.
They need 2 and 1/4 cups of all-purpose flour, 1 teaspoon of baking soda,
1 teaspoon of salt, 1 cup of unsalted butter (softened), 3/4 cup of granulated sugar,
3/4 cup of packed brown sugar, 1 teaspoon of vanilla extract, and 2 large eggs.
For the best part, they will need 2 cups of semisweet chocolate chips.
First, preheat the oven to 375°F (190°C). Then, in a small bowl, whisk together the flour,
baking soda, and salt. In a large bowl, cream together the butter, granulated sugar, and brown sugar
until light and fluffy. Beat in the vanilla and eggs, one at a time. Gradually beat in the dry
ingredients until just combined. Finally, stir in the chocolate chips. Drop by rounded tablespoons
onto ungreased baking sheets and bake for 9 to 11 minutes.';

	// Define the JSON schema for the response.
	$schema = array(
		'type'       => 'object',
		'properties' => array(
			'recipe_name'       => array(
				'type'        => 'string',
				'description' => 'The name of the recipe.',
			),
			'prep_time_minutes' => array(
				'type'        => 'integer',
				'description' => 'Optional time in minutes to prepare the recipe.',
			),
			'ingredients'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array(
							'type'        => 'string',
							'description' => 'Name of the ingredient.',
						),
						'quantity' => array(
							'type'        => 'string',
							'description' => 'Quantity of the ingredient, including units.',
						),
					),
					'required'   => array( 'name', 'quantity' ),
				),
			),
			'instructions'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		),
		'required'   => array( 'recipe_name', 'ingredients', 'instructions' ),
	);

	$messages = array(
		array(
			'role'    => 'user',
			'content' => $prompt,
		),
	);

	$options = array(
		'model'                => 'gemini-2.5-flash',
		'response_mime_type'   => 'application/json',
		'response_json_schema' => $schema,
	);

	$response = $client->create_chat_completion( $messages, $options );

	if ( is_wp_error( $response ) ) {
		error_log( 'Recipe extraction failed: ' . $response->get_error_message() );
		return $response;
	}

	// The response content should be valid JSON matching the schema.
	if ( isset( $response['choices'][0]['message']['content'][0]['text'] ) ) {
		$json_text = $response['choices'][0]['message']['content'][0]['text'];
		$recipe    = json_decode( $json_text, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			// Successfully extracted structured recipe data!
			return $recipe;
		}
	}

	return new WP_Error( 'invalid_response', 'Failed to parse JSON response' );
}

/**
 * Example: Using response_schema option (alternative approach).
 *
 * This uses the responseSchema field instead of responseJsonSchema.
 */
function wp_mcp_ai_example_structured_output() {
	$client = new WP_MCP_AI_Gemini_Client();

	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'List 3 popular cookie recipes with their main ingredients.',
		),
	);

	// Simpler schema definition using response_schema.
	$schema = array(
		'type'       => 'object',
		'properties' => array(
			'recipes' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'ingredients' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
	);

	$options = array(
		'model'              => 'gemini-2.5-flash',
		'response_mime_type' => 'application/json',
		'response_schema'    => $schema,
	);

	$response = $client->create_chat_completion( $messages, $options );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( isset( $response['choices'][0]['message']['content'][0]['text'] ) ) {
		return json_decode( $response['choices'][0]['message']['content'][0]['text'], true );
	}

	return array();
}

/**
 * Example: Extract contact information with strict schema validation.
 */
function wp_mcp_ai_example_extract_contact_info( $text ) {
	$client = new WP_MCP_AI_Gemini_Client();

	$schema = array(
		'type'       => 'object',
		'properties' => array(
			'name'    => array(
				'type'        => 'string',
				'description' => 'Full name of the person.',
			),
			'email'   => array(
				'type'        => 'string',
				'description' => 'Email address.',
			),
			'phone'   => array(
				'type'        => 'string',
				'description' => 'Phone number.',
			),
			'company' => array(
				'type'        => 'string',
				'description' => 'Company name.',
			),
		),
		'required'   => array( 'name' ),
	);

	$messages = array(
		array(
			'role'    => 'user',
			'content' => 'Extract contact information from this text: ' . $text,
		),
	);

	$options = array(
		'model'                => 'gemini-2.5-flash',
		'response_mime_type'   => 'application/json',
		'response_json_schema' => $schema,
	);

	$response = $client->create_chat_completion( $messages, $options );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( isset( $response['choices'][0]['message']['content'][0]['text'] ) ) {
		return json_decode( $response['choices'][0]['message']['content'][0]['text'], true );
	}

	return array();
}

/**
 * Usage examples:
 *
 * 1. Extract a recipe:
 *    $recipe = wp_mcp_ai_example_extract_recipe_with_schema();
 *    if ( ! is_wp_error( $recipe ) ) {
 *        echo 'Recipe: ' . $recipe['recipe_name'];
 *        foreach ( $recipe['ingredients'] as $ingredient ) {
 *            echo $ingredient['quantity'] . ' ' . $ingredient['name'];
 *        }
 *    }
 *
 * 2. Get structured recipe list:
 *    $recipes = wp_mcp_ai_example_structured_output();
 *    foreach ( $recipes['recipes'] as $recipe ) {
 *        echo $recipe['name'];
 *    }
 *
 * 3. Extract contact info:
 *    $text = 'John Doe from Acme Corp can be reached at john@acme.com or 555-1234';
 *    $contact = wp_mcp_ai_example_extract_contact_info( $text );
 *    echo $contact['name'] . ' - ' . $contact['email'];
 */
