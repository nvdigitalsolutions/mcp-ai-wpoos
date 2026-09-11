<?php
/**
 * Characterization tests for the Wave F6 places data layer — the Place CPT
 * class and the four place metabox classes ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` +
 *   `src/metaboxes/places/` are asserted in full, including the slim init's
 *   file-gate targets and the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Places data layer tests.
 */
class Test_Places_Data_Layer extends WP_UnitTestCase {

	/**
	 * The five ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Place_CPT'              => 'class-wp-mcp-ai-place-cpt.php',
			'WP_MCP_AI_Place_Metabox_Base'     => 'metaboxes/places/class-wp-mcp-ai-place-metabox-base.php',
			'WP_MCP_AI_Place_Metabox_Location' => 'metaboxes/places/class-wp-mcp-ai-place-metabox-location.php',
			'WP_MCP_AI_Place_Metabox_Contact'  => 'metaboxes/places/class-wp-mcp-ai-place-metabox-contact.php',
			'WP_MCP_AI_Place_Metabox_Details'  => 'metaboxes/places/class-wp-mcp-ai-place-metabox-details.php',
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
	 * The Place CPT constant must be byte-identical.
	 */
	public function test_cpt_constant(): void {
		$this->assertSame( 'mcp_ai_place', WP_MCP_AI_Place_CPT::POST_TYPE );
	}

	/**
	 * The slim init's global CPT registration helper must register the place
	 * CPT + the two taxonomies when the toolkit gate is on.
	 */
	public function test_cpt_registration(): void {
		// Standalone: the helper lives inside the slim init's monolith guard.
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/places/init.php';

		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_places_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		wp_mcp_ai_register_places_management_post_type();
		$this->assertTrue( post_type_exists( 'mcp_ai_place' ) );
		$this->assertTrue( taxonomy_exists( 'mcp_ai_place_type' ) );
		$this->assertTrue( taxonomy_exists( 'mcp_ai_place_tag' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The metabox hierarchy must be byte-identical.
	 */
	public function test_metabox_contracts(): void {
		$base = new ReflectionClass( 'WP_MCP_AI_Place_Metabox_Base' );
		$this->assertTrue( $base->isAbstract() );

		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_Place_Metabox_Location', 'WP_MCP_AI_Place_Metabox_Base' ) );
		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_Place_Metabox_Contact', 'WP_MCP_AI_Place_Metabox_Base' ) );
		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_Place_Metabox_Details', 'WP_MCP_AI_Place_Metabox_Base' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry zero places tools (the map fills as the places tool
	 * batch lands), and the standalone helper functions must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base places init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-place-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/places/class-wp-mcp-ai-place-metabox-base.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/places/class-wp-mcp-ai-place-metabox-location.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/places/class-wp-mcp-ai-place-metabox-contact.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/places/class-wp-mcp-ai-place-metabox-details.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/places/init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/places/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_places_tools', 10 );
		$this->assertCount( 0, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_places_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_places_management_post_type' ) );
	}
}
