<?php
/**
 * Characterization tests for the Wave F2 regulatory-registration data layer —
 * the multi-CPT registration class and the requirement-post-type migration
 * ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are asserted in full, including the slim init's file-gate targets and
 *   the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Regulatory-registration data layer tests.
 */
class Test_Regulatory_Registration_Data_Layer extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Regulatory_Registration_CPT'   => 'class-wp-mcp-ai-regulatory-registration-cpt.php',
			'WP_MCP_AI_Migrate_Requirement_Post_Type' => 'migrations/class-wp-mcp-ai-migrate-requirement-post-type.php',
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
	 * The five post-type constants must be byte-identical.
	 */
	public function test_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_reg_product', WP_MCP_AI_Regulatory_Registration_CPT::PRODUCT_POST_TYPE );
		$this->assertSame( 'mcp_ai_registration', WP_MCP_AI_Regulatory_Registration_CPT::REGISTRATION_POST_TYPE );
		$this->assertSame( 'mcp_ai_reg_document', WP_MCP_AI_Regulatory_Registration_CPT::DOCUMENT_POST_TYPE );
		$this->assertSame( 'mcp_ai_reg_country', WP_MCP_AI_Regulatory_Registration_CPT::COUNTRY_POST_TYPE );
		$this->assertSame( 'mcp_ai_requirement', WP_MCP_AI_Regulatory_Registration_CPT::REQUIREMENT_POST_TYPE );
	}

	/**
	 * The settings-gated CPT init must wire the registration hooks and
	 * register the post types.
	 */
	public function test_cpt_init_and_registration(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_regulatory_registration_toolkit' => true ) );

		WP_MCP_AI_Regulatory_Registration_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Regulatory_Registration_CPT', 'register_post_types' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Regulatory_Registration_CPT', 'register_taxonomies' ) ) );

		WP_MCP_AI_Regulatory_Registration_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_reg_product' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_registration' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_reg_document' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_reg_country' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_requirement' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The migration status contract must report the byte-identical shape on
	 * an empty environment.
	 */
	public function test_migration_status_contract(): void {
		$status = WP_MCP_AI_Migrate_Requirement_Post_Type::get_status();
		$this->assertArrayHasKey( 'needs_migration', $status );
		$this->assertArrayHasKey( 'migration_completed', $status );
	}

	/**
	 * Standalone only: the slim init's file targets must exist and the tool
	 * filter must start empty (the tool batches land with the following
	 * sub-clusters).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-regulatory-registration-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/migrations/class-wp-mcp-ai-migrate-requirement-post-type.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_regulatory_registration_tools', 10 );
		$this->assertSame( array(), apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
	}
}
