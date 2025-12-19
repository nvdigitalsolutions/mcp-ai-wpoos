<?php
/**
 * Tests for chat error logging helpers in the REST controller.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_REST_Chat_Error_Logging_Test extends WP_UnitTestCase {
	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	public function set_up(): void {
		parent::set_up();

		$registry = $this->createMock( WP_MCP_AI_Tool_Registry::class );
		$client   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );

		$this->rest_controller = new WP_MCP_AI_REST( $registry, $client );
	}

	public function test_build_chat_error_log_message_includes_rate_limit_details() {
		$error = new WP_Error(
			'wp_mcp_ai_api_error',
			'Rate limit reached',
			array(
				'status' => 429,
				'body'   => array(
					'error' => array(
						'message' => 'Rate limit reached.',
						'code'    => 'rate_limit_exceeded',
						'detail'  => array(
							'type'            => 'tokens',
							'limit_type'      => 'tokens',
							'scope'           => 'account',
							'rate_limit_unit' => 'tpm',
							'limit'           => 200000,
							'remaining'       => 0,
							'reset_seconds'   => 12,
						),
					),
				),
			)
		);

		$message_method = new ReflectionMethod( WP_MCP_AI_REST::class, 'build_chat_error_log_message' );
		$message_method->setAccessible( true );

		$message = $message_method->invoke( $this->rest_controller, $error );

		$this->assertSame(
			'Chat request failed due to token limits (TPM) being exceeded; OpenAI rate-limit response 429.',
			$message
		);

		$context_method = new ReflectionMethod( WP_MCP_AI_REST::class, 'extract_chat_error_log_context' );
		$context_method->setAccessible( true );

		$context = $context_method->invoke( $this->rest_controller, $error );

		$this->assertSame( 429, $context['http_status'] );
		$this->assertSame( 'TPM', $context['rate_limit_unit'] );
		$this->assertSame( 'tokens', $context['rate_limit_type'] );
		$this->assertSame( 'account', $context['rate_limit_scope'] );
		$this->assertSame( 200000, $context['rate_limit_limit'] );
		$this->assertSame( 0, $context['rate_limit_remaining'] );
		$this->assertSame( 12, $context['rate_limit_reset_seconds'] );
	}

	public function test_build_chat_error_log_message_defaults_for_non_rate_limit_errors() {
		$error = new WP_Error(
			'wp_mcp_ai_api_error',
			'Server error',
			array(
				'status' => 500,
			)
		);

		$message_method = new ReflectionMethod( WP_MCP_AI_REST::class, 'build_chat_error_log_message' );
		$message_method->setAccessible( true );

		$this->assertSame(
			'Chat request failed.',
			$message_method->invoke( $this->rest_controller, $error )
		);

		$context_method = new ReflectionMethod( WP_MCP_AI_REST::class, 'extract_chat_error_log_context' );
		$context_method->setAccessible( true );

		$context = $context_method->invoke( $this->rest_controller, $error );

		$this->assertSame( array( 'http_status' => 500 ), $context );
	}
}
