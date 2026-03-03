<?php
/**
 * Test OpenAI Image Quality Model-Aware Defaults
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Tests that the OpenAI client uses model-aware quality defaults.
 */
class WP_MCP_AI_OpenAI_Image_Quality_Model_Aware_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that gpt-image-1.5 defaults to 'medium' quality when no settings exist.
	 */
	public function test_gpt_image_15_defaults_to_medium_quality() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		// Don't set openai_image_quality - let it use defaults.
		unset( $settings['openai_image_quality'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => time(),
				'model'   => 'gpt-image-1.5',
				'data'    => array(
					array(
						'b64_json'       => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
						'revised_prompt' => 'Test',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $client->generate_image( 'Test prompt', array( 'model' => 'gpt-image-1.5' ) );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured_request );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'quality', $body );

		// gpt-image-1.5 should send 'medium' directly, not map to 'standard'.
		$this->assertSame( 'medium', $body['quality'] );
	}

	/**
	 * Test that gpt-image-1 defaults to 'medium' quality when no settings exist.
	 */
	public function test_gpt_image_1_defaults_to_medium_quality() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		unset( $settings['openai_image_quality'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => time(),
				'model'   => 'gpt-image-1',
				'data'    => array(
					array(
						'b64_json'       => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
						'revised_prompt' => 'Test',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $client->generate_image( 'Test prompt', array( 'model' => 'gpt-image-1' ) );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured_request );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'quality', $body );

		// gpt-image-1 should send 'medium' directly, not map to 'standard'.
		$this->assertSame( 'medium', $body['quality'] );
	}

	/**
	 * Test that DALL-E models default to 'standard' quality when no settings exist.
	 */
	public function test_dalle_3_defaults_to_standard_quality() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		unset( $settings['openai_image_quality'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => time(),
				'model'   => 'dall-e-3',
				'data'    => array(
					array(
						'b64_json'       => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
						'revised_prompt' => 'Test',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $client->generate_image( 'Test prompt', array( 'model' => 'dall-e-3' ) );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured_request );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'quality', $body );
		$this->assertSame( 'standard', $body['quality'] );
	}

	/**
	 * Test that settings override model defaults.
	 */
	public function test_settings_override_model_defaults() {
		$settings                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']       = 'sk-test';
		$settings['openai_image_quality'] = 'high';
		$settings['openai_image_model']   = 'gpt-image-1.5';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => time(),
				'model'   => 'gpt-image-1.5',
				'data'    => array(
					array(
						'b64_json'       => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
						'revised_prompt' => 'Test',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $client->generate_image( 'Test prompt' );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured_request );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'quality', $body );

		// gpt-image-1.5 should send 'high' directly, not map to 'hd'.
		$this->assertSame( 'high', $body['quality'] );
	}

	/**
	 * Test that explicit options override everything.
	 */
	public function test_explicit_options_override_everything() {
		$settings                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']       = 'sk-test';
		$settings['openai_image_quality'] = 'medium';
		$settings['openai_image_model']   = 'gpt-image-1.5';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => time(),
				'model'   => 'dall-e-3',
				'data'    => array(
					array(
						'b64_json'       => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
						'revised_prompt' => 'Test',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Explicitly request 'hd' quality with DALL-E 3 model.
		$result = $client->generate_image(
			'Test prompt',
			array(
				'model'   => 'dall-e-3',
				'quality' => 'hd',
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured_request );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'quality', $body );
		$this->assertSame( 'hd', $body['quality'] );
	}
}
