<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Test_Toolkit_Server_Chat_Exposure
 *
 * Covers the assistant→toolkit bridge shipped with the grant gate (PR #6796):
 *
 *   1. Loading mcp-servers-init.php makes the grant-lookup class available in
 *      non-admin contexts (the JSON-RPC grant gate and the initialize
 *      `toolkitServers` metadata silently skipped this lookup in production
 *      because the metabox class was admin-only).
 *   2. The bridge filter is registered on wp_mcp_ai_chat_effective_tools.
 *   3. The estimator filter is registered on wp_mcp_ai_prompt_window_toolkit_tool_slugs.
 *   4. Granted + enabled servers contribute their effective tool slugs to the
 *      assistant's effective tool list.
 *   5. Disabled servers contribute nothing.
 *   6. No assistant / no grants = noop.
 *   7. The Context Window Estimator counts the granted servers' tools.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Test_Exposure_Tool' ) ) {
	/**
	 * Tiny tool stub used as the assistant's core tool in the estimator test.
	 * The assistant CPT's tools-meta sanitizer keeps only registered slugs.
	 */
	class WP_MCP_AI_Toolkit_MCP_Test_Exposure_Tool implements WP_MCP_AI_Tool_Interface {
		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * Get slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'exposure_core_tool';
		}

		/**
		 * Get name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'Exposure Core Tool';
		}

		/**
		 * Get description.
		 *
		 * @return string
		 */
		public function get_description() {
			return 'Exposure core tool. When to use: verifying the estimator counts registered tool slugs. When NOT to use: anywhere else.';
		}

		/**
		 * Get parameters schema.
		 *
		 * @return array
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		/**
		 * Execute tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			return array( 'ok' => true );
		}
	}
}

if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Test_Exposure_Server' ) ) {
	/**
	 * Test server stub exposing two tool slugs for the bridge tests.
	 */
	class WP_MCP_AI_Toolkit_MCP_Test_Exposure_Server extends WP_MCP_AI_Toolkit_Server_Base {

		/**
		 * Get slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'exposure-test';
		}

		/**
		 * Get name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'Exposure Test';
		}

		/**
		 * Get description.
		 *
		 * @return string
		 */
		public function get_description() {
			return 'Exposure test server';
		}

		/**
		 * Get candidate tool slugs.
		 *
		 * @return string[]
		 */
		public function candidate_tool_slugs() {
			return array( 'exposure_tool_one', 'exposure_tool_two' );
		}

		/**
		 * Get ingestion surfaces.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function ingestion_surfaces() {
			return array();
		}
	}
}

/**
 * PHPUnit test case for the toolkit-server chat/estimator exposure bridge.
 */
class Test_Toolkit_Server_Chat_Exposure extends WP_UnitTestCase {

	/**
	 * Tear down — reset singletons, drop the stub tool, and clear persisted config.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			WP_MCP_AI_Tool_Registry::get_instance()->unregister_tool( 'exposure_core_tool' );
		}
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'exposure-test' );
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Register the stub server and return an assistant with the given grants.
	 *
	 * @param string[] $grants Allowed server slugs for the assistant.
	 * @return int Assistant post ID.
	 */
	private function seed_assistant( $grants ) {
		WP_MCP_AI_Toolkit_Server_Registry::get_instance()->register( new WP_MCP_AI_Toolkit_MCP_Test_Exposure_Server() );

		$assistant_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );
		if ( ! empty( $grants ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_pro_allowed_mcp_servers', $grants );
		}
		return $assistant_id;
	}

	/**
	 * Test loading the framework makes the grant-lookup class available in non-admin contexts.
	 */
	public function test_framework_loads_grant_lookup_class() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers' ),
			'The grant-lookup class must load with the framework so the JSON-RPC gate works in REST contexts.'
		);
	}

	/**
	 * Test the bridge filter is registered on the effective-tools seam.
	 */
	public function test_bridge_filter_registered() {
		$this->assertSame(
			10,
			has_filter( 'wp_mcp_ai_chat_effective_tools', 'wp_mcp_ai_toolkit_servers_expose_tools' )
		);
	}

	/**
	 * Test the estimator filter is registered on the prompt-window seam.
	 */
	public function test_estimator_filter_registered() {
		$this->assertSame(
			10,
			has_filter( 'wp_mcp_ai_prompt_window_toolkit_tool_slugs', 'wp_mcp_ai_toolkit_servers_estimator_tool_slugs' )
		);
	}

	/**
	 * Test granted + enabled servers contribute their tool slugs.
	 */
	public function test_expose_tools_appends_granted_enabled_servers() {
		$assistant_id = $this->seed_assistant( array( 'exposure-test' ) );

		$slugs = wp_mcp_ai_toolkit_servers_expose_tools(
			array( 'some_core_tool' ),
			array(),
			$assistant_id
		);

		$this->assertSame(
			array( 'some_core_tool', 'exposure_tool_one', 'exposure_tool_two' ),
			$slugs
		);
	}

	/**
	 * Test the assistant-config fallback resolves the ID when the call site passes 0.
	 */
	public function test_expose_tools_resolves_assistant_from_config() {
		$assistant_id = $this->seed_assistant( array( 'exposure-test' ) );

		$slugs = wp_mcp_ai_toolkit_servers_expose_tools(
			array(),
			array( 'ID' => $assistant_id ),
			0
		);

		$this->assertSame( array( 'exposure_tool_one', 'exposure_tool_two' ), $slugs );
	}

	/**
	 * Test disabled servers contribute nothing.
	 */
	public function test_expose_tools_skips_disabled_servers() {
		$assistant_id = $this->seed_assistant( array( 'exposure-test' ) );
		update_option(
			WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'exposure-test',
			array( 'enabled' => false )
		);

		$slugs = wp_mcp_ai_toolkit_servers_expose_tools( array(), array(), $assistant_id );

		$this->assertSame( array(), $slugs );
	}

	/**
	 * Test no assistant = noop.
	 */
	public function test_expose_tools_noop_without_assistant() {
		$slugs = wp_mcp_ai_toolkit_servers_expose_tools( array( 'some_core_tool' ), array(), 0 );

		$this->assertSame( array( 'some_core_tool' ), $slugs );
	}

	/**
	 * Test no grants = noop.
	 */
	public function test_expose_tools_noop_without_grants() {
		$assistant_id = $this->seed_assistant( array() );

		$slugs = wp_mcp_ai_toolkit_servers_expose_tools( array( 'some_core_tool' ), array(), $assistant_id );

		$this->assertSame( array( 'some_core_tool' ), $slugs );
	}

	/**
	 * Test the estimator filter returns the granted servers' slugs.
	 */
	public function test_estimator_filter_returns_granted_slugs() {
		$assistant_id = $this->seed_assistant( array( 'exposure-test' ) );

		$slugs = apply_filters( 'wp_mcp_ai_prompt_window_toolkit_tool_slugs', array(), $assistant_id );

		$this->assertSame( array( 'exposure_tool_one', 'exposure_tool_two' ), $slugs );
	}

	/**
	 * Test the estimator filter noops without grants.
	 */
	public function test_estimator_filter_noop_without_grants() {
		$assistant_id = $this->seed_assistant( array() );

		$slugs = apply_filters( 'wp_mcp_ai_prompt_window_toolkit_tool_slugs', array(), $assistant_id );

		$this->assertSame( array(), $slugs );
	}

	/**
	 * Test the Context Window Estimator counts granted servers' tools.
	 */
	public function test_estimator_meta_box_counts_granted_tools() {
		$assistant_id = $this->seed_assistant( array( 'exposure-test' ) );

		// The assistant's tools meta sanitizer keeps only registered slugs.
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Toolkit_MCP_Test_Exposure_Tool() );
		update_post_meta( $assistant_id, '_wp_mcp_ai_tools', array( 'exposure_core_tool' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );

		ob_start();
		$cpt->render_prompt_window_estimator_meta_box( get_post( $assistant_id ) );
		$output = ob_get_clean();

		// 1 core tool + 2 granted tools = 3 selected.
		$this->assertMatchesRegularExpression( '/Tools Selected:[\s\S]*?>\s*3\s*<\//', $output );
		wp_set_current_user( 0 );
	}
}
