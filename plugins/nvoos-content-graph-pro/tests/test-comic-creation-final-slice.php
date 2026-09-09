<?php
/**
 * Characterization tests for the Wave F2 comic-creation final slice — the
 * Consolidate & Add page and the Research & Add page ported from the base
 * Pro addon (completes the comic-creation toolkit port).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` and `src/research-add/` are asserted in full, including
 *   the init's file-gate targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Comic-creation final slice tests.
 */
class Test_Comic_Creation_Final_Slice extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Comic_Consolidate_Page' => 'admin/class-wp-mcp-ai-comic-consolidate-page.php',
			'WP_MCP_AI_Comic_Research_Add'     => 'research-add/class-wp-mcp-ai-comic-research-add.php',
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
	 * The consolidate page contracts must be byte-identical.
	 */
	public function test_consolidate_page_contracts(): void {
		$this->assertSame( 'consolidate-comic', WP_MCP_AI_Comic_Consolidate_Page::PAGE_SLUG );

		$page = new WP_MCP_AI_Comic_Consolidate_Page( 'comic_creation' );
		$entity_types = $this->invoke_protected( $page, 'get_entity_types' );
		$this->assertSame( array( 'comics', 'panels', 'characters', 'scripts' ), array_keys( $entity_types ) );
		$import_formats = $this->invoke_protected( $page, 'get_import_formats' );
		$this->assertSame( array( 'cbz', 'csv', 'json' ), array_keys( $import_formats ) );
	}

	/**
	 * The research-add contracts must be byte-identical.
	 */
	public function test_research_add_contracts(): void {
		$research_add = new WP_MCP_AI_Comic_Research_Add();

		$entity_types = $this->invoke_protected( $research_add, 'get_entity_types' );
		$this->assertSame( array( 'comics', 'panels', 'characters', 'scripts' ), array_keys( $entity_types ) );

		$this->assertNotFalse( has_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $research_add, 'filter_cpt_field_schema' ) ) );
		$this->assertNotFalse( has_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $research_add, 'filter_cct_field_schema' ) ) );

		// The schema filter must pass through foreign toolkits untouched.
		$schema = array( 'type' => 'object' );
		$this->assertSame( $schema, $research_add->filter_cpt_field_schema( $schema, 'crm', 'comics' ) );
	}

	/**
	 * Standalone only: the comic init's file-gated consolidate + research-add
	 * targets must now exist (the comic-creation port is complete).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the final slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-consolidate-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-comic-research-add.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}

	/**
	 * Invoke a protected method for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @return mixed Method return value.
	 */
	private function invoke_protected( object $instance, string $method ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasMethod( $method ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invoke( $instance );
	}
}
