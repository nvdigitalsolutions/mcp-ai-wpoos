<?php
/**
 * Characterization tests for the ported Pro module registry (Wave F1).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns
 *   `WP_MCP_AI_Pro_Module_Registry`; only its public surface is asserted.
 * - Standalone matrix (base plugin absent): the ported registry in
 *   `src/class-wp-mcp-ai-pro-module-registry.php` is asserted in full,
 *   including the protected test seams via the helper subclass.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Module registry tests.
 */
class Test_Pro_Module_Registry extends WP_UnitTestCase {

	/**
	 * Whether the standalone matrix is active (base plugin absent).
	 *
	 * @return bool
	 */
	private function standalone(): bool {
		return ! defined( 'WP_MCP_AI_PATH' );
	}

	/**
	 * Skip when the monolith matrix is active (the base registry is final).
	 *
	 * @return void
	 */
	private function require_standalone(): void {
		if ( ! $this->standalone() ) {
			$this->markTestSkipped( 'Monolith matrix: the base Pro addon owns the registry.' );
		}
	}

	/**
	 * Build the testable subclass (standalone matrix only).
	 *
	 * @return \NvoosContentGraphPro\Tests\Test_Pro_Module_Registry_Seam
	 */
	private function make_testable(): \NvoosContentGraphPro\Tests\Test_Pro_Module_Registry_Seam {
		require_once __DIR__ . '/helpers/test-pro-module-registry-seam.php';
		return \NvoosContentGraphPro\Tests\Test_Pro_Module_Registry_Seam::make();
	}

