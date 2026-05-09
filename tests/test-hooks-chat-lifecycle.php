<?php
/**
 * Tests for chat request/response lifecycle hooks.
 *
 * Covers the hooks fired by the REST chat handler:
 *   - wp_mcp_ai_before_chat_request    (action)
 *   - wp_mcp_ai_after_chat_response    (action)
 *   - wp_mcp_ai_chat_options           (filter)
 *   - wp_mcp_ai_max_agentic_iterations (filter)
 *
 * Because the chat endpoint requires a live language-model client, these
 * tests exercise the hooks at the WordPress hook-system level (via
 * do_action / apply_filters) rather than through the full HTTP stack.
 * This mirrors how production code consumers attach to these hooks.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for chat lifecycle hooks.
 */
class Test_Hooks_Chat_Lifecycle extends WP_UnitTestCase {

	/**
	 * Simulated assistant post ID used in tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a published assistant post so tests have a valid assistant ID.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Chat Lifecycle Test Assistant',
			)
		);
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_post( $this->assistant_id, true );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_before_chat_request
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_before_chat_request fires with correct argument types.
	 */
	public function test_before_chat_request_fires_with_correct_argument_types() {
		// Arrange.
		$received_assistant_id = null;
		$received_messages     = null;
		$received_options      = null;
		$received_request      = null;

		$callback = function (
			$assistant_id,
			$messages,
			$options,
			$request
		) use (
			&$received_assistant_id,
			&$received_messages,
			&$received_options,
			&$received_request
		) {
			$received_assistant_id = $assistant_id;
			$received_messages     = $messages;
			$received_options      = $options;
			$received_request      = $request;
		};

		add_action( 'wp_mcp_ai_before_chat_request', $callback, 10, 4 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
		$options  = array(
			'model'       => 'gpt-4',
			'temperature' => 0.7,
		);
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		// Act.
		do_action( 'wp_mcp_ai_before_chat_request', $this->assistant_id, $messages, $options, $request );

		remove_action( 'wp_mcp_ai_before_chat_request', $callback, 10 );

		// Assert.
		$this->assertIsInt( $received_assistant_id );
		$this->assertSame( $this->assistant_id, $received_assistant_id );
		$this->assertIsArray( $received_messages );
		$this->assertCount( 1, $received_messages );
		$this->assertSame( 'user', $received_messages[0]['role'] );
		$this->assertIsArray( $received_options );
		$this->assertInstanceOf( 'WP_REST_Request', $received_request );
	}

	/**
	 * Test that wp_mcp_ai_before_chat_request passes the messages array intact.
	 */
	public function test_before_chat_request_passes_full_message_history() {
		// Arrange.
		$received_messages = null;

		$callback = function ( $assistant_id, $messages ) use ( &$received_messages ) {
			$received_messages = $messages;
		};

		add_action( 'wp_mcp_ai_before_chat_request', $callback, 10, 4 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Tell me something.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Sure!',
			),
		);

		// Act.
		do_action( 'wp_mcp_ai_before_chat_request', $this->assistant_id, $messages, array(), null );

		remove_action( 'wp_mcp_ai_before_chat_request', $callback, 10 );

		// Assert.
		$this->assertIsArray( $received_messages );
		$this->assertCount( 3, $received_messages );
		$this->assertSame( 'system', $received_messages[0]['role'] );
		$this->assertSame( 'assistant', $received_messages[2]['role'] );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_chat_options
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_chat_options filter passes options through unmodified when no filter is attached.
	 */
	public function test_chat_options_filter_passes_through_by_default() {
		// Arrange.
		$options = array(
			'model'      => 'gpt-4',
			'max_tokens' => 1000,
		);

		// Act.
		$result = apply_filters( 'wp_mcp_ai_chat_options', $options, array(), new WP_REST_Request() );

		// Assert.
		$this->assertSame( $options, $result );
	}

	/**
	 * Test that wp_mcp_ai_chat_options filter can modify the options array.
	 */
	public function test_chat_options_filter_can_add_field() {
		// Arrange.
		$original_options = array(
			'model'       => 'gpt-4',
			'temperature' => 0.5,
		);

		$filter = function ( $options, $config, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$options['max_tokens']  = 500;
			$options['temperature'] = 0.9; // Override.
			return $options;
		};

		add_filter( 'wp_mcp_ai_chat_options', $filter, 10, 3 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_chat_options', $original_options, array(), new WP_REST_Request() );

		remove_filter( 'wp_mcp_ai_chat_options', $filter, 10 );

		// Assert.
		$this->assertArrayHasKey( 'max_tokens', $result );
		$this->assertSame( 500, $result['max_tokens'] );
		$this->assertSame( 0.9, $result['temperature'] );
		$this->assertSame( 'gpt-4', $result['model'] );
	}

	/**
	 * Test that wp_mcp_ai_chat_options filter receives the assistant config.
	 */
	public function test_chat_options_filter_receives_assistant_config() {
		// Arrange.
		$received_config = null;

		$filter = function ( $options, $config, $request ) use ( &$received_config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$received_config = $config;
			return $options;
		};

		add_filter( 'wp_mcp_ai_chat_options', $filter, 10, 3 );

		$assistant_config = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		// Act.
		apply_filters( 'wp_mcp_ai_chat_options', array(), $assistant_config, new WP_REST_Request() );

		remove_filter( 'wp_mcp_ai_chat_options', $filter, 10 );

		// Assert.
		$this->assertIsArray( $received_config );
		$this->assertSame( 'openai', $received_config['provider'] );
	}

	/**
	 * Test that wp_mcp_ai_chat_options filter can remove a field.
	 */
	public function test_chat_options_filter_can_remove_field() {
		// Arrange.
		$original_options = array(
			'model'        => 'gpt-4',
			'stream'       => true,
			'unsafe_field' => 'bad_value',
		);

		$filter = function ( $options ) {
			unset( $options['unsafe_field'] );
			return $options;
		};

		add_filter( 'wp_mcp_ai_chat_options', $filter, 10, 3 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_chat_options', $original_options, array(), new WP_REST_Request() );

		remove_filter( 'wp_mcp_ai_chat_options', $filter, 10 );

		// Assert.
		$this->assertArrayNotHasKey( 'unsafe_field', $result );
		$this->assertArrayHasKey( 'model', $result );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_max_agentic_iterations
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_max_agentic_iterations filter is applied.
	 */
	public function test_max_agentic_iterations_filter_is_applied() {
		// Arrange — baseline value mirrors what the REST class sets before filtering.
		$base_value = 1;

		// Act — no filter attached, should return unmodified.
		$result = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', $base_value, array() );

		// Assert.
		$this->assertSame( $base_value, $result );
	}

	/**
	 * Test that wp_mcp_ai_max_agentic_iterations filter can increase the limit.
	 */
	public function test_max_agentic_iterations_filter_can_increase_limit() {
		// Arrange.
		$base_value = 1;

		$filter = function ( $max, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return 10;
		};

		add_filter( 'wp_mcp_ai_max_agentic_iterations', $filter, 10, 2 );

		// Act.
		$result = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', $base_value, array() );

		remove_filter( 'wp_mcp_ai_max_agentic_iterations', $filter, 10 );

		// Assert.
		$this->assertSame( 10, $result );
		$this->assertGreaterThan( $base_value, $result );
	}

	/**
	 * Test that wp_mcp_ai_max_agentic_iterations filter can set a custom value based on config.
	 */
	public function test_max_agentic_iterations_filter_receives_assistant_config() {
		// Arrange.
		$received_config = null;

		$filter = function ( $max, $config ) use ( &$received_config ) {
			$received_config = $config;
			return $max;
		};

		add_filter( 'wp_mcp_ai_max_agentic_iterations', $filter, 10, 2 );

		$config = array(
			'provider'       => 'openai',
			'max_iterations' => 5,
		);

		// Act.
		apply_filters( 'wp_mcp_ai_max_agentic_iterations', 1, $config );

		remove_filter( 'wp_mcp_ai_max_agentic_iterations', $filter, 10 );

		// Assert.
		$this->assertIsArray( $received_config );
		$this->assertSame( 'openai', $received_config['provider'] );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_after_chat_response
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_after_chat_response fires with an assistant_id and response.
	 */
	public function test_after_chat_response_fires_with_assistant_id_and_response() {
		// Arrange.
		$received_assistant_id = null;
		$received_response     = null;
		$received_request      = null;

		$callback = function (
			$assistant_id,
			$response,
			$request
		) use (
			&$received_assistant_id,
			&$received_response,
			&$received_request
		) {
			$received_assistant_id = $assistant_id;
			$received_response     = $response;
			$received_request      = $request;
		};

		add_action( 'wp_mcp_ai_after_chat_response', $callback, 10, 3 );

		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
					),
				),
			),
		);
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		// Act.
		do_action( 'wp_mcp_ai_after_chat_response', $this->assistant_id, $response, $request );

		remove_action( 'wp_mcp_ai_after_chat_response', $callback, 10 );

		// Assert.
		$this->assertSame( $this->assistant_id, $received_assistant_id );
		$this->assertIsArray( $received_response );
		$this->assertArrayHasKey( 'choices', $received_response );
		$this->assertInstanceOf( 'WP_REST_Request', $received_request );
	}

	/**
	 * Test that wp_mcp_ai_after_chat_response fires with integer assistant_id.
	 */
	public function test_after_chat_response_assistant_id_is_integer() {
		// Arrange.
		$received_id = null;

		$callback = function ( $assistant_id ) use ( &$received_id ) {
			$received_id = $assistant_id;
		};

		add_action( 'wp_mcp_ai_after_chat_response', $callback, 10, 3 );

		// Act.
		do_action( 'wp_mcp_ai_after_chat_response', (int) $this->assistant_id, array(), null );

		remove_action( 'wp_mcp_ai_after_chat_response', $callback, 10 );

		// Assert.
		$this->assertIsInt( $received_id );
		$this->assertGreaterThan( 0, $received_id );
	}

	/**
	 * Test that multiple before_chat_request callbacks are all invoked.
	 */
	public function test_before_chat_request_multiple_callbacks_all_fire() {
		// Arrange.
		$fired_callbacks = array();

		$cb1 = function () use ( &$fired_callbacks ) {
			$fired_callbacks[] = 'cb1';
		};
		$cb2 = function () use ( &$fired_callbacks ) {
			$fired_callbacks[] = 'cb2';
		};

		add_action( 'wp_mcp_ai_before_chat_request', $cb1, 5, 4 );
		add_action( 'wp_mcp_ai_before_chat_request', $cb2, 15, 4 );

		// Act.
		do_action( 'wp_mcp_ai_before_chat_request', 1, array(), array(), new WP_REST_Request() );

		remove_action( 'wp_mcp_ai_before_chat_request', $cb1, 5 );
		remove_action( 'wp_mcp_ai_before_chat_request', $cb2, 15 );

		// Assert.
		$this->assertContains( 'cb1', $fired_callbacks );
		$this->assertContains( 'cb2', $fired_callbacks );
		$this->assertCount( 2, $fired_callbacks );
	}
}
