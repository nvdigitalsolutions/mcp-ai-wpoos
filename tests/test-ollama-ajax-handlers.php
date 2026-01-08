<?php
/**
 * Test Ollama AJAX handlers.
 *
 * This test verifies that the Ollama AJAX handlers return the correct data format.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Ollama AJAX Handlers.
 */
class Test_Ollama_AJAX_Handlers extends WP_UnitTestCase {

	/**
	 * Test that fetch_ollama_models returns model objects with correct structure.
	 *
	 * This test verifies that the AJAX handler returns an array of model objects
	 * with 'name', 'size', and 'family' properties, not just model name strings.
	 */
	public function test_fetch_ollama_models_returns_model_objects() {
		// Mock Ollama API response with typical model data.
		$mock_ollama_response = array(
			'models' => array(
				array(
					'name'    => 'llama3:latest',
					'size'    => 4661224768,
					'details' => array(
						'family' => 'llama',
					),
				),
				array(
					'name'    => 'mistral:latest',
					'size'    => 4109865159,
					'details' => array(
						'family' => 'mistral',
					),
				),
				array(
					'name' => 'codellama:latest',
					'size' => 3826793677,
					// Model without family details.
				),
			),
		);

		// Mock the HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $mock_ollama_response ) {
				if ( false !== strpos( $url, '/api/tags' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( $mock_ollama_response ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Set up POST data.
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['endpoint_url'] = 'http://localhost:11434';

		// Capture output.
		ob_start();
		try {
			// Initialize the AJAX handler.
			$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
			$ajax_handlers->handle_fetch_ollama_models();
		} catch ( WPDieException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Parse the JSON response.
		$response = json_decode( $output, true );

		// Assertions.
		$this->assertTrue( $response['success'], 'Response should be successful' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data key' );
		$this->assertArrayHasKey( 'models', $response['data'], 'Response data should have models key' );
		$this->assertIsArray( $response['data']['models'], 'Models should be an array' );
		$this->assertCount( 3, $response['data']['models'], 'Should return 3 models' );

		// Verify each model has the correct structure.
		foreach ( $response['data']['models'] as $model ) {
			$this->assertIsArray( $model, 'Each model should be an array (object)' );
			$this->assertArrayHasKey( 'name', $model, 'Model should have name property' );
			$this->assertArrayHasKey( 'size', $model, 'Model should have size property' );
			$this->assertArrayHasKey( 'family', $model, 'Model should have family property' );
		}

		// Verify first model data.
		$first_model = $response['data']['models'][0];
		$this->assertEquals( 'llama3:latest', $first_model['name'] );
		$this->assertEquals( 4661224768, $first_model['size'] );
		$this->assertEquals( 'llama', $first_model['family'] );

		// Verify model without family has empty string.
		$third_model = $response['data']['models'][2];
		$this->assertEquals( 'codellama:latest', $third_model['name'] );
		$this->assertEquals( '', $third_model['family'], 'Model without family should have empty string' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that models are not just strings.
	 *
	 * This test ensures the bug is fixed where models were returned as strings
	 * instead of objects, which broke the JavaScript that expected model.name.
	 */
	public function test_fetch_ollama_models_not_just_strings() {
		// Mock Ollama API response.
		$mock_ollama_response = array(
			'models' => array(
				array(
					'name' => 'llama3:latest',
					'size' => 4661224768,
				),
			),
		);

		// Mock the HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $mock_ollama_response ) {
				if ( false !== strpos( $url, '/api/tags' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( $mock_ollama_response ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Set up POST data.
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['endpoint_url'] = 'http://localhost:11434';

		// Capture output.
		ob_start();
		try {
			$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
			$ajax_handlers->handle_fetch_ollama_models();
		} catch ( WPDieException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Parse the JSON response.
		$response = json_decode( $output, true );

		// Assertions - the model should NOT be just a string.
		$first_model = $response['data']['models'][0];
		$this->assertIsArray( $first_model, 'Model should be an array/object, not a string' );
		$this->assertNotEquals( 'llama3:latest', $first_model, 'Model should not be just the name string' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}
}
