<?php
/**
 * Tests for request_user_approval tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test request_user_approval tool functionality.
 */
class Test_Tool_Request_User_Approval extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Request_User_Approval
	 */
	private $tool;

	/**
	 * Editor user ID — has edit_posts capability.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user ID — lacks edit_posts capability.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Defensive loads — no-ops if already required by the plugin bootstrap.
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-approval-queue.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-request-user-approval.php';

		// Register the mcp_ai_approval CPT so wp_insert_post can create records.
		WP_MCP_AI_Approval_Queue::register_cpt();

		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->editor_id );

		$this->tool = new WP_MCP_AI_Tool_Request_User_Approval();
	}

	// ── Metadata ─────────────────────────────────────────────────────────────

	/**
	 * Slug, name, and description are populated.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'request_user_approval', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	// ── Schema ───────────────────────────────────────────────────────────────

	/**
	 * Parameters schema includes the required fields and their properties.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Required parameters.
		$this->assertContains( 'tool_to_approve', $schema['required'] );
		$this->assertContains( 'reason', $schema['required'] );

		// Property presence.
		$this->assertArrayHasKey( 'tool_to_approve', $schema['properties'] );
		$this->assertArrayHasKey( 'reason', $schema['properties'] );
		$this->assertArrayHasKey( 'arguments', $schema['properties'] );
		$this->assertArrayHasKey( 'session_id', $schema['properties'] );
		$this->assertArrayHasKey( 'assistant_id', $schema['properties'] );
		$this->assertArrayHasKey( 'ttl', $schema['properties'] );

		// The 'arguments' parameter is an object — no items key required.
		$this->assertSame( 'object', $schema['properties']['arguments']['type'] );

		// Numeric types.
		$this->assertSame( 'integer', $schema['properties']['assistant_id']['type'] );
		$this->assertSame( 'integer', $schema['properties']['ttl']['type'] );
	}

	// ── Capability flags ──────────────────────────────────────────────────────

	/**
	 * Capability flags must include write, state-changing, and requires-approval.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'requires-approval', $flags );
	}

	// ── Interface compliance ──────────────────────────────────────────────────

	/**
	 * Tool must implement both required interfaces.
	 */
	public function test_implements_interfaces() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $this->tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}

	// ── Permission checks ─────────────────────────────────────────────────────

	/**
	 * A subscriber (no edit_posts) without guest context must be refused.
	 */
	public function test_forbidden_for_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'About to delete a post.',
			),
			array()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * A guest_request WITHOUT assistant_id in context must still be refused.
	 */
	public function test_guest_request_without_assistant_id_is_blocked() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'About to delete a post.',
			),
			array(
				'guest_request' => true,
				// No assistant_id supplied.
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * A guest_request WITH assistant_id in context must bypass the capability check.
	 */
	public function test_guest_request_with_assistant_id_is_allowed() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'About to delete a post.',
			),
			array(
				'guest_request' => true,
				'assistant_id'  => 1,
			)
		);

		// Must not fail on permission — may succeed or fail for other reasons.
		$this->assertNotEquals( 'forbidden', is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	// ── Input validation ──────────────────────────────────────────────────────

	/**
	 * An empty tool_to_approve argument returns a missing_tool error.
	 */
	public function test_missing_tool_to_approve_returns_error() {
		$result = $this->tool->execute(
			array(
				'reason' => 'About to delete a post.',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_tool', $result->get_error_code() );
	}

	/**
	 * An empty reason argument returns a missing_reason error.
	 */
	public function test_missing_reason_returns_error() {
		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_reason', $result->get_error_code() );
	}

	// ── Happy path ────────────────────────────────────────────────────────────

	/**
	 * A valid call returns a pending-approval descriptor with the correct shape.
	 */
	public function test_happy_path_returns_pending_result() {
		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'The user asked me to delete post 42.',
				'arguments'       => array( 'post_id' => 42 ),
				'session_id'      => 'sess-abc123',
			),
			array( 'user_id' => $this->editor_id, 'assistant_id' => 5 )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertArrayHasKey( 'approval_id', $result );
		$this->assertIsInt( $result['approval_id'] );
		$this->assertGreaterThan( 0, $result['approval_id'] );
		$this->assertSame( 'delete_post', $result['tool'] );
		$this->assertNotEmpty( $result['message'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * A successful call creates an mcp_ai_approval post with pending status.
	 */
	public function test_creates_pending_approval_post() {
		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'Checking DB record creation.',
				'arguments'       => array( 'post_id' => 99 ),
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$approval_id = $result['approval_id'];
		$post = get_post( $approval_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( WP_MCP_AI_Approval_Queue::CPT, $post->post_type );
		$this->assertSame( 'pending', $post->post_status );

		// Check meta.
		$stored_tool = get_post_meta( $approval_id, WP_MCP_AI_Approval_Queue::META_TOOL, true );
		$this->assertSame( 'delete_post', $stored_tool );

		$stored_args = json_decode(
			get_post_meta( $approval_id, WP_MCP_AI_Approval_Queue::META_ARGUMENTS, true ),
			true
		);
		$this->assertIsArray( $stored_args );
		$this->assertSame( 99, $stored_args['post_id'] );
	}

	/**
	 * A successful call fires the wp_mcp_ai_approval_request_emitted action.
	 */
	public function test_action_hook_fired_on_success() {
		$fired_ids  = array();
		$fired_tool = '';

		add_action(
			'wp_mcp_ai_approval_request_emitted',
			function ( $approval_id, $tool_to_approve ) use ( &$fired_ids, &$fired_tool ) {
				$fired_ids[]  = $approval_id;
				$fired_tool   = $tool_to_approve;
			},
			10,
			2
		);

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'publish_post',
				'reason'          => 'About to publish.',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $fired_ids );
		$this->assertSame( $result['approval_id'], $fired_ids[0] );
		$this->assertSame( 'publish_post', $fired_tool );
	}

	/**
	 * TTL defaults to DEFAULT_TTL_SECONDS when not supplied.
	 */
	public function test_default_ttl_applied() {
		$before = time();

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'Testing TTL default.',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );

		$expires_at = (int) get_post_meta(
			$result['approval_id'],
			WP_MCP_AI_Approval_Queue::META_EXPIRES,
			true
		);

		$expected_min = $before + WP_MCP_AI_Approval_Queue::DEFAULT_TTL_SECONDS;
		$this->assertGreaterThanOrEqual( $expected_min, $expires_at );
	}

	/**
	 * TTL below the 60-second minimum is clamped to 60.
	 */
	public function test_minimum_ttl_clamped() {
		$before = time();

		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'Testing TTL minimum.',
				'ttl'             => 5, // below minimum.
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );

		$expires_at = (int) get_post_meta(
			$result['approval_id'],
			WP_MCP_AI_Approval_Queue::META_EXPIRES,
			true
		);

		// Should be clamped to 60 seconds, not 5.
		$this->assertGreaterThanOrEqual( $before + 60, $expires_at );
		// Should not be set to the default (86400) either.
		$this->assertLessThan( $before + WP_MCP_AI_Approval_Queue::DEFAULT_TTL_SECONDS, $expires_at );
	}

	/**
	 * assistant_id in context is stored when not provided in arguments.
	 */
	public function test_assistant_id_inferred_from_context() {
		$result = $this->tool->execute(
			array(
				'tool_to_approve' => 'delete_post',
				'reason'          => 'Testing assistant inference.',
			),
			array( 'user_id' => $this->editor_id, 'assistant_id' => 99 )
		);

		$this->assertIsArray( $result );

		$stored_assistant = (int) get_post_meta(
			$result['approval_id'],
			WP_MCP_AI_Approval_Queue::META_ASSISTANT,
			true
		);

		$this->assertSame( 99, $stored_assistant );
	}
}
