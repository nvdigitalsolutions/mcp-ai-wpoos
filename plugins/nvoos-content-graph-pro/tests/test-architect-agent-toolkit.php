<?php
/**
 * Characterization tests for the Wave F2 architect-agent toolkit — the six
 * self-editing tools, the git-helpers trait, the D8-compat proc helpers, the
 * slim standalone init, and the settings page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/architect-agent/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Architect-agent toolkit tests.
 */
class Test_Architect_Agent_Toolkit extends WP_UnitTestCase {

	/**
	 * The git-helpers trait is explicit-required by the base init monolith
	 * (the Pro fallback autoloader only globs class- files); mirror that
	 * per matrix so the git tools can load.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php';
		}
	}

	/**
	 * The registered tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$files = new WP_MCP_AI_Tool_Manage_Files();
		$this->assertSame( 'manage_files', $files->get_slug() );
		$this->assertSame( 'edit_posts', $files->get_required_capability() );

		$shell = new WP_MCP_AI_Tool_Execute_Shell_Command();
		$this->assertSame( 'execute_shell_command', $shell->get_slug() );
		$this->assertSame( 'edit_plugins', $shell->get_required_capability() );

		$inspect = new WP_MCP_AI_Tool_Git_Inspect();
		$this->assertSame( 'git_inspect', $inspect->get_slug() );

		$change = new WP_MCP_AI_Tool_Git_Change();
		$this->assertSame( 'git_change', $change->get_slug() );

		$search = new WP_MCP_AI_Tool_Search_Codebase();
		$this->assertSame( 'search_codebase', $search->get_slug() );

		// The legacy git-operations class stays loadable but unregistered.
		$legacy = new WP_MCP_AI_Tool_Git_Operations();
		$this->assertSame( 'git_operations', $legacy->get_slug() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Manage_Files'    => 'tools/architect-agent/class-wp-mcp-ai-tool-manage-files.php',
			'WP_MCP_AI_Tool_Git_Inspect'     => 'tools/architect-agent/class-wp-mcp-ai-tool-git-inspect.php',
			'WP_MCP_AI_Tool_Search_Codebase' => 'tools/architect-agent/class-wp-mcp-ai-tool-search-codebase.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * Standalone only: the proc helpers must exist (D8-compat copy).
	 */
	public function test_proc_helpers_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base bootstrap declares the helpers.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/helpers.php';
		$this->assertTrue( function_exists( 'wp_mcp_ai_run_process' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_run_shell' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_find_binary' ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the five registered
	 * tools (the legacy git-operations class stays unregistered).
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers the architect tools via the tool registry.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_architect_agent_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 5, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-manage-files.php',
			$tools['WP_MCP_AI_Tool_Manage_Files']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Search_Codebase', $tools );
		$this->assertArrayNotHasKey( 'WP_MCP_AI_Tool_Git_Operations', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the five
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/init.php';
		wp_mcp_ai_pro_register_architect_agent_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['manage_files'] ?? null );
		$this->assertNotNull( $parent->all()['git_inspect'] ?? null );
		$this->assertNotNull( $parent->all()['git_change'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'manage_files' ) );
		$this->assertTrue( $core_tools->has( 'execute_shell_command' ) );
		$this->assertTrue( $core_tools->has( 'search_codebase' ) );
	}

	/**
	 * Standalone only: the init's file-gated settings-page target must exist.
	 */
	public function test_init_settings_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$this->assertFileExists(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architect-agent-settings-page.php'
		);
	}
}
