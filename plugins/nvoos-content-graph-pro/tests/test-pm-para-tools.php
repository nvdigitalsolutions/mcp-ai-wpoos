<?php
/**
 * Characterization tests for the Wave F2 PM PARA + capture-decision batch —
 * the ported PARA subsystem (taxonomy/area CPT/lifecycle/admin columns/
 * self-booting init), the seven PARA tools, and the MemPalace capture-
 * decision tool.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants, hooks,
 *   and tool surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/para/` + `src/tools/project-management/` are asserted in full,
 *   including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM PARA batch tests.
 */
class Test_Pm_Para_Tools extends WP_UnitTestCase {

	/**
	 * Enable the PM toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		$settings['enable_para_organization']  = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Register the PARA area CPT + taxonomy and seed the four locked roots.
	 */
	private function seed_para(): void {
		WP_MCP_AI_PARA_Area_CPT::register();
		WP_MCP_AI_PARA_Taxonomy::register_taxonomy();
		WP_MCP_AI_PARA_Taxonomy::seed_root_terms();
	}

	/**
	 * The thirteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_PARA_Taxonomy'              => 'para/class-wp-mcp-ai-para-taxonomy.php',
			'WP_MCP_AI_PARA_Area_CPT'              => 'para/class-wp-mcp-ai-para-area-cpt.php',
			'WP_MCP_AI_PARA_Lifecycle'             => 'para/class-wp-mcp-ai-para-lifecycle.php',
			'WP_MCP_AI_PARA_Admin_Columns'         => 'para/class-wp-mcp-ai-para-admin-columns.php',
			'WP_MCP_AI_Tool_PARA_Classify_Item'    => 'tools/project-management/class-wp-mcp-ai-tool-para-classify-item.php',
			'WP_MCP_AI_Tool_PARA_Create_Area'      => 'tools/project-management/class-wp-mcp-ai-tool-para-create-area.php',
			'WP_MCP_AI_Tool_PARA_List_Areas'       => 'tools/project-management/class-wp-mcp-ai-tool-para-list-areas.php',
			'WP_MCP_AI_Tool_PARA_Move_To_Archives' => 'tools/project-management/class-wp-mcp-ai-tool-para-move-to-archives.php',
			'WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project' => 'tools/project-management/class-wp-mcp-ai-tool-para-promote-resource-to-project.php',
			'WP_MCP_AI_Tool_PARA_Update_Area'      => 'tools/project-management/class-wp-mcp-ai-tool-para-update-area.php',
			'WP_MCP_AI_Tool_PARA_Weekly_Review'    => 'tools/project-management/class-wp-mcp-ai-tool-para-weekly-review.php',
			'WP_MCP_AI_Tool_PM_Capture_Decision'   => 'tools/project-management/class-wp-mcp-ai-tool-pm-capture-decision.php',
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
	 * The PARA subsystem constants must be byte-identical.
	 */
	public function test_para_constants(): void {
		$this->assertSame( 'mcp_ai_para', WP_MCP_AI_PARA_Taxonomy::TAXONOMY );
		$this->assertSame( array( 'projects', 'areas', 'resources', 'archives' ), WP_MCP_AI_PARA_Taxonomy::ROOTS );
		$this->assertSame( 'mcp_ai_area', WP_MCP_AI_PARA_Area_CPT::POST_TYPE );
	}

	/**
	 * The eight tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_PARA_Classify_Item'    => 'para_classify_item',
			'WP_MCP_AI_Tool_PARA_Create_Area'      => 'para_create_area',
			'WP_MCP_AI_Tool_PARA_List_Areas'       => 'para_list_areas',
			'WP_MCP_AI_Tool_PARA_Move_To_Archives' => 'para_move_to_archives',
			'WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project' => 'para_promote_resource_to_project',
			'WP_MCP_AI_Tool_PARA_Update_Area'      => 'para_update_area',
			'WP_MCP_AI_Tool_PARA_Weekly_Review'    => 'para_weekly_review',
			'WP_MCP_AI_Tool_PM_Capture_Decision'   => 'pm_capture_decision',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The capture-decision tool must extend the ported capture base.
	 */
	public function test_capture_decision_extends_base(): void {
		$tool = new WP_MCP_AI_Tool_PM_Capture_Decision();
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_Capture_Tool_Base', $tool );
		$this->assertContains( 'pii-data', $tool->get_capability_flags() );
	}

	/**
	 * create-area must enforce the title gate and stamp the PARA meta.
	 */
	public function test_create_area_execute(): void {
		$this->seed_para();

		$tool = new WP_MCP_AI_Tool_PARA_Create_Area();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'title'          => 'Health Content',
				'description'    => 'Area for wellness articles.',
				'standard'       => 'Every post gets alt text.',
				'review_cadence' => 'weekly',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$area_id = $result['area_id'];
		$this->assertSame( 'mcp_ai_area', get_post( $area_id )->post_type );
		$this->assertSame( 'Every post gets alt text.', get_post_meta( $area_id, '_para_standard', true ) );
		$this->assertSame( 'weekly', get_post_meta( $area_id, '_para_review_cadence', true ) );
	}

	/**
	 * list-areas must return the created area.
	 */
	public function test_list_areas_execute(): void {
		$this->seed_para();
		$create = new WP_MCP_AI_Tool_PARA_Create_Area();
		$create->execute( array( 'title' => 'Listed Area' ), array( 'user_id' => 1 ) );

		$list   = new WP_MCP_AI_Tool_PARA_List_Areas();
		$result = $list->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['areas'] ?? array() );
	}

	/**
	 * classify-item must reject missing posts and classify real ones.
	 */
	public function test_classify_item_execute(): void {
		$this->seed_para();
		$tool = new WP_MCP_AI_Tool_PARA_Classify_Item();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_invalid_post', $missing->get_error_code() );

		$post_id = $this->factory()->post->create( array( 'post_type' => 'post' ) );
		$result  = $tool->execute(
			array(
				'post_id' => $post_id,
				'bucket'  => 'resources',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * weekly-review must run its smoke path.
	 */
	public function test_weekly_review_execute(): void {
		$tool   = new WP_MCP_AI_Tool_PARA_Weekly_Review();
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Standalone only: the init's tool filter must carry the PARA batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the PM tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_PARA_Create_Area', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_PM_Capture_Decision', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the PARA
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		wp_mcp_ai_pro_register_pm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['para_create_area'] ?? null );
		$this->assertNotNull( $parent->all()['pm_capture_decision'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'para_classify_item' ) );
		$this->assertTrue( $core_tools->has( 'para_weekly_review' ) );
	}

	/**
	 * Standalone only: the registry must declare the pro_para module.
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the module.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$modules = $registry->get_modules();
		$this->assertArrayHasKey( 'pro_para', $modules );
		$this->assertSame( 'PARA Init', $modules['pro_para']['label'] );
	}
}
