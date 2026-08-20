<?php
/**
 * Tests for MemPalace-inspired Phase 2 memory tools:
 *   - mine_agent_memory
 *   - wake_up_context
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

/**
 * Test case for the Phase 2 memory tools.
 *
 * @since 1.1.0
 */
class Test_MemPalace_Phase2_Memory_Tools extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// The wp-phpunit framework resets the current user to 0 after each
		// test, and the bootstrap's one-shot admin is not restored. Create a
		// fresh authenticated admin per test so tools like store_agent_context
		// (which checks `user_can( $user_id, 'read' )`) don't fail with
		// `wp_mcp_ai_forbidden` for every test after the first.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%'
			)
		);

		remove_all_filters( 'wp_mcp_ai_wake_up_top_n' );
		remove_all_filters( 'wp_mcp_ai_wake_up_token_budget' );
		remove_all_filters( 'wp_mcp_ai_wake_up_system_block' );

		parent::tearDown();
	}

	// --- mine_agent_memory ---

	/**
	 * The tool should be registered and available.
	 */
	public function test_mine_agent_memory_tool_registered() {
		$tool = $this->registry->get_tool( 'mine_agent_memory' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Mine_Agent_Memory', $tool );
	}

	/**
	 * Mining from the "text" source should create one verbatim record per item
	 * scoped to the requested wing/room.
	 */
	public function test_mine_from_text_source_creates_records() {
		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id' => 9001,
				'source'   => 'text',
				'wing'     => 'client-acme',
				'room'     => 'onboarding',
				'tags'     => array( 'mined' ),
				'items'    => array(
					array(
						'title'   => 'Welcome message',
						'content' => 'Hi! Thanks for choosing Acme.',
						'tags'    => array( 'greeting' ),
					),
					array(
						'title'   => 'Activation flow',
						'content' => 'Customers receive an activation link valid for 24h.',
					),
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 'client-acme', $result['wing'] );
		$this->assertSame( 'onboarding', $result['room'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute(
			array(
				'agent_id' => 9001,
				'filters'  => array( 'wing' => 'client-acme' ),
			),
			array()
		);
		$this->assertTrue( $lookup['success'] );
		$this->assertSame( 2, $lookup['count'] );
		foreach ( $lookup['contexts'] as $ctx ) {
			$this->assertSame( 'client-acme', $ctx['wing'] );
			$this->assertSame( 'onboarding', $ctx['room'] );
			$this->assertTrue( $ctx['verbatim'], 'Mined records default to verbatim=true' );
			$this->assertContains( 'mined', $ctx['tags'] );
		}
	}

	/**
	 * Long content should be split into multiple chunked records sharing the
	 * same base title.
	 */
	public function test_mine_chunks_long_content() {
		$tool      = $this->registry->get_tool( 'mine_agent_memory' );
		$long_text = str_repeat( 'word ', 2000 ); // ~10000 chars.

		$result = $tool->execute(
			array(
				'agent_id'   => 9002,
				'source'     => 'text',
				'chunk_size' => 1000,
				'items'      => array(
					array(
						'title'   => 'Long doc',
						'content' => $long_text,
					),
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 1, $result['count'], 'Expected multiple chunks' );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute( array( 'agent_id' => 9002 ), array() );

		// Every chunk title should reference "Long doc".
		foreach ( $lookup['contexts'] as $ctx ) {
			$this->assertStringContainsString( 'Long doc', $ctx['title'] );
		}
	}

	/**
	 * Dry_run should plan without writing.
	 */
	public function test_mine_dry_run_does_not_write() {
		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id' => 9003,
				'source'   => 'text',
				'dry_run'  => true,
				'items'    => array(
					array(
						'title'   => 'Trial',
						'content' => 'noop',
					),
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['count'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute( array( 'agent_id' => 9003 ), array() );
		$this->assertSame( 0, $lookup['count'], 'Dry run must not persist anything' );
	}

	/**
	 * Mining from "posts" should pull WP posts via WP_Query.
	 */
	public function test_mine_from_posts_source() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Wake-up source',
				'post_content' => 'Hello world from a post.',
				'post_status'  => 'publish',
			)
		);

		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id'   => 9004,
				'source'     => 'posts',
				'wing'       => 'site-content',
				'post_query' => array(
					'post_type'      => 'post',
					'posts_per_page' => 5,
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 1, $result['count'] );
		$titles = wp_list_pluck( $result['mined'], 'title' );
		$this->assertNotEmpty(
			array_filter(
				$titles,
				static function ( $t ) {
					return false !== strpos( $t, 'Wake-up source' );
				}
			)
		);

		// Cleanup.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Unsupported source should produce a structured error.
	 */
	public function test_mine_unsupported_source() {
		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id' => 9005,
				'source'   => 'invalid',
			),
			array()
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_error', $result->get_error_code() );
	}

	// --- wake_up_context ---

	/**
	 * The tool should be registered and available.
	 */
	public function test_wake_up_context_tool_registered() {
		$tool = $this->registry->get_tool( 'wake_up_context' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Wake_Up_Context', $tool );
	}

	/**
	 * Wake-up should return an empty block when no memories exist for the agent.
	 */
	public function test_wake_up_returns_empty_when_no_memory() {
		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute(
			array(
				'agent_id' => 9100,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', $result['system_block'] );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * Wake-up should render a labeled block scoped to the requested wing.
	 */
	public function test_wake_up_renders_block_scoped_to_wing() {
		// Seed memories: two in wing A, one in wing B.
		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 9101,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Acme deploy day',
					'content'    => 'Deploys on Tuesdays.',
					'importance' => 'high',
				),
				'wing'         => 'client-acme',
			),
			array()
		);
		$store->execute(
			array(
				'agent_id'     => 9101,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Acme contact',
					'content'    => 'Primary contact is Jane Doe.',
					'importance' => 'medium',
				),
				'wing'         => 'client-acme',
			),
			array()
		);
		$store->execute(
			array(
				'agent_id'     => 9101,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Globex secret',
					'content'    => 'Should NOT appear in Acme wake-up.',
					'importance' => 'critical',
				),
				'wing'         => 'client-globex',
			),
			array()
		);

		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute(
			array(
				'agent_id' => 9101,
				'wing'     => 'client-acme',
				'top_n'    => 5,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertStringContainsString( 'Persistent Memory', $result['system_block'] );
		$this->assertStringContainsString( 'Acme deploy day', $result['system_block'] );
		$this->assertStringContainsString( 'Acme contact', $result['system_block'] );
		$this->assertStringNotContainsString( 'Globex secret', $result['system_block'] );
	}

	/**
	 * Token-budget pruning should drop overflow records and report truncation.
	 */
	public function test_wake_up_respects_token_budget() {
		$store = $this->registry->get_tool( 'store_agent_context' );
		for ( $i = 0; $i < 6; $i++ ) {
			$store->execute(
				array(
					'agent_id'     => 9102,
					'context_type' => 'note',
					'context_data' => array(
						'title'      => 'Memory ' . $i,
						'content'    => str_repeat( 'long content payload ', 50 ),
						'importance' => 'medium',
					),
				),
				array()
			);
		}

		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute(
			array(
				'agent_id'     => 9102,
				'top_n'        => 6,
				'token_budget' => 200,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertLessThan( 6, $result['count'], 'Some records should be pruned' );
		$this->assertGreaterThan( 0, $result['truncated'] );
		$this->assertLessThanOrEqual( 200 + 50, $result['tokens_used'], 'Tokens used should approximate the budget' );
	}

	/**
	 * Include_content=false should keep the rendered block compact.
	 */
	public function test_wake_up_include_content_false_omits_body() {
		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 9103,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Title only memory',
					'content'    => 'SECRET PAYLOAD',
					'importance' => 'high',
				),
			),
			array()
		);

		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute(
			array(
				'agent_id'        => 9103,
				'include_content' => false,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Title only memory', $result['system_block'] );
		$this->assertStringNotContainsString( 'SECRET PAYLOAD', $result['system_block'] );
	}

	/**
	 * The `wp_mcp_ai_wake_up_system_block` filter should be applied.
	 */
	public function test_wake_up_filter_can_modify_block() {
		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 9104,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Filter test',
					'content' => 'body',
				),
			),
			array()
		);

		add_filter(
			'wp_mcp_ai_wake_up_system_block',
			static function ( $block ) {
				return "PREFIX\n" . $block;
			}
		);

		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute( array( 'agent_id' => 9104 ), array() );

		$this->assertTrue( $result['success'] );
		$this->assertStringStartsWith( 'PREFIX', $result['system_block'] );
	}

	/**
	 * Agent_id is required — the tool returns WP_Error per the canonical
	 * envelope instead of a success array with success=false.
	 */
	public function test_wake_up_requires_agent_id() {
		$tool   = $this->registry->get_tool( 'wake_up_context' );
		$result = $tool->execute( array(), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_error', $result->get_error_code() );
	}
}
