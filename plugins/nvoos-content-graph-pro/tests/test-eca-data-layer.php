<?php
/**
 * Characterization tests for the Wave F5 eca-management data layer — the
 * ECA/student CPT class, the four metabox classes, and the enrollments +
 * attendance DB classes ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` +
 *   `src/eca/` + `src/metaboxes/` are asserted in full, including the slim
 *   init's file-gate targets and the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * ECA data layer tests.
 */
class Test_ECA_Data_Layer extends WP_UnitTestCase {

	/**
	 * The five ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_ECA_CPT'             => 'class-wp-mcp-ai-eca-cpt.php',
			'WP_MCP_AI_ECA_Enrollments_DB'  => 'eca/class-wp-mcp-ai-eca-enrollments-db.php',
			'WP_MCP_AI_ECA_Attendance_DB'   => 'eca/class-wp-mcp-ai-eca-attendance-db.php',
			'WP_MCP_AI_ECA_Metabox_Base'    => 'metaboxes/class-wp-mcp-ai-eca-metabox-base.php',
			'WP_MCP_AI_ECA_Metabox_Details' => 'metaboxes/class-wp-mcp-ai-eca-metabox-details.php',
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
	 * The ECA CPT constants must be byte-identical.
	 */
	public function test_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_eca', WP_MCP_AI_ECA_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_student', WP_MCP_AI_ECA_CPT::STUDENT_POST_TYPE );
	}

	/**
	 * The ECA CPT init must wire the registration hooks and register the
	 * ECA and student post types (settings-gated).
	 */
	public function test_cpt_init_and_registration(): void {
		$settings                          = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_eca_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		WP_MCP_AI_ECA_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_ECA_CPT', 'register_post_types' ) ) );

		WP_MCP_AI_ECA_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_eca' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_student' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The enrollments + attendance DB contracts must be byte-identical —
	 * the version constants, the custom-table names, and the deterministic
	 * first gates.
	 */
	public function test_db_contracts(): void {
		$this->assertSame( '1.0.0', WP_MCP_AI_ECA_Enrollments_DB::DB_VERSION );
		$this->assertSame( 'wp_mcp_ai_eca_enrollments_db_version', WP_MCP_AI_ECA_Enrollments_DB::VERSION_OPTION );
		$this->assertSame( '1.0.0', WP_MCP_AI_ECA_Attendance_DB::DB_VERSION );
		$this->assertSame( 'wp_mcp_ai_eca_attendance_db_version', WP_MCP_AI_ECA_Attendance_DB::VERSION_OPTION );
		$this->assertSame(
			array( 'present', 'absent', 'late', 'excused' ),
			WP_MCP_AI_ECA_Attendance_DB::ALLOWED_STATUSES
		);

		$result = WP_MCP_AI_ECA_Enrollments_DB::enroll( 0, 1, 'default', 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_eca', $result->get_error_code() );

		$result = WP_MCP_AI_ECA_Enrollments_DB::enroll( 1, 0, 'default', 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_student', $result->get_error_code() );

		$result = WP_MCP_AI_ECA_Attendance_DB::mark( 0, 1, '2026-09-11', 'present', 'default', 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_eca', $result->get_error_code() );

		$result = WP_MCP_AI_ECA_Attendance_DB::mark( 1, 1, '2026-09-11', 'bogus', 'default', 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	/**
	 * The metabox hierarchy must be byte-identical: an abstract base with
	 * `get_id()`/`get_title()` plus the details implementation.
	 */
	public function test_metabox_contracts(): void {
		$base = new ReflectionClass( 'WP_MCP_AI_ECA_Metabox_Base' );
		$this->assertTrue( $base->isAbstract() );
		$this->assertTrue( $base->hasMethod( 'get_id' ) );

		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_ECA_Metabox_Details', 'WP_MCP_AI_ECA_Metabox_Base' ) );
		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_ECA_Metabox_Schedule', 'WP_MCP_AI_ECA_Metabox_Base' ) );
		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_ECA_Metabox_Enrollment', 'WP_MCP_AI_ECA_Metabox_Base' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry zero ECA tools (the map fills as the ECA tool
	 * batch lands), and the standalone helper functions must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base ECA init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-eca-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-eca-metabox-base.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-eca-metabox-details.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-eca-metabox-enrollment.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-eca-metabox-schedule.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/eca/init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/eca/class-wp-mcp-ai-eca-enrollments-db.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/eca/class-wp-mcp-ai-eca-attendance-db.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_eca_tools', 10 );
		$this->assertCount( 0, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_eca_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_eca_management_admin_styles' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_eca_rest_routes' ) );
	}
}
