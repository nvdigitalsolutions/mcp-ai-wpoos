<?php
/**
 * Tests the wp-admin commerce settings subtab grouping.
 *
 * The four Proposal 089 commerce toolkit sections keep their own registry
 * tabs (the settings schema and the SPA commerce pages filter fields by tab
 * id), but the wp-admin settings dashboard renders the three sync toolkits
 * (EZuite, Shopify Sync, FlowHub) as subtabs of the E-Commerce tab — the
 * Tools → Connections pattern — instead of top-level nav tabs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Commerce settings subtab grouping tests.
 */
class WP_MCP_AI_Commerce_Settings_Subtabs_Test extends WP_UnitTestCase {

	/**
	 * Set up per test.
	 */
	public function set_up() {
		parent::set_up();
		unset( $_GET['tab'], $_GET['subtab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness state reset.
	}

	/**
	 * Tear down per test.
	 */
	public function tear_down() {
		unset( $_GET['tab'], $_GET['subtab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness state reset.
		parent::tear_down();
	}

	/**
	 * Load the four commerce section classes (idempotent).
	 */
	private function load_commerce_sections() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/addons/pro/' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}

		$p = WP_MCP_AI_PRO_PATH;

		if ( ! class_exists( 'WP_MCP_AI_Commerce_Toolkit_Settings_Section' ) ) {
			require_once $p . 'includes/admin/sections/abstract-wp-mcp-ai-commerce-toolkit-section.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-ecommerce-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Ezuite_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-ezuite-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Shopify_Sync_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-shopify-sync-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_FlowHub_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-flowhub-toolkit.php';
		}
	}

	/**
	 * Invoke a protected method via reflection.
	 *
	 * @param object $section    Section instance.
	 * @param string $method     Method name.
	 * @return mixed
	 */
	private function invoke_protected( $section, $method ) {
		$reflection = new ReflectionClass( $section );
		$reflected  = $reflection->getMethod( $method );
		$reflected->setAccessible( true );

		return $reflected->invoke( $section );
	}

	/**
	 * Render a section wrapper to a string.
	 *
	 * @param object $section Section instance.
	 * @return string
	 */
	private function render_wrapper( $section ) {
		ob_start();
		$section->render_wrapper();
		return (string) ob_get_clean();
	}

	/**
	 * Test that the commerce subtab catalogue lists the four toolkits in
	 * navigation order with E-Commerce first.
	 */
	public function test_subtab_groups_are_in_navigation_order() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$host   = new WP_MCP_AI_Section_Ecommerce_Toolkit();
		$groups = $this->invoke_protected( $host, 'get_commerce_subtab_groups' );

		$this->assertSame(
			array( 'ecommerce', 'ezuite', 'shopify_sync', 'flowhub' ),
			array_keys( $groups ),
			'The four commerce toolkits should form the subtab catalogue in navigation order'
		);
	}

	/**
	 * Test that the active subtab defaults to E-Commerce.
	 */
	public function test_active_subtab_defaults_to_ecommerce() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$host = new WP_MCP_AI_Section_Ecommerce_Toolkit();
		$this->assertSame( 'ecommerce', $this->invoke_protected( $host, 'get_active_subtab' ) );
	}

	/**
	 * Test that the active subtab honours the subtab URL parameter.
	 */
	public function test_active_subtab_reads_subtab_parameter() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Shopify_Sync_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$_GET['subtab'] = 'shopify_sync'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.

		$shopify = new WP_MCP_AI_Section_Shopify_Sync_Toolkit();
		$this->assertSame( 'shopify_sync', $this->invoke_protected( $shopify, 'get_active_subtab' ) );
	}

	/**
	 * Test that a legacy ?tab=<toolkit> URL (no subtab) lands on the right
	 * toolkit subtab.
	 */
	public function test_legacy_tab_parameter_maps_to_subtab() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_FlowHub_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$_GET['tab'] = 'flowhub'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.

		$flowhub = new WP_MCP_AI_Section_FlowHub_Toolkit();
		$this->assertSame( 'flowhub', $this->invoke_protected( $flowhub, 'get_active_subtab' ) );
	}

	/**
	 * Test that the section → tab mapping is unchanged (the settings schema
	 * and the SPA commerce pages filter fields by these tab ids).
	 */
	public function test_section_tab_ids_keep_the_spa_contract() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$this->assertSame( 'ecommerce', ( new WP_MCP_AI_Section_Ecommerce_Toolkit() )->get_tab() );
		$this->assertSame( 'ezuite', ( new WP_MCP_AI_Section_Ezuite_Toolkit() )->get_tab() );
		$this->assertSame( 'shopify_sync', ( new WP_MCP_AI_Section_Shopify_Sync_Toolkit() )->get_tab() );
		$this->assertSame( 'flowhub', ( new WP_MCP_AI_Section_FlowHub_Toolkit() )->get_tab() );
	}

	/**
	 * Test that the host (E-Commerce) section renders the subtab navigation
	 * and its own fields on the default subtab.
	 */
	public function test_host_renders_subtab_nav_and_own_fields() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$_GET['tab'] = 'ecommerce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.

		$host = new WP_MCP_AI_Section_Ecommerce_Toolkit();
		$html = $this->render_wrapper( $host );

		$this->assertStringContainsString( 'wp-mcp-ai-subtab-nav', $html, 'Host should render the subtab nav' );
		$this->assertStringContainsString( 'wp_mcp_ai_settings[ecommerce_safety_buffer]', $html, 'Host should render its own fields on the default subtab' );
		$this->assertStringContainsString( 'name="subtab" value="ecommerce"', $html, 'Host should emit the hidden subtab field' );
	}

	/**
	 * Test that the host renders the subtab nav but not its own fields when
	 * a child subtab is active.
	 */
	public function test_host_hides_own_fields_when_child_subtab_active() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$_GET['tab']    = 'ecommerce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.
		$_GET['subtab'] = 'flowhub'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.

		$host = new WP_MCP_AI_Section_Ecommerce_Toolkit();
		$html = $this->render_wrapper( $host );

		$this->assertStringContainsString( 'wp-mcp-ai-subtab-nav', $html, 'Host should keep the subtab nav visible' );
		$this->assertStringNotContainsString( 'wp_mcp_ai_settings[ecommerce_safety_buffer]', $html, 'Host should not render its fields while a child subtab is active' );
	}

	/**
	 * Test that a child section renders only while its own subtab is active.
	 */
	public function test_child_section_renders_only_when_active() {
		$this->load_commerce_sections();

		if ( ! class_exists( 'WP_MCP_AI_Section_Shopify_Sync_Toolkit' ) ) {
			$this->markTestSkipped( 'Pro commerce sections are not loaded.' );
		}

		$shopify = new WP_MCP_AI_Section_Shopify_Sync_Toolkit();

		$_GET['subtab'] = 'shopify_sync'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.
		$active_html    = $this->render_wrapper( $shopify );
		$this->assertStringContainsString( 'wp_mcp_ai_settings[shopify_sync_safety_buffer]', $active_html, 'Active child section should render its fields' );

		$_GET['subtab'] = 'ezuite'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test input.
		$inactive_html  = $this->render_wrapper( $shopify );
		$this->assertSame( '', trim( $inactive_html ), 'Inactive child section should render nothing' );
	}
}
