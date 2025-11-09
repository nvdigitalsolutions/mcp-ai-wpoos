<?php
/**
 * Tests for Displays Dashboard Admin Page
 *
 * Verifies that the Displays Dashboard page is properly registered,
 * appears in the correct menu position, and functions correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Displays Dashboard functionality.
 */
class Test_Admin_Displays_Dashboard extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that Displays Dashboard page is registered.
	 */
	public function test_displays_dashboard_page_registered() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Verify the page is registered under wp-mcp-ai-dashboard menu.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu, 'WP oOS menu should exist' );

		// Find the Displays Dashboard submenu item.
		$found = false;
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( 'wp-mcp-ai-displays' === $item[2] ) {
				$found = true;
				$this->assertEquals( 'Displays Dashboard', $item[0], 'Menu title should be "Displays Dashboard"' );
				$this->assertEquals( 'manage_options', $item[1], 'Required capability should be manage_options' );
				break;
			}
		}

		$this->assertTrue( $found, 'Displays Dashboard submenu item should be registered' );
	}

	/**
	 * Test that Displays Dashboard page stores hook suffix.
	 */
	public function test_displays_dashboard_page_hook() {
		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( $displays_dashboard );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue( $displays_dashboard );

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'Displays Dashboard page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-displays', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that Displays Dashboard appears before JetEngine in menu.
	 */
	public function test_displays_dashboard_appears_before_jetengine() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Verify the page is registered.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu, 'WP oOS menu should exist' );

		// Find positions of Displays Dashboard and JetEngine.
		$displays_position = null;
		$jetengine_position = null;

		foreach ( $submenu['wp-mcp-ai-dashboard'] as $index => $item ) {
			if ( 'wp-mcp-ai-displays' === $item[2] ) {
				$displays_position = $index;
			}
			if ( 'wp-mcp-ai-jetengine' === $item[2] ) {
				$jetengine_position = $index;
			}
		}

		// Verify both pages are registered.
		$this->assertNotNull( $displays_position, 'Displays Dashboard should be registered' );
		$this->assertNotNull( $jetengine_position, 'JetEngine page should be registered' );

		// Verify Displays Dashboard appears before JetEngine.
		$this->assertLessThan(
			$jetengine_position,
			$displays_position,
			'Displays Dashboard should appear before JetEngine in menu'
		);
	}

	/**
	 * Test that enqueue_assets method respects its hook suffix.
	 */
	public function test_enqueue_assets_respects_hook() {
		global $wp_scripts, $wp_styles;

		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the actual hook suffix.
		$reflection = new ReflectionClass( $displays_dashboard );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$actual_hook = $property->getValue( $displays_dashboard );

		// Reset scripts and styles.
		$wp_scripts = null;
		$wp_styles  = null;

		// Trigger enqueue with a different hook.
		do_action( 'admin_enqueue_scripts', 'some-other-page' );

		// Verify scripts are NOT enqueued on other pages.
		if ( isset( $wp_scripts->registered['wp-mcp-ai-displays-dashboard'] ) ) {
			$this->fail( 'Displays Dashboard scripts should not be enqueued on other pages' );
		}

		// Reset scripts and styles.
		$wp_scripts = null;
		$wp_styles  = null;

		// Trigger enqueue with the correct hook.
		do_action( 'admin_enqueue_scripts', $actual_hook );

		// Verify scripts ARE enqueued on the correct page.
		$this->assertTrue(
			isset( $wp_scripts->registered['wp-mcp-ai-displays-dashboard'] ),
			'Displays Dashboard scripts should be enqueued on the correct page'
		);

		// Verify styles ARE enqueued on the correct page.
		$this->assertTrue(
			isset( $wp_styles->registered['wp-mcp-ai-displays-dashboard'] ),
			'Displays Dashboard styles should be enqueued on the correct page'
		);
	}

	/**
	 * Test that localized script data is properly set.
	 */
	public function test_localized_script_data() {
		global $wp_scripts;

		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the actual hook suffix.
		$reflection = new ReflectionClass( $displays_dashboard );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$actual_hook = $property->getValue( $displays_dashboard );

		// Reset scripts.
		$wp_scripts = null;

		// Trigger enqueue with the correct hook.
		do_action( 'admin_enqueue_scripts', $actual_hook );

		// Verify the script is enqueued.
		$this->assertTrue(
			isset( $wp_scripts->registered['wp-mcp-ai-displays-dashboard'] ),
			'Displays Dashboard script should be enqueued'
		);

		// Verify localized data is present.
		$this->assertNotEmpty(
			$wp_scripts->registered['wp-mcp-ai-displays-dashboard']->extra,
			'Localized script data should be present'
		);

		// Get the localized data.
		$localized_data = null;
		if ( isset( $wp_scripts->registered['wp-mcp-ai-displays-dashboard']->extra['data'] ) ) {
			$localized_data = $wp_scripts->registered['wp-mcp-ai-displays-dashboard']->extra['data'];
		}

		$this->assertNotNull( $localized_data, 'Localized data should exist' );
		$this->assertStringContainsString( 'wpMcpAiDisplays', $localized_data, 'Localized object name should be present' );
		$this->assertStringContainsString( 'ajaxUrl', $localized_data, 'Ajax URL should be localized' );
		$this->assertStringContainsString( 'nonce', $localized_data, 'Nonce should be localized' );
	}

	/**
	 * Test that render_page method requires proper capabilities.
	 */
	public function test_render_page_requires_capabilities() {
		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Set up a user without proper capabilities.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		// Expect wp_die to be called.
		$this->expectException( WPDieException::class );

		// Try to render the page.
		$displays_dashboard->render_page();
	}

	/**
	 * Test that get_elementor_widgets method returns expected structure.
	 */
	public function test_get_elementor_widgets_structure() {
		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $displays_dashboard );
		$method = $reflection->getMethod( 'get_elementor_widgets' );
		$method->setAccessible( true );

		// Call the method.
		$widgets = $method->invoke( $displays_dashboard );

		// Verify structure.
		$this->assertIsArray( $widgets, 'get_elementor_widgets should return an array' );
		$this->assertArrayHasKey( 'chat', $widgets, 'Should have chat category' );
		$this->assertArrayHasKey( 'assistant', $widgets, 'Should have assistant category' );
		$this->assertArrayHasKey( 'dashboard', $widgets, 'Should have dashboard category' );
		$this->assertArrayHasKey( 'performance', $widgets, 'Should have performance category' );
		$this->assertArrayHasKey( 'system', $widgets, 'Should have system category' );

		// Verify each category has required keys.
		foreach ( $widgets as $category ) {
			$this->assertArrayHasKey( 'title', $category, 'Each category should have a title' );
			$this->assertArrayHasKey( 'widgets', $category, 'Each category should have widgets array' );
			$this->assertIsArray( $category['widgets'], 'Widgets should be an array' );
		}

		// Verify widget structure.
		foreach ( $widgets['chat']['widgets'] as $widget ) {
			$this->assertArrayHasKey( 'name', $widget, 'Widget should have a name' );
			$this->assertArrayHasKey( 'slug', $widget, 'Widget should have a slug' );
			$this->assertArrayHasKey( 'description', $widget, 'Widget should have a description' );
			$this->assertArrayHasKey( 'icon', $widget, 'Widget should have an icon' );
		}
	}

	/**
	 * Test that get_gutenberg_blocks method returns expected structure.
	 */
	public function test_get_gutenberg_blocks_structure() {
		// Get the global instance.
		$displays_dashboard = $GLOBALS['wp_mcp_ai_admin_displays_dashboard'];

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $displays_dashboard );
		$method = $reflection->getMethod( 'get_gutenberg_blocks' );
		$method->setAccessible( true );

		// Call the method.
		$blocks = $method->invoke( $displays_dashboard );

		// Verify structure.
		$this->assertIsArray( $blocks, 'get_gutenberg_blocks should return an array' );
		$this->assertArrayHasKey( 'chat', $blocks, 'Should have chat category' );
		$this->assertArrayHasKey( 'assistant', $blocks, 'Should have assistant category' );
		$this->assertArrayHasKey( 'dashboard', $blocks, 'Should have dashboard category' );
		$this->assertArrayHasKey( 'performance', $blocks, 'Should have performance category' );

		// Verify each category has required keys.
		foreach ( $blocks as $category ) {
			$this->assertArrayHasKey( 'title', $category, 'Each category should have a title' );
			$this->assertArrayHasKey( 'blocks', $category, 'Each category should have blocks array' );
			$this->assertIsArray( $category['blocks'], 'Blocks should be an array' );
		}

		// Verify block structure.
		foreach ( $blocks['chat']['blocks'] as $block ) {
			$this->assertArrayHasKey( 'name', $block, 'Block should have a name' );
			$this->assertArrayHasKey( 'slug', $block, 'Block should have a slug' );
			$this->assertArrayHasKey( 'description', $block, 'Block should have a description' );
			$this->assertArrayHasKey( 'icon', $block, 'Block should have an icon' );
		}
	}

	/**
	 * Test that page slug constant is defined correctly.
	 */
	public function test_page_slug_constant() {
		$this->assertEquals(
			'wp-mcp-ai-displays',
			WP_MCP_AI_Admin_Displays_Dashboard::PAGE_SLUG,
			'PAGE_SLUG constant should be wp-mcp-ai-displays'
		);
	}
}
