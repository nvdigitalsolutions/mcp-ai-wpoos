<?php
/**
 * Characterization tests for the Wave F2 document-generation QMS data layer
 * and admin slice — the eight QMS classes, the toolkit settings page, the
 * research page, and the Research & Add integration ported from the base Pro
 * addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are asserted in full, including the docgen init's file-gate targets and
 *   the QMS registry module.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Document-generation QMS/admin slice tests.
 */
class Test_Document_Generation_Qms_Admin extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		// The class name deliberately does not match its filename upstream
		// (`...-cpt-settings-page.php`), so neither classmap serves it; the
		// docgen init requires it explicitly (is_admin-gated).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		}
		$symbols = array(
			'WP_MCP_AI_QMS_Capabilities'                  => 'qms/class-wp-mcp-ai-qms-capabilities.php',
			'WP_MCP_AI_QMS_Doc_Record_CPT'                => 'qms/class-wp-mcp-ai-qms-doc-record-cpt.php',
			'WP_MCP_AI_QMS_Audit_Log'                     => 'qms/class-wp-mcp-ai-qms-audit-log.php',
			'WP_MCP_AI_Document_Generation_Settings_Page' => 'admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php',
			'WP_MCP_AI_Document_Template_Research_Page'   => 'admin/class-wp-mcp-ai-document-template-research-page.php',
			'WP_MCP_AI_Document_Generation_Research_Add'  => 'research-add/class-wp-mcp-ai-document-generation-research-add.php',
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
	 * The QMS constants must be byte-identical.
	 */
	public function test_qms_constants(): void {
		$this->assertSame( 'manage_qms', WP_MCP_AI_QMS_Capabilities::CAP );
		$this->assertSame( 'mcp_ai_doc_record', WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_qms_doc_type', WP_MCP_AI_QMS_Taxonomy::TAXONOMY );
	}

	/**
	 * The QMS doc-record CPT must register the byte-identical post type.
	 */
	public function test_qms_cpt_registration(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_document_generation_toolkit' => true,
				'enable_qms_compliance'             => true,
			)
		);

		WP_MCP_AI_QMS_Doc_Record_CPT::register();
		$this->assertTrue( post_type_exists( 'mcp_ai_doc_record' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The settings page constructor props must be byte-identical.
	 */
	public function test_settings_page_props(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		}

		$settings = new WP_MCP_AI_Document_Generation_Settings_Page();
		$this->assertSame( 'document-generation-settings', $this->read_prop( $settings, 'page_slug' ) );
	}

	/**
	 * The research page constants + init hooks must be byte-identical.
	 */
	public function test_research_page_contracts(): void {
		$this->assertSame( 'research-document-template', WP_MCP_AI_Document_Template_Research_Page::PAGE_SLUG );

		WP_MCP_AI_Document_Template_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Document_Template_Research_Page', 'add_menu_page' ) ) );
	}

	/**
	 * Standalone only: the docgen init's file-gated admin targets must now
	 * exist (the admin slice + QMS layer have landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-template-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-document-generation-research-add.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/qms/class-wp-mcp-ai-qms-init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}

	/**
	 * Standalone only: the QMS init must boot the subsystem hooks.
	 */
	public function test_qms_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base module registry boots QMS.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/qms/class-wp-mcp-ai-qms-init.php';

		// First-loader-gated (the WP test framework restores hooks between
		// tests; a previous suite may have already required the init).
		WP_MCP_AI_QMS_Doc_Record_CPT::init();
		WP_MCP_AI_QMS_Taxonomy::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_QMS_Doc_Record_CPT', 'register' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_QMS_Taxonomy', 'register' ) ) );
	}

	/**
	 * Read a protected/private property for assertions.
	 *
	 * @param object $object Object instance.
	 * @param string $prop   Property name.
	 * @return mixed Property value.
	 */
	private function read_prop( $object, string $prop ) {
		$reflection = new ReflectionProperty( $object, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $object );
	}
}
