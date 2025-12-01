<?php
/**
 * tests/test-openai-external-action-tool.php
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the OpenAI external action tool.
 */
class WP_MCP_AI_OpenAI_External_Action_Tool_Test extends WP_UnitTestCase {

	/**
	 * Reset global state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Ensure the tool honours the configured request timeout setting.
	 */
	public function test_execute_honours_custom_request_timeout_setting() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 45;

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Run_OpenAI_External_Action();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = $args;

			return array(
				'body'     => wp_json_encode(
					array(
						'id'     => 'action_123',
						'status' => 'completed',
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'action_type' => 'workflow',
				'identifier'  => 'wf_123',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'action_123', $result['id'] );
		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'timeout', $captured_request );
		$this->assertSame( 45, $captured_request['timeout'] );
	}

	/**
	 * Ensure input variable sanitisation preserves casing and removes disallowed keys.
	 */
	public function test_sanitize_input_variables_preserves_key_casing() {
		$tool   = new WP_MCP_AI_Tool_Run_OpenAI_External_Action();
		$method = new ReflectionMethod( $tool, 'sanitize_input_variables' );
		$method->setAccessible( true );

		$variables = array(
			'customerId'   => ' 12345 ',
			'Order-Ref'    => "Order\n123",
			'invalid key!' => 'value',
			7              => 'keep-int',
		);

		$sanitised = $method->invoke( $tool, $variables );

		$this->assertArrayHasKey( 'customerId', $sanitised );
		$this->assertArrayHasKey( 'Order-Ref', $sanitised );
		$this->assertArrayNotHasKey( 'invalid key!', $sanitised );
		$this->assertArrayHasKey( 7, $sanitised );
		$this->assertSame( '12345', $sanitised['customerId'] );
		$this->assertSame( 'Order 123', $sanitised['Order-Ref'] );
		$this->assertSame( 'keep-int', $sanitised[7] );
	}
}
