<?php
/**
 * Base+pro regression pins for the auxiliary load-gate batch.
 *
 * Covers the non-toolkit gate variants fixed alongside the toolkit inits:
 * the Shopify Sync init condition, the Extended Cognition and Vision
 * Analysis is_enabled() helpers, and the Telegram Mini App toolkit listing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * Auxiliary base+pro gate regression tests.
 */
class Test_BasePro_Aux_Gates extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
	}

	/**
	 * The Shopify Sync init must load its engine classes in base+pro.
	 *
	 * @return void
	 */
	public function test_shopify_sync_init_loads_with_pro_active(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded in this matrix.' );
		}

		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_shopify_sync_toolkit' ) );
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/init.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ), 'Shopify Sync engine must load in base+pro.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ), 'Shopify Sync CCT manager must load in base+pro.' );
	}

	/**
	 * The Vision Analysis is_enabled() helper must pass in base+pro.
	 *
	 * @return void
	 */
	public function test_vision_analysis_enabled_with_pro_active(): void {
		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_vision_analysis_toolkit' ) );
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/init.php';

		$this->assertTrue(
			wp_mcp_ai_vision_analysis_is_enabled(),
			'Vision Analysis must be enabled in base+pro with the toolkit on.'
		);
	}

	/**
	 * The Extended Cognition is_enabled() helper must pass in base+pro.
	 *
	 * @return void
	 */
	public function test_extended_cognition_enabled_with_pro_active(): void {
		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_extended_cognition_toolkit' ) );
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/init.php';

		$this->assertTrue(
			wp_mcp_ai_ext_cog_is_enabled(),
			'Extended Cognition must be enabled in base+pro with the toolkit on.'
		);
	}

	/**
	 * The Telegram Mini App toolkit listing must include the media toolkit
	 * in base+pro when the media setting is on.
	 *
	 * @return void
	 */
	public function test_telegram_mini_app_lists_media_toolkit(): void {
		if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Controller' ) ) {
			$this->markTestSkipped( 'Telegram Mini App controller is not loaded in this matrix.' );
		}

		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_media_toolkit' ) );

		$controller = new WP_MCP_AI_Telegram_Mini_App_Controller();
		$active     = $controller->get_active_toolkits();

		$this->assertArrayHasKey(
			'_always_media',
			$active,
			'The media toolkit must be listed as active in base+pro.'
		);
	}
}
