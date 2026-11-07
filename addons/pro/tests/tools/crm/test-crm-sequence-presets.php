<?php
/**
 * Sequence preset tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Presets.
 */
class Test_CRM_Sequence_Presets extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-sequence-presets.php';
	}

	/**
	 * Short preset shape.
	 */
	public function test_short_preset() {
		$preset = WP_MCP_AI_CRM_Sequence_Presets::get( 'short' );
		$this->assertIsArray( $preset );
		$this->assertCount( 4, $preset['steps'] );
		$this->assertSame( 0, $preset['steps'][0]['link_budget'] );
		$this->assertSame( 75, $preset['steps'][0]['max_words'] );
		$this->assertTrue( $preset['steps'][0]['require_evidence'] );
	}

	/**
	 * Standard preset shape.
	 */
	public function test_standard_preset() {
		$preset = WP_MCP_AI_CRM_Sequence_Presets::get( 'standard' );
		$this->assertCount( 6, $preset['steps'] );
		$this->assertSame( 'breakup', $preset['steps'][5]['purpose'] );
	}

	/**
	 * Unknown preset errors.
	 */
	public function test_unknown_preset() {
		$this->assertWPError( WP_MCP_AI_CRM_Sequence_Presets::get( 'nope' ) );
	}
}
