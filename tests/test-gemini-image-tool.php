<?php
/**
 * Gemini Image Tool
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';

/**
 * Tests for the Gemini image generation tool.
 */
class WP_MCP_AI_Gemini_Image_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * The tool should return inline content and a download URL when an image is generated successfully.
	 */
	public function test_execute_returns_inline_content_payload() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'gsk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
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
									'text' => 'Try bright morning light and a shallow depth of field for extra charm.',
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

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'    => 'A friendly otter in a teacup',
				'mime_type' => 'image/png',
				'file_name' => 'otter-teacup',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );

		$this->assertArrayHasKey( 'content', $result );
		$this->assertIsArray( $result['content'] );
		$this->assertSame( 'base64', $result['content']['encoding'] );
		$this->assertSame( $png_base64, $result['content']['data'] );
		$this->assertSame( 'image/png', $result['content']['mime_type'] );
		$this->assertSame( 'data:image/png;base64,' . $result['content']['data'], $result['content']['data_url'] );

		$this->assertArrayHasKey( 'download_url', $result );
		$this->assertNotEmpty( $result['download_url'] );
		$this->assertSame( 'Try bright morning light and a shallow depth of field for extra charm.', $result['revised_prompt'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			$this->assertSame( wp_get_attachment_url( $result['attachment_id'] ), $result['download_url'] );
		}

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * The tool should implement the Tool Rules Interface.
	 */
	public function test_implements_tool_rules_interface() {
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		$this->assertTrue( method_exists( $tool, 'get_tool_rules' ), 'Tool should implement get_tool_rules method' );

		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'model_requirements', $rules );
		$this->assertArrayHasKey( 'parameter_constraints', $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
		$this->assertArrayHasKey( 'response_constraints', $rules );
		$this->assertArrayHasKey( 'dependencies', $rules );
		$this->assertArrayHasKey( 'orchestration_hints', $rules );

		// Verify model requirements.
		$this->assertArrayHasKey( 'providers', $rules['model_requirements'] );
		$this->assertContains( 'gemini', $rules['model_requirements']['providers'] );

		// Verify parameter constraints.
		$this->assertArrayHasKey( 'required_fields', $rules['parameter_constraints'] );
		$this->assertContains( 'prompt', $rules['parameter_constraints']['required_fields'] );

		// Verify dependencies.
		$this->assertArrayHasKey( 'required_settings', $rules['dependencies'] );
		$this->assertArrayHasKey( 'api_key', $rules['dependencies']['required_settings'] );
		$this->assertSame( 'wp_mcp_ai_gemini_api_key', $rules['dependencies']['required_settings']['api_key'] );
	}

	/**
	 * The tool should have correct capability flags.
	 */
	public function test_has_correct_capability_flags() {
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags, 'Should require Gemini API credentials' );
		$this->assertContains( 'requires-capability', $flags, 'Should require user capabilities' );
		$this->assertContains( 'write', $flags, 'Should create media files' );
		$this->assertContains( 'async', $flags, 'Should be async operation' );
		$this->assertContains( 'rate-limited', $flags, 'Should be rate limited' );
		$this->assertContains( 'requires-model', $flags, 'Should require model specification' );
		$this->assertContains( 'consumes-tokens', $flags, 'Should consume tokens' );
		$this->assertContains( 'model-dependent', $flags, 'Should be model dependent' );

		// Verify incorrect flags are not present.
		$this->assertNotContains( 'read-only', $flags, 'Should NOT be read-only as it creates files' );
		$this->assertNotContains( 'local-only', $flags, 'Should NOT be local-only as it makes API calls' );
	}

	/**
	 * The tool should handle base64url encoded inline data from Gemini.
	 */
	public function test_execute_decodes_base64url_inline_payload() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'gsk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary       = base64_decode( $png_base64 );
		$png_base64url    = rtrim( strtr( base64_encode( $png_binary ), '+/', '-_' ), '=' );

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64url ) {
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
									'inlineData' => array(
										'data'     => $png_base64url,
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

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'    => 'A friendly otter in a teacup',
				'mime_type' => 'image/png',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertSame( 'base64', $result['content']['encoding'] );
		$this->assertSame( base64_encode( $png_binary ), $result['content']['data'] );
		$this->assertSame( 'image/png', $result['content']['mime_type'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}
}
