<?php
/**
 * Tests for agent-identity resolution and scope gating across the memory tools.
 *
 * The memory tools historically hard-required an `agent_id` argument, forcing
 * the calling model to guess an identity the runtime already knows. This suite
 * locks in the fix:
 *
 *  - `agent_id` is optional and resolves from the execution context.
 *  - Cross-agent access requires `manage_options` (403 otherwise).
 *  - Neither argument nor context identity fails loudly (400), never guesses.
 *  - Responses echo `resolved_agent_id` + `resolution_source`.
 *
 * @package WP_MCP_AI
 */

/**
 * Identity + scope behaviour for the memory tool family.
 */
class Test_Memory_Tool_Identity_Scope extends WP_UnitTestCase {

	/**
	 * Create a real user with the given role and set it as the current user.
	 *
	 * @param string $role WordPress role slug.
	 * @return int User ID.
	 */
	private function create_user( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Wipe memory transients and the recall candidates filter between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_recall_memory_candidates' );
		parent::tearDown();
	}

	/**
	 * Every memory tool must stop requiring agent_id in its schema.
	 */
	public function test_schemas_no_longer_require_agent_id() {
		$tools = array(
			'retrieve_agent_memory'    => 'WP_MCP_AI_Tool_Retrieve_Agent_Memory',
			'store_agent_context'      => 'WP_MCP_AI_Tool_Store_Agent_Context',
			'recall_memory'            => 'WP_MCP_AI_Tool_Recall_Memory',
			'wake_up_context'          => 'WP_MCP_AI_Tool_Wake_Up_Context',
			'semantic_context_search'  => 'WP_MCP_AI_Tool_Semantic_Context_Search',
			'mine_agent_memory'        => 'WP_MCP_AI_Tool_Mine_Agent_Memory',
			'manage_context_lifecycle' => 'WP_MCP_AI_Tool_Manage_Context_Lifecycle',
			'batch_manage_memory'      => 'WP_MCP_AI_Tool_Batch_Manage_Memory',
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		foreach ( $tools as $slug => $class ) {
			if ( ! class_exists( $class ) ) {
				$this->markTestSkipped( $class . ' not loaded.' );
			}
			$tool   = $registry->get_tool( $slug );
			$schema = $tool->get_parameters_schema();
			$this->assertNotContains( 'agent_id', $schema['required'], $slug . ' must not require agent_id.' );
		}
	}

	/**
	 * The recall_memory tool resolves its own identity from the execution
	 * context and echoes the resolved scope back.
	 */
	public function test_recall_resolves_identity_from_context() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );

		add_filter(
			'wp_mcp_ai_recall_memory_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_recall_own',
						'wing'       => 'client/acme',
						'room'       => 'decisions',
						'tier'       => 'core',
						'importance' => 0.8,
						'valid_from' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
						'title'      => 'Own decision',
					),
				);
			}
		);

		$tool   = new WP_MCP_AI_Tool_Recall_Memory();
		$result = $tool->execute(
			array( 'wing' => 'client/acme' ),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $own, $result['resolved_agent_id'] );
		$this->assertSame( 'context', $result['resolution_source'] );
		$this->assertNotEmpty( $result['memories'] );
	}

	/**
	 * The recall_memory tool denies cross-agent recall without manage_options.
	 */
	public function test_recall_cross_agent_denied_without_manage_options() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Recall_Memory();
		$result = $tool->execute(
			array(
				'agent_id' => $other,
				'wing'     => 'client/acme',
			),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );
	}

	/**
	 * The recall_memory tool fails loudly when neither argument nor context
	 * identity exists.
	 */
	public function test_recall_missing_identity_errors_400() {
		$admin = $this->create_user( 'administrator' );

		$tool   = new WP_MCP_AI_Tool_Recall_Memory();
		$result = $tool->execute(
			array( 'wing' => 'client/acme' ),
			array( 'user_id' => $admin )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_no_agent', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * The wake_up_context tool resolves its own identity from context even
	 * when the store is empty — the empty envelope still echoes the resolved
	 * scope.
	 */
	public function test_wake_up_resolves_identity_from_context_when_empty() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Wake_Up_Context();
		$result = $tool->execute(
			array(),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $own, $result['resolved_agent_id'] );
		$this->assertSame( 'context', $result['resolution_source'] );
		$this->assertSame( '', $result['system_block'] );
	}

	/**
	 * The wake_up_context tool denies cross-agent wake-up without
	 * manage_options.
	 */
	public function test_wake_up_cross_agent_denied_without_manage_options() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Wake_Up_Context();
		$result = $tool->execute(
			array( 'agent_id' => $other ),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );
	}

	/**
	 * The semantic_context_search tool gates cross-agent reads before any
	 * embedding call (deterministic 403 even without an OpenAI key
	 * configured).
	 */
	public function test_semantic_cross_agent_denied_without_manage_options() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Semantic_Context_Search();
		$result = $tool->execute(
			array(
				'agent_id' => $other,
				'query'    => 'anything',
			),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );
	}

	/**
	 * The semantic_context_search tool fails loudly without any identity.
	 */
	public function test_semantic_missing_identity_errors_400() {
		$admin = $this->create_user( 'administrator' );

		$tool   = new WP_MCP_AI_Tool_Semantic_Context_Search();
		$result = $tool->execute(
			array( 'query' => 'anything' ),
			array( 'user_id' => $admin )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_no_agent', $result->get_error_code() );
	}

	/**
	 * The mine_agent_memory tool resolves its own identity from context on a
	 * dry run and echoes the resolved scope.
	 */
	public function test_mine_resolves_identity_from_context() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Mine_Agent_Memory();
		$result = $tool->execute(
			array(
				'source'  => 'text',
				'dry_run' => true,
				'items'   => array(
					array(
						'title'   => 'Own note',
						'content' => 'Mined into my own store.',
					),
				),
			),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $own, $result['resolved_agent_id'] );
		$this->assertSame( 'context', $result['resolution_source'] );
	}

	/**
	 * The mine_agent_memory tool denies cross-agent mining without
	 * manage_options.
	 */
	public function test_mine_cross_agent_denied_without_manage_options() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Mine_Agent_Memory();
		$result = $tool->execute(
			array(
				'agent_id' => $other,
				'source'   => 'text',
				'items'    => array(
					array(
						'title'   => 'Injected',
						'content' => 'Should never reach the store.',
					),
				),
			),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );
	}

	/**
	 * The manage_context_lifecycle tool denies cross-agent operations without
	 * manage_options, and fails loudly without any identity.
	 */
	public function test_lifecycle_scope_gates() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Manage_Context_Lifecycle();
		$result = $tool->execute(
			array(
				'agent_id' => $other,
				'action'   => 'analyze',
			),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );

		$result = $tool->execute(
			array( 'action' => 'analyze' ),
			array( 'user_id' => $subscriber )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_no_agent', $result->get_error_code() );
	}

	/**
	 * The batch_manage_memory tool denies cross-agent operations without
	 * manage_options, and fails loudly without any identity.
	 */
	public function test_batch_scope_gates() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );

		$tool   = new WP_MCP_AI_Tool_Batch_Manage_Memory();
		$result = $tool->execute(
			array(
				'agent_id' => $other,
				'action'   => 'export',
			),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );

		$result = $tool->execute(
			array( 'action' => 'export' ),
			array( 'user_id' => $subscriber )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_no_agent', $result->get_error_code() );
	}
}
