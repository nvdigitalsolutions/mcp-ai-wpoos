<?php
/**
 * Tests for Gemini image generation with auto aspect ratio.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client auto aspect ratio functionality.
 */
class WP_MCP_AI_Gemini_Image_Auto_Aspect_Ratio_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that when aspect_ratio is set to "auto", the aspectRatio field is omitted from the API request.
	 */
	public function test_generate_image_with_auto_aspect_ratio_omits_aspect_ratio() {
		$settings                              = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key']            = 'gsk-test';
		$settings['gemini_image_aspect_ratio'] = 'auto';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'A beautiful landscape',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $client->generate_image( 'A beautiful landscape' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );

		// Decode the request body to verify the payload.
		$request_body = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $request_body );
		$this->assertArrayHasKey( 'generationConfig', $request_body );

		// When aspect_ratio is "auto", imageConfig should not be present or aspectRatio should be absent.
		if ( isset( $request_body['generationConfig']['imageConfig'] ) ) {
			$this->assertArrayNotHasKey( 'aspectRatio', $request_body['generationConfig']['imageConfig'], 'aspectRatio should not be set when using auto mode' );
		}
	}

	/**
	 * Test that when aspect_ratio is set to a specific value, the aspectRatio field is included.
	 */
	public function test_generate_image_with_specific_aspect_ratio_includes_aspect_ratio() {
		$settings                              = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key']            = 'gsk-test';
		$settings['gemini_image_aspect_ratio'] = '16:9';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'A widescreen vista',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $client->generate_image( 'A widescreen vista' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );

		// Decode the request body to verify the payload.
		$request_body = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $request_body );
		$this->assertArrayHasKey( 'generationConfig', $request_body );
		$this->assertArrayHasKey( 'imageConfig', $request_body['generationConfig'] );
		$this->assertArrayHasKey( 'aspectRatio', $request_body['generationConfig']['imageConfig'] );
		$this->assertSame( '16:9', $request_body['generationConfig']['imageConfig']['aspectRatio'] );
	}

	/**
	 * Test that edit_image with auto aspect ratio also omits the aspectRatio field.
	 */
	public function test_edit_image_with_auto_aspect_ratio_omits_aspect_ratio() {
		$settings                              = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key']            = 'gsk-test';
		$settings['gemini_image_aspect_ratio'] = 'auto';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'Edited successfully',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$source_image = array(
			'data'      => $png_base64,
			'mime_type' => 'image/png',
		);

		$result = $client->edit_image(
			'Make it brighter',
			array( 'source_image' => $source_image )
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );

		// Decode the request body to verify the payload.
		$request_body = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $request_body );
		$this->assertArrayHasKey( 'generationConfig', $request_body );

		// When aspect_ratio is "auto", imageConfig should not be present or aspectRatio should be absent.
		if ( isset( $request_body['generationConfig']['imageConfig'] ) ) {
			$this->assertArrayNotHasKey( 'aspectRatio', $request_body['generationConfig']['imageConfig'], 'aspectRatio should not be set when using auto mode' );
		}
	}

	/**
	 * Test that normalise_aspect_ratio handles "auto" correctly.
	 */
	public function test_normalise_aspect_ratio_handles_auto() {
		$client = new WP_MCP_AI_Gemini_Client();

		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'normalise_aspect_ratio' );
		$method->setAccessible( true );

		// Test various forms of "auto".
		$this->assertSame( 'auto', $method->invoke( $client, 'auto' ) );
		$this->assertSame( 'auto', $method->invoke( $client, 'Auto' ) );
		$this->assertSame( 'auto', $method->invoke( $client, 'AUTO' ) );
		$this->assertSame( 'auto', $method->invoke( $client, 'AuTo' ) );

		// Test that other values still work.
		$this->assertSame( '16:9', $method->invoke( $client, '16:9' ) );
		$this->assertSame( '1:1', $method->invoke( $client, '1:1' ) );
		$this->assertSame( '4:3', $method->invoke( $client, '4:3' ) );
	}
}
