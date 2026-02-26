<?php
/**
 * Tests for Image Template Admin Pages
 *
 * Verifies that Research & Add and Settings pages are properly registered
 * under the Image Templates menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test image template admin page registration.
 */
class Test_Image_Template_Admin_Pages extends WP_UnitTestCase {

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
	 * Test that Image Template CPT is registered.
	 */
	public function test_image_template_cpt_registered() {
		// Initialize the CPT.
		WP_MCP_AI_Image_Template_CPT::init();
		do_action( 'init' );

		// Check if the post type is registered.
		$post_type = get_post_type_object( 'mcp_ai_image_tpl' );
		$this->assertNotNull( $post_type, 'Image Template CPT should be registered' );
		$this->assertTrue( $post_type->show_in_menu, 'Image Template CPT should show in menu' );
	}

	/**
	 * Test that Research & Add page is registered.
	 */
	public function test_research_add_page_registered() {
		global $submenu;

		// Load the initialization file to ensure admin pages are loaded.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Image_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_image_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Image Template should have submenu items' );

		// Find the Research & Add page.
		$found_research_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'research-image-template' ) !== false ) {
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
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Image_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_image_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Image Template should have submenu items' );

		// Find the Settings page.
		$found_settings_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'image-production-settings' ) !== false ) {
					$found_settings_page = true;
					$this->assertSame( 'Image Settings', $item[0], 'Settings page should have correct title' );
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
		// Disable the image production toolkit.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_image_production_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		global $submenu;

		// Load the initialization file.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Image_Template_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is still registered.
		$parent_slug = 'edit.php?post_type=mcp_ai_image_tpl';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Image Template submenu should exist even when disabled' );

		// Verify at least the Research & Add page is present.
		$found_research_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'research-image-template' ) !== false ) {
					$found_research_page = true;
					break;
				}
			}
		}

		$this->assertTrue( $found_research_page, 'Research & Add page should be registered even when feature is disabled' );

		// Also verify Settings page is present.
		$found_settings_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'image-production-settings' ) !== false ) {
					$found_settings_page = true;
					break;
				}
			}
		}

		$this->assertTrue( $found_settings_page, 'Settings page should be registered even when feature is disabled' );
	}

	/**
	 * Test that Settings page has tabbed interface.
	 */
	public function test_settings_page_has_tabs() {
		// Load the settings page class.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
		}
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Image_Production_Settings_Page();

		// Verify the class has the required methods for tabs.
		$this->assertTrue( method_exists( $settings_page, 'render_overview_tab' ), 'Settings page should have render_overview_tab method' );
		$this->assertTrue( method_exists( $settings_page, 'get_tools_list' ), 'Settings page should have get_tools_list method' );

		// Verify tools list is not empty.
		$reflection = new ReflectionClass( $settings_page );
		$method     = $reflection->getMethod( 'get_tools_list' );
		$method->setAccessible( true );
		$tools = $method->invoke( $settings_page );

		$this->assertIsArray( $tools, 'Tools list should be an array' );
		$this->assertNotEmpty( $tools, 'Tools list should not be empty' );
	}

	/**
	 * Test image production settings sanitization.
	 */
	public function test_image_production_settings_sanitization() {
		// Load the settings page class.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
		}
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Image_Production_Settings_Page();

		// Test sanitization of image generator.
		$input = array(
			'default_image_generator' => 'dalle',
		);
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertSame( 'dalle', $sanitized['default_image_generator'], 'Default image generator should be sanitized correctly' );

		// Test sanitization of output format.
		$input = array(
			'default_output_format' => 'png',
		);
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertSame( 'png', $sanitized['default_output_format'], 'Default output format should be sanitized correctly' );

		// Test sanitization of max dimensions.
		$input = array(
			'max_image_width'  => 2048,
			'max_image_height' => 2048,
		);
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertSame( 2048, $sanitized['max_image_width'], 'Max image width should be sanitized correctly' );
		$this->assertSame( 2048, $sanitized['max_image_height'], 'Max image height should be sanitized correctly' );
	}
}