	/**
	 * The registry class must be available in both matrices.
	 */
	public function test_registry_class_is_available(): void {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) );
	}

	/**
	 * get_instance() must return the same instance every time.
	 */
	public function test_get_instance_is_singleton(): void {
		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$this->assertSame( $registry, WP_MCP_AI_Pro_Module_Registry::get_instance() );
	}

	/**
	 * boot() must be idempotent: a second boot changes nothing.
	 */
	public function test_boot_is_idempotent(): void {
		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$first = $registry->get_loaded_modules();
		$registry->boot();
		$this->assertSame( $first, $registry->get_loaded_modules() );
	}

	/**
	 * Monolith matrix: the base registry owns the full Pro module set and
	 * must report the F1 privacy module as loaded after boot.
	 */
	public function test_monolith_base_registry_owns_pro_modules(): void {
		if ( $this->standalone() ) {
			$this->markTestSkipped( 'Standalone matrix: ported registry covers this.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertContains( 'privacy', $registry->get_loaded_modules() );
		$this->assertTrue( $registry->is_loaded( 'privacy' ) );
	}

	/**
	 * Standalone matrix: modules whose files have not landed yet must be
	 * skipped silently; modules whose files exist must be loaded.
	 */
	public function test_boot_degrades_gracefully_on_missing_module_files(): void {
		$this->require_standalone();

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();

		foreach ( $registry->get_modules() as $id => $mod ) {
			// Disabled modules (e.g. toolkit_crm without enable_crm_toolkit)
			// must be skipped regardless of their files.
			if ( isset( $mod['enabled'] ) && ! $mod['enabled'] ) {
				$this->assertFalse( $registry->is_loaded( $id ), "Disabled module \"{$id}\" must not load." );
				continue;
			}
			// Admin-context modules (admin_remote_sites) only boot when the
			// matrix runs in an admin context (is_admin()).
			if ( ! empty( $mod['context'] ) && 'admin' === $mod['context'] && ! is_admin() ) {
				$this->assertFalse( $registry->is_loaded( $id ), "Admin module \"{$id}\" must not load outside admin context." );
				continue;
			}
			if ( empty( $mod['files'] ) ) {
				continue;
			}
			$all_present = true;
			foreach ( $mod['files'] as $file ) {
				if ( ! file_exists( $file ) ) {
					$all_present = false;
					break;
				}
			}
			if ( $all_present ) {
				$this->assertTrue( $registry->is_loaded( $id ), "Module \"{$id}\" should load when its files exist." );
			} else {
				$this->assertFalse( $registry->is_loaded( $id ), "Module \"{$id}\" should degrade when its files are missing." );
			}
		}
	}

	/**
	 * define_modules() must register exactly the Wave F1 pro-core subset.
	 */
	public function test_define_modules_registers_f1_subset(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$registry->define_test_modules( array() );

		$expected = array(
			'privacy',
			'toolkit_data_store',
			'pro_skills_manager',
			'toolkit_vault',
			'vector_storage',
			'toolkit_crm',
			'toolkit_ecommerce',
			'toolkit_project_management',
			'pro_para',
			'toolkit_calendar_booking',
			'booking_adapters',
			'toolkit_financial_planning',
			'toolkit_social_media',
			'mcp_servers_framework',
			'remote_connection',
			'admin_remote_sites',
			'remote_connections',
			'toolkit_video_production',
			'toolkit_analytics',
			'toolkit_multilingual',
			'toolkit_cloudways',
			'toolkit_dj_management',
			'toolkit_image_production',
			'toolkit_comic_creation',
			'toolkit_ai_tool_builder',
			'toolkit_architect_agent',
			'toolkit_architectural_design',
			'toolkit_site_creator',
			'toolkit_document_generation',
			'pro_qms',
			'toolkit_regulatory_registration',
			'toolkit_healthcare',
			'toolkit_law_firm',
			'toolkit_cre_debt',
		);

		$this->assertSame( $expected, array_keys( $registry->modules() ) );

		foreach ( $expected as $id ) {
			$mod = $registry->modules()[ $id ];
			$this->assertNotEmpty( $mod['label'] );
			$this->assertIsArray( $mod['deps'] );
			$this->assertIsCallable( $mod['factory'] );
		}
	}

	/**
	 * resolve_order() must run dependencies before dependents.
	 */
	public function test_resolve_order_is_topological(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$registry->add( 'a', 'A', array(), array(), static function (): void {} );
		$registry->add( 'b', 'B', array( 'a' ), array(), static function (): void {} );
		$registry->add( 'c', 'C', array( 'a', 'b' ), array(), static function (): void {} );

		$order = $registry->ordered();
		$this->assertCount( 3, $order );
		$this->assertLessThan( array_search( 'b', $order, true ), array_search( 'a', $order, true ) );
		$this->assertLessThan( array_search( 'c', $order, true ), array_search( 'a', $order, true ) );
		$this->assertLessThan( array_search( 'c', $order, true ), array_search( 'b', $order, true ) );
	}

	/**
	 * Circular dependencies must not hang — fall back to insertion order.
	 */
	public function test_resolve_order_circular_deps_fall_back_to_insertion_order(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$registry->add( 'a', 'A', array( 'b' ), array(), static function (): void {} );
		$registry->add( 'b', 'B', array( 'a' ), array(), static function (): void {} );

		$order = $registry->ordered();
		$this->assertCount( 2, $order );
		$this->assertContains( 'a', $order );
		$this->assertContains( 'b', $order );
	}

	/**
	 * boot() must run factories in dependency order and track loaded state.
	 */
	public function test_boot_runs_factories_in_dependency_order(): void {
		$this->require_standalone();

		$log      = array();
		$registry = $this->make_testable();
		$registry->add(
			'alpha',
			'Alpha',
			array(),
			array(),
			static function () use ( &$log ): void {
				$log[] = 'alpha';
			}
		);
		$registry->add(
			'beta',
			'Beta',
			array( 'alpha' ),
			array(),
			static function () use ( &$log ): void {
				$log[] = 'beta';
			}
		);

		$this->assertFalse( $registry->is_bootstrapped() );
		$registry->boot();
		$this->assertTrue( $registry->is_bootstrapped() );

		$this->assertContains( 'alpha', $log );
		$this->assertContains( 'beta', $log );
		$this->assertLessThan( array_search( 'beta', $log, true ), array_search( 'alpha', $log, true ) );
		$this->assertTrue( $registry->is_loaded( 'alpha' ) );
		$this->assertTrue( $registry->is_loaded( 'beta' ) );
	}

	/**
	 * The admin context gate must only admit modules when is_admin().
	 */
	public function test_check_context_admin_gate(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$this->assertTrue( $registry->context_ok( array() ) );
		$this->assertSame( is_admin(), $registry->context_ok( array( 'context' => 'admin' ) ) );
	}

	/**
	 * The class-requirements gate must reject missing classes.
	 */
	public function test_check_required_classes(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$this->assertTrue( $registry->classes_ok( array() ) );
		$this->assertTrue( $registry->classes_ok( array( 'requires' => array( 'WP_Error' ) ) ) );
		$this->assertFalse( $registry->classes_ok( array( 'requires' => array( 'NvoosContentGraphPro_Missing_Class' ) ) ) );
	}

	/**
	 * The function-requirements gate must reject missing functions.
	 */
	public function test_check_required_functions(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$this->assertTrue( $registry->functions_ok( array() ) );
		$this->assertTrue( $registry->functions_ok( array( 'requires_fn' => array( 'wp_kses_post' ) ) ) );
		$this->assertFalse( $registry->functions_ok( array( 'requires_fn' => array( 'nvoos_content_graph_pro_missing_fn' ) ) ) );
	}

	/**
	 * The file-requirements gate must reject missing files.
	 */
	public function test_check_required_files(): void {
		$this->require_standalone();

		$registry = $this->make_testable();
		$this->assertTrue( $registry->files_ok( array() ) );
		$this->assertTrue(
			$registry->files_ok( array( 'files' => array( NVOOS_CONTENT_GRAPH_PRO_PATH . 'nvoos-content-graph-pro.php' ) ) )
		);
		$this->assertFalse(
			$registry->files_ok( array( 'files' => array( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/definitely-missing.php' ) ) )
		);
	}
}
