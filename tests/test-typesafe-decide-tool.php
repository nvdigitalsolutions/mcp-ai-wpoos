<?php
/**
 * Tests for the typesafe_decide tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the TypeSafe Decide tool.
 */
class Test_Typesafe_Decide_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Typesafe_Decide
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-typesafe-decide.php';

		$this->tool = new WP_MCP_AI_Tool_Typesafe_Decide();

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Helper: create an admin user and set it as current.
	 *
	 * @return int User ID.
	 */
	private function set_admin_user() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'typesafe_decide', $this->tool->get_slug() );
		// The declared capability must match the gate enforced in execute().
		$this->assertEquals( 'manage_options', $this->tool->get_required_capability() );
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test schema declares state, questions, model, and transport.
	 */
	public function test_schema_declares_expected_properties() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'state', $schema['properties'] );
		$this->assertArrayHasKey( 'questions', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'transport', $schema['properties'] );
		$this->assertEquals( array( 'typesafe', 'openrouter' ), $schema['properties']['transport']['enum'] );
		$this->assertEquals( array( 'state', 'questions' ), $schema['required'] );
	}

	/**
	 * Test execute() rejects non-admin users.
	 */
	public function test_execute_rejects_non_admin() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->tool->execute(
			array(
				'state'     => 'test',
				'questions' => array(
					'q' => array(
						'type'         => 'noul',
						'instructions' => 'Yes?',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute() requires state and questions.
	 */
	public function test_execute_requires_state_and_questions() {
		$user_id = $this->set_admin_user();

		$result = $this->tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_arguments', $result->get_error_code() );
	}

	/**
	 * Test execute() rejects invalid question types.
	 */
	public function test_execute_rejects_invalid_question_type() {
		$user_id = $this->set_admin_user();
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test' ) );

		$result = $this->tool->execute(
			array(
				'state'     => 'test',
				'questions' => array(
					'q' => array(
						'type'         => 'essay',
						'instructions' => 'Write.',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_question_type', $result->get_error_code() );
	}

	/**
	 * Test execute() sanitises question strings at entry (two-gate rule, gate one).
	 */
	public function test_execute_sanitises_question_strings_at_entry() {
		$user_id = $this->set_admin_user();
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test' ) );

		$captured_body = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-latest',
							'answers' => array( 'q' => array( 'noul' => 0.9 ) ),
						)
					),
				);
			},
			10,
			2
		);

		$result = $this->tool->execute(
			array(
				'state'     => 'Message with <script>alert(1)</script> content.',
				'questions' => array(
					'q' => array(
						'type'         => 'choice',
						'instructions' => 'Which team <b>handles</b> this?',
						'criteria'     => array(
							'billing'   => 'Payment <script>issues</script>',
							'technical' => 'Bugs',
						),
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );

		// sanitize_text_field strips <script> elements TOGETHER with their
		// content (WP core behaviour), so the payload must contain neither
		// the element nor its payload, while surrounding text survives.
		$this->assertStringContainsString( 'Message with', $captured_body['state'] );
		$this->assertStringContainsString( 'content.', $captured_body['state'] );
		$this->assertStringNotContainsString( '<script>', $captured_body['state'] );
		$this->assertStringContainsString( 'handles', $captured_body['questions']['q']['instructions'] );
		$this->assertStringNotContainsString( '<b>', $captured_body['questions']['q']['instructions'] );
		$this->assertStringNotContainsString( '<script>', $captured_body['questions']['q']['criteria']['billing'] );
		$this->assertStringNotContainsString( 'issues', $captured_body['questions']['q']['criteria']['billing'] );
	}

	/**
	 * Test execute() returns the canonical success envelope.
	 */
	public function test_execute_returns_canonical_success_envelope() {
		$user_id = $this->set_admin_user();
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test' ) );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => array( 'q' => array( 'noul' => 0.83 ) ),
							'usage'   => array( 'input_tokens' => 64 ),
						)
					),
				);
			},
			10
		);

		$result = $this->tool->execute(
			array(
				'state'     => 'test',
				'questions' => array(
					'q' => array(
						'type'         => 'noul',
						'instructions' => 'Urgent?',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'jev-1.13.0', $result['model'] );
		$this->assertEquals( 'typesafe', $result['provider'] );
		$this->assertArrayHasKey( 'q', $result['answers'] );
		$this->assertEquals( 64, $result['usage']['input_tokens'] );
	}

	/**
	 * Test execute() propagates client WP_Errors.
	 */
	public function test_execute_propagates_client_errors() {
		$user_id = $this->set_admin_user();
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->tool->execute(
			array(
				'state'     => 'test',
				'questions' => array(
					'q' => array(
						'type'         => 'noul',
						'instructions' => 'Urgent?',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_typesafe_api_key', $result->get_error_code() );
	}

	/**
	 * Test execute() routes the openrouter transport to the decisions endpoint.
	 */
	public function test_execute_openrouter_transport_hits_decisions_endpoint() {
		$user_id = $this->set_admin_user();
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-test' ) );

		$captured_url = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'typesafe/jev-1.13',
							'answers' => array(
								'q' => array(
									'choice'        => 'billing',
									'probabilities' => array( 'billing' => 0.9 ),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'state'     => 'test',
				'questions' => array(
					'q' => array(
						'type'         => 'choice',
						'instructions' => 'Team?',
						'criteria'     => array( 'billing' => 'Billing' ),
					),
				),
				'transport' => 'openrouter',
			),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'openrouter', $result['provider'] );
		$this->assertStringContainsString( 'decisions', $captured_url );
	}
}
