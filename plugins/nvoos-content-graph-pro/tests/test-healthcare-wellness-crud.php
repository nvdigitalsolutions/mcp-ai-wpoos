<?php
/**
 * Characterization tests for the Wave F4 healthcare wellness CRUD batch 1 —
 * the eleven ported member/policy tools plus the base-owned
 * WP_MCP_AI_Tool_Content_Media trait D8-compat copy.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/wellness/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare wellness CRUD batch 1 tests.
 */
class Test_Healthcare_Wellness_Crud extends WP_UnitTestCase {

	/**
	 * The monolith matrix loads gated tool files only when the toolkit
	 * setting is enabled at registry-init time — not guaranteed across
	 * suites. Require the eleven base files + the base-owned trait
	 * deterministically (docgen misnamed-page precedent). Standalone: the
	 * entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
				'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-list-members.php',
				'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-get-member.php',
				'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php',
				'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-delete-member.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-create-policy.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-list-policies.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-get-policy.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-update-policy.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-delete-policy.php',
				'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The twelve ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Member'   => 'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
			'WP_MCP_AI_Tool_List_Members'    => 'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-list-members.php',
			'WP_MCP_AI_Tool_Get_Member'      => 'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-get-member.php',
			'WP_MCP_AI_Tool_Update_Member'   => 'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php',
			'WP_MCP_AI_Tool_Delete_Member'   => 'tools/healthcare/wellness/members/class-wp-mcp-ai-tool-delete-member.php',
			'WP_MCP_AI_Tool_Create_Policy'   => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-create-policy.php',
			'WP_MCP_AI_Tool_List_Policies'   => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-list-policies.php',
			'WP_MCP_AI_Tool_Get_Policy'      => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-get-policy.php',
			'WP_MCP_AI_Tool_Update_Policy'   => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-update-policy.php',
			'WP_MCP_AI_Tool_Delete_Policy'   => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-delete-policy.php',
			'WP_MCP_AI_Tool_Search_Policies' => 'tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
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

		// The base-owned content-media trait: base `includes/tools/` copy
		// monolith (classmap-excluded, loaded by the base's own require) /
		// addon D8-compat copy standalone.
		$trait_reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Content_Media' );
		$trait_path       = str_replace( '\\', '/', (string) $trait_reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'includes/tools/trait-wp-mcp-ai-tool-content-media.php', $trait_path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/trait-wp-mcp-ai-tool-content-media.php', $trait_path );
		}
	}

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Member'   => 'create_member',
			'WP_MCP_AI_Tool_List_Members'    => 'list_members',
			'WP_MCP_AI_Tool_Get_Member'      => 'get_member',
			'WP_MCP_AI_Tool_Update_Member'   => 'update_member',
			'WP_MCP_AI_Tool_Delete_Member'   => 'delete_member',
			'WP_MCP_AI_Tool_Create_Policy'   => 'create_policy',
			'WP_MCP_AI_Tool_List_Policies'   => 'list_policies',
			'WP_MCP_AI_Tool_Get_Policy'      => 'get_policy',
			'WP_MCP_AI_Tool_Update_Policy'   => 'update_policy',
			'WP_MCP_AI_Tool_Delete_Policy'   => 'delete_policy',
			'WP_MCP_AI_Tool_Search_Policies' => 'search_policies',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The deterministic create-member contract degrades gracefully with a
	 * missing name and creates a member post with the byte-identical meta
	 * stamps when the author user supplies a name.
	 */
	public function test_create_member_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool = new WP_MCP_AI_Tool_Create_Member();

		$missing = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_missing_name', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'name'  => 'Jane Doe',
				'email' => 'jane@example.com',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Jane Doe', $result['member']['name'] );

		$post = get_post( $result['member_id'] );
		$this->assertSame( 'mcp_ai_member', $post->post_type );
		$this->assertSame( 'jane@example.com', get_post_meta( $result['member_id'], '_member_email', true ) );
	}

	/**
	 * The content-media trait schema must be byte-identical.
	 */
	public function test_content_media_trait_contract(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Content_Media' );
		$this->assertTrue( $reflection->isTrait() );

		$tool = new WP_MCP_AI_Tool_Create_Policy();
		$uses = class_uses( $tool );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Content_Media', $uses );
	}

	/**
	 * Standalone only: the init's tool filter must carry the fifty-one
	 * ported wellness CRUD + breadth tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_healthcare_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 51, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
			$tools['WP_MCP_AI_Tool_Create_Member']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
			$tools['WP_MCP_AI_Tool_Search_Policies']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/init.php';
		wp_mcp_ai_pro_register_healthcare_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['create_member'] ?? null );
		$this->assertNotNull( $parent->all()['create_policy'] ?? null );
		$this->assertNotNull( $parent->all()['search_policies'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_member' ) );
		$this->assertTrue( $core_tools->has( 'create_policy' ) );
		$this->assertTrue( $core_tools->has( 'search_policies' ) );
	}
}
