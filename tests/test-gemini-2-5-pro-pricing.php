<?php
/**
 * Test Gemini 2.5 Pro pricing update.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for verifying Gemini 2.5 Pro pricing.
 */
class Test_Gemini_25_Pro_Pricing extends WP_UnitTestCase {

	/**
	 * Test that gemini-2.5-pro has correct pricing.
	 */
	// phpcs:ignore PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Found -- False positive: PHPUnit test method, not a PHP4 constructor.
	public function test_gemini_25_pro_pricing() {
		$config = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-pro' );

		$this->assertIsArray( $config, 'gemini-2.5-pro should have a configuration' );
		$this->assertArrayHasKey( 'cost_per_1k', $config, 'Configuration should have cost_per_1k' );
		$this->assertEquals( 0.003, $config['cost_per_1k'], 'gemini-2.5-pro cost should be $0.003 per 1k tokens ($3 per 1M tokens)' );
	}

	/**
	 * Test that gemini-2.5-pro has correct provider.
	 */
	public function test_gemini_25_pro_provider() {
		$config = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-pro' );

		$this->assertIsArray( $config, 'gemini-2.5-pro should have a configuration' );
		$this->assertEquals( 'gemini', $config['provider'], 'gemini-2.5-pro should have gemini provider' );
		$this->assertEquals( 'Gemini 2.5 Pro', $config['name'], 'gemini-2.5-pro should have correct name' );
	}

	/**
	 * Test that gemini-2.5-pro has correct context window.
	 */
	public function test_gemini_25_pro_context_window() {
		$config = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-pro' );

		$this->assertIsArray( $config, 'gemini-2.5-pro should have a configuration' );
		$this->assertEquals( 2000000, $config['context_window'], 'gemini-2.5-pro should have 2M token context window' );
	}

	/**
	 * Test that gemini-2.5-pro pricing is different from gemini-2.5-flash.
	 */
	public function test_gemini_25_pro_vs_flash_pricing() {
		$pro_config   = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-pro' );
		$flash_config = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-flash' );

		$this->assertIsArray( $pro_config, 'gemini-2.5-pro should have a configuration' );
		$this->assertIsArray( $flash_config, 'gemini-2.5-flash should have a configuration' );

		// Pro should be more expensive than Flash.
		$this->assertGreaterThan(
			$flash_config['cost_per_1k'],
			$pro_config['cost_per_1k'],
			'gemini-2.5-pro should be more expensive than gemini-2.5-flash'
		);
	}
}
