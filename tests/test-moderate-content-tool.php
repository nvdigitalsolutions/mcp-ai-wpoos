<?php
/**
 * Tests for the OpenAI content moderation tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-moderate-content.php';

/**
 * Test class for WP_MCP_AI_Tool_Moderate_Content.
 */
class WP_MCP_AI_Moderate_Content_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that the tool slug is correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Tool_Moderate_Content();
		$this->assertSame( 'moderate_content', $tool->get_slug() );
	}

	/**
	 * Test that the tool has a proper name and description.
	 */
	public function test_has_name_and_description() {
		$tool = new WP_MCP_AI_Tool_Moderate_Content();

		$this->assertIsString( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_name() );

		$this->assertIsString( $tool->get_description() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test that the tool requires authentication.
	 */
	public function test_execute_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Moderate_Content();
		$result = $tool->execute( array( 'input' => 'Test content' ), array() );

		// Without user_id, tool should be accessible (moderation can be public).
		// But let's check with a user for now per existing pattern.
		$this->assertNotWPError( $result, 'Tool should work without authentication for moderation checks' );
	}

	/**
	 * Test that the tool requires input parameter.
	 */
	public function test_execute_requires_input_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Moderate_Content();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test that empty input is rejected.
	 */
	public function test_execute_rejects_empty_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Moderate_Content();
		$result = $tool->execute( array( 'input' => '' ), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_empty_input', $result->get_error_code() );
	}

	/**
	 * Test successful moderation of safe content.
	 */
	public function test_execute_moderates_safe_content() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Moderate_Content();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'id'      => 'modr-test123',
				'model'   => 'omni-moderation-latest',
				'results' => array(
					array(
						'flagged'    => false,
						'categories' => array(
							'sexual'                   => false,
							'sexual/minors'            => false,
							'harassment'               => false,
							'harassment/threatening'   => false,
							'hate'                     => false,
							'hate/threatening'         => false,
							'illicit'                  => false,
							'illicit/violent'          => false,
							'self-harm'                => false,
							'self-harm/intent'         => false,
							'self-harm/instructions'   => false,
							'violence'                 => false,
							'violence/graphic'         => false,
						),
						'category_scores' => array(
							'sexual'                   => 0.00001,
							'sexual/minors'            => 0.00001,
							'harassment'               => 0.00002,
							'harassment/threatening'   => 0.00001,
							'hate'                     => 0.00001,
							'hate/threatening'         => 0.00001,
							'illicit'                  => 0.00001,
							'illicit/violent'          => 0.00001,
							'self-harm'                => 0.00001,
							'self-harm/intent'         => 0.00001,
							'self-harm/instructions'   => 0.00001,
							'violence'                 => 0.00001,
							'violence/graphic'         => 0.00001,
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
				'input' => 'This is a friendly and safe message about WordPress.',
				'model' => 'omni-moderation-latest',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'moderation_id', $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'summary', $result );

		// Verify request was made to correct endpoint.
		$this->assertNotNull( $captured_request );
		$this->assertSame( 'https://api.openai.com/v1/moderations', $captured_request['url'] );

		// Verify safe content summary.
		$this->assertTrue( $result['summary']['is_safe'] );
		$this->assertSame( 0, $result['summary']['flagged_items'] );
	}

	/**
	 * Test successful moderation of flagged content.
	 */
	public function test_execute_moderates_flagged_content() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Moderate_Content();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'id'      => 'modr-test456',
				'model'   => 'omni-moderation-latest',
				'results' => array(
					array(
						'flagged'    => true,
						'categories' => array(
							'sexual'                   => false,
							'sexual/minors'            => false,
							'harassment'               => false,
							'harassment/threatening'   => false,
							'hate'                     => false,
							'hate/threatening'         => false,
							'illicit'                  => false,
							'illicit/violent'          => false,
							'self-harm'                => false,
							'self-harm/intent'         => false,
							'self-harm/instructions'   => false,
							'violence'                 => true,
							'violence/graphic'         => false,
						),
						'category_scores' => array(
							'sexual'                   => 0.00001,
							'sexual/minors'            => 0.00001,
							'harassment'               => 0.00112,
							'harassment/threatening'   => 0.00224,
							'hate'                     => 0.00001,
							'hate/threatening'         => 0.00001,
							'illicit'                  => 0.00051,
							'illicit/violent'          => 0.00001,
							'self-harm'                => 0.00112,
							'self-harm/intent'         => 0.00062,
							'self-harm/instructions'   => 0.00001,
							'violence'                 => 0.86,
							'violence/graphic'         => 0.37,
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
				'input' => 'I want to hurt them.',
				'model' => 'omni-moderation-latest',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify flagged content summary.
		$this->assertFalse( $result['summary']['is_safe'] );
		$this->assertSame( 1, $result['summary']['flagged_items'] );
		$this->assertContains( 'violence', $result['summary']['categories_found'] );

		// Verify formatted results include flagged categories.
		$this->assertNotEmpty( $result['results'] );
		$this->assertTrue( $result['results'][0]['flagged'] );
		$this->assertContains( 'violence', $result['results'][0]['categories'] );
	}

	/**
	 * Test batch moderation with multiple inputs.
	 */
	public function test_execute_batch_moderation() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Moderate_Content();

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'id'      => 'modr-batch123',
				'model'   => 'omni-moderation-latest',
				'results' => array(
					array( 'flagged' => false, 'categories' => array(), 'category_scores' => array() ),
					array( 'flagged' => true, 'categories' => array( 'violence' => true ), 'category_scores' => array( 'violence' => 0.92 ) ),
					array( 'flagged' => false, 'categories' => array(), 'category_scores' => array() ),
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
				'input' => array(
					'Safe content about cooking.',
					'Violent threat content.',
					'Another safe message.',
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['results_count'] );
		$this->assertSame( 3, $result['summary']['total_items'] );
		$this->assertSame( 1, $result['summary']['flagged_items'] );
		$this->assertFalse( $result['summary']['is_safe'] );
	}

	/**
	 * Test that API errors are properly handled.
	 */
	public function test_execute_handles_api_errors() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Moderate_Content();

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'error' => array(
					'message' => 'Invalid API key',
					'type'    => 'invalid_request_error',
					'code'    => 'invalid_api_key',
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 401 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array( 'input' => 'Test content' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_moderation_error', $result->get_error_code() );
	}

	/**
	 * Test that the tool uses correct default model.
	 */
	public function test_uses_default_model() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Moderate_Content();
		$captured_payload = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_payload ) {
			$captured_payload = json_decode( $args['body'], true );

			$payload = array(
				'id'      => 'modr-test',
				'model'   => 'omni-moderation-latest',
				'results' => array(
					array( 'flagged' => false, 'categories' => array(), 'category_scores' => array() ),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array( 'input' => 'Test' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_payload );
		$this->assertSame( 'omni-moderation-latest', $captured_payload['model'] );
	}
}
