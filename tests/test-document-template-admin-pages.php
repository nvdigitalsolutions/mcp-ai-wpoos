<?php
/**
 * Tests for Document Template Admin Pages
 *
 * Verifies that Research & Add and Settings pages are properly registered
 * under the Document Templates menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test document template admin page registration.
 */
class Test_Document_Template_Admin_Pages extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Store original menu globals and clear them.
		global $menu, $submenu;
		$this->original_menu    = $menu;
		$this->original_submenu = $submenu;
		$menu                   = array();
		$submenu                = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Restore original settings.
		if ( ! empty( $this->original_settings ) ) {
			update_option( 'wp_mcp_ai_settings', $this->original_settings );
		} else {
			delete_option( 'wp_mcp_ai_settings' );
		}

		// Restore original menu globals.
		global $menu, $submenu;
		$menu    = $this->original_menu;
		$submenu = $this->original_submenu;

		parent::tearDown();
	}

	/**
	 * Original settings.
	 *
	 * @var array
	 */
	protected $original_settings = array();

	/**
	 * Original menu state.
	 *
	 * @var array
	 */
	protected $original_menu;

	/**
	 * Original submenu state.
	 *
	 * @var array
	 */
	protected $original_submenu;

	/**
	 * Test that Document Template CPT is registered.
	 */
	public function test_document_template_cpt_registered() {
		// Initialize the CPT.
		WP_MCP_AI_Document_Template_CPT::init();
		do_action( 'init' );

		// Check if the post type is registered.
		$post_type = get_post_type_object( 'mcp_ai_doc_tpl' );
		$this->assertNotNull( $post_type, 'Document Template CPT should be registered' );
		$this->assertTrue( $post_type->show_in_menu, 'Document Template CPT should show in menu' );
	}

	/**
	 * Test that Research & Add page is registered.
	 */
	public function test_research_add_page_registered() {
		global $submenu;

		// Load the initialization file to ensure admin pages are loaded.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Document_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_doc_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Document Template should have submenu items' );

		// Find the Research & Add page.
		$found_research_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'research-document-template' ) !== false ) {
					$found_research_page = true;
					$this->assertSame( 'Research & Add', $item[0], 'Research & Add page should have correct title' );
					break;
				}
			}
		}

		$this->assertTrue( $found_research_page, 'Research & Add page should be registered' );
	}

	/**
	 * Test that Settings page is registered.
	 */
	public function test_settings_page_registered() {
		global $submenu;

		// Load the initialization file to ensure admin pages are loaded.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Document_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_doc_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Document Template should have submenu items' );

		// Find the Settings page.
		$found_settings_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'document-generation-settings' ) !== false ) {
					$found_settings_page = true;
					$this->assertSame( 'Settings', $item[0], 'Settings page should have correct title' );
					break;
				}
			}
		}

		$this->assertTrue( $found_settings_page, 'Settings page should be registered' );
	}

	/**
	 * Test that admin pages are registered even when feature is disabled.
	 */
	public function test_admin_pages_registered_when_disabled() {
		// Disable the document generation toolkit.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_document_generation_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		global $submenu;

		// Load the initialization file.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Document_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is still registered.
		$parent_slug = 'edit.php?post_type=mcp_ai_doc_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Document Template submenu should exist even when disabled' );

		// Verify at least the Research & Add page is present.
		$found_research_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'research-document-template' ) !== false ) {
					$found_research_page = true;
					break;
				}
			}
		}

		$this->assertTrue( $found_research_page, 'Research & Add page should be registered even when feature is disabled' );
	}
}
