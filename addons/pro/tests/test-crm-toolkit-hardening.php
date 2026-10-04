<?php
/**
 * Tests for CRM toolkit hardening (provider response flattening + live AI path).
 *
 * Regression coverage for two production fixes in the CRM toolkit:
 *
 *  1. draft_upwork_proposal fed `message.content` straight into trim() —
 *     Gemini (and OpenAI-compatible gateways such as vLLM) return that
 *     field as an array of parts, causing the fatal
 *     `trim(): Argument #1 ($value) must be of type string, array given`.
 *
 *  2. draft_lead_reply called the nonexistent global helper
 *     wp_mcp_ai_chat_completion(), so its AI path could never run (every
 *     call silently fell back to the template). Rewritten around the
 *     OpenAI / Gemini / Anthropic provider clients with the same
 *     flattening discipline.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * CRM toolkit hardening test case.
 */
class Test_CRM_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Draft Upwork Proposal tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Draft_Upwork_Proposal
	 */
	private $upwork_tool;

	/**
	 * Draft Lead Reply tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Draft_Lead_Reply
	 */
	private $reply_tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-draft-upwork-proposal.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-draft-lead-reply.php';

		$this->upwork_tool = new WP_MCP_AI_Tool_Draft_Upwork_Proposal();
		$this->reply_tool  = new WP_MCP_AI_Tool_Draft_Lead_Reply();

		// Neutralise environment-provided keys so provider selection is
		// driven solely by the wp_mcp_ai_settings option in these tests.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'OPENAI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'GEMINI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'ANTHROPIC_API_KEY' );

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_credentials' );
		update_option( 'wp_mcp_ai_settings', array() );
	}

	/**
	 * Build a mocked HTTP response carrying Gemini array-of-parts content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function gemini_parts_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array(
								'parts' => array(
									array(
										'text' => 'Part one.',
									),
									array(
										'text' => 'Part two.',
									),
								),
							),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Build a mocked HTTP response carrying OpenAI string content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_string_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'      => 'chatcmpl-test',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'gpt-4.1',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Plain text content.',
							),
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 5,
						'total_tokens'      => 15,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Invoke a private/protected method on an object.
	 *
	 * @param object $target    Target object.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( $target, $method, array $arguments ) {
		$reflection = new ReflectionClass( $target );
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invokeArgs( $target, $arguments );
	}

	/**
	 * The Upwork proposal generator must flatten Gemini array-of-parts
	 * content instead of fataling in trim().
	 */
	public function test_draft_upwork_proposal_flattens_gemini_array_content() {
		update_option( 'wp_mcp_ai_settings', array( 'gemini_api_key' => 'gsk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->upwork_tool, 'generate_proposal', array( 'Draft a proposal for a test job.' ) );

		remove_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Part one.Part two.', $result );
	}

	/**
	 * The Upwork proposal generator must pass OpenAI string content through.
	 */
	public function test_draft_upwork_proposal_passes_string_content() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->upwork_tool, 'generate_proposal', array( 'Draft a proposal for a test job.' ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Plain text content.', $result );
	}

	/**
	 * The Upwork proposal flattening helper must handle every provider shape.
	 */
	public function test_draft_upwork_proposal_flatten_handles_shapes() {
		// String content (OpenAI-style).
		$this->assertSame( 'Plain text.', $this->invoke_private( $this->upwork_tool, 'flatten_response_content', array( 'Plain text.' ) ) );

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_private(
				$this->upwork_tool,
				'flatten_response_content',
				array(
					array(
						array(
							'type' => 'text',
							'text' => 'Part one.',
						),
						array(
							'type' => 'text',
							'text' => 'Part two.',
						),
					),
				)
			)
		);

		// Array-of-strings content.
		$this->assertSame(
			'AB',
			$this->invoke_private( $this->upwork_tool, 'flatten_response_content', array( array( 'A', 'B' ) ) )
		);

		// Non-string / non-array content yields ''.
		$this->assertSame( '', $this->invoke_private( $this->upwork_tool, 'flatten_response_content', array( null ) ) );
		$this->assertSame( '', $this->invoke_private( $this->upwork_tool, 'flatten_response_content', array( 42 ) ) );
	}

	/**
	 * The lead-reply AI draft path must now reach a real provider client and
	 * flatten Gemini array-of-parts content (regression: it previously called
	 * the nonexistent wp_mcp_ai_chat_completion() helper and could never run).
	 */
	public function test_draft_lead_reply_flattens_gemini_array_content() {
		update_option( 'wp_mcp_ai_settings', array( 'gemini_api_key' => 'gsk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10, 3 );

		$result = $this->invoke_private(
			$this->reply_tool,
			'generate_ai_draft',
			array( 'We need a demo of your product.', 'professional', 'email', '' )
		);

		remove_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Part one.Part two.', $result );
	}

	/**
	 * The lead-reply AI draft path must pass OpenAI string content through.
	 */
	public function test_draft_lead_reply_uses_openai_string_content() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10, 3 );

		$result = $this->invoke_private(
			$this->reply_tool,
			'generate_ai_draft',
			array( 'We need a demo of your product.', 'professional', 'email', '' )
		);

		remove_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Plain text content.', $result );
	}

	/**
	 * Without credentials the lead-reply tool must fall back to the template
	 * draft (existing behavior preserved by the rewrite).
	 */
	public function test_draft_lead_reply_falls_back_to_template_without_credentials() {
		$result = $this->reply_tool->execute(
			array(
				'incoming_message' => 'We need a demo of your product.',
				'tone'             => 'concise',
				'channel'          => 'email',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'template', $result['powered_by'] );
		$this->assertNotEmpty( $result['draft'] );
	}

	/**
	 * The lead-reply flattening helper must handle every provider shape.
	 */
	public function test_draft_lead_reply_flatten_handles_shapes() {
		$this->assertSame( 'Plain text.', $this->invoke_private( $this->reply_tool, 'flatten_response_content', array( 'Plain text.' ) ) );

		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_private(
				$this->reply_tool,
				'flatten_response_content',
				array(
					array(
						array(
							'type' => 'text',
							'text' => 'Part one.',
						),
						array(
							'type' => 'text',
							'text' => 'Part two.',
						),
					),
				)
			)
		);

		$this->assertSame( '', $this->invoke_private( $this->reply_tool, 'flatten_response_content', array( null ) ) );
	}
}
