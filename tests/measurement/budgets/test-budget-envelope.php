<?php
/**
 * Tests for the Budget Envelope value object.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Budget Envelope.
 */
class Test_WP_MCP_AI_Budget_Envelope extends WP_UnitTestCase {

	public function test_requires_slug() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Budget_Envelope( array( 'metric_ids' => array( 'm' ), 'limit' => 1.0 ) );
	}

	public function test_requires_metric_ids() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Budget_Envelope( array( 'slug' => 's', 'limit' => 1.0 ) );
	}

	public function test_requires_positive_limit() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Budget_Envelope(
			array( 'slug' => 's', 'metric_ids' => array( 'm' ), 'limit' => 0 )
		);
	}

	public function test_defaults_and_normalization() {
		$e = new WP_MCP_AI_Budget_Envelope(
			array(
				'slug'       => 'daily_cost',
				'metric_ids' => array( ' Model.Cost ', 'tokens.in' ),
				'limit'      => 10.0,
			)
		);
		$this->assertSame( 'daily_cost', $e->get_slug() );
		// Slug defaults to label.
		$this->assertSame( 'daily_cost', $e->get_label() );
		// Metric ids are normalized to lowercase/trimmed/unique.
		$this->assertSame( array( 'model.cost', 'tokens.in' ), $e->get_metric_ids() );
		$this->assertTrue( $e->observes( 'MODEL.COST' ) );
		$this->assertFalse( $e->observes( 'unknown' ) );
		// Warn ratio defaults to 0.8.
		$this->assertEqualsWithDelta( 8.0, $e->get_warn_threshold(), 0.0001 );
		// Default scope is request.
		$this->assertSame( WP_MCP_AI_Budget_Envelope::SCOPE_REQUEST, $e->get_scope() );
	}

	public function test_warn_ratio_clamped() {
		$e = new WP_MCP_AI_Budget_Envelope(
			array( 'slug' => 's', 'metric_ids' => array( 'm' ), 'limit' => 10, 'warn_ratio' => 1.5 )
		);
		$this->assertSame( 1.0, $e->get_warn_ratio() );
		$e2 = new WP_MCP_AI_Budget_Envelope(
			array( 'slug' => 's', 'metric_ids' => array( 'm' ), 'limit' => 10, 'warn_ratio' => -0.2 )
		);
		$this->assertSame( 0.0, $e2->get_warn_ratio() );
	}

	public function test_invalid_scope_falls_back_to_request() {
		$e = new WP_MCP_AI_Budget_Envelope(
			array( 'slug' => 's', 'metric_ids' => array( 'm' ), 'limit' => 10, 'scope' => 'weirdo' )
		);
		$this->assertSame( WP_MCP_AI_Budget_Envelope::SCOPE_REQUEST, $e->get_scope() );
	}

	public function test_tags_sanitized() {
		$e = new WP_MCP_AI_Budget_Envelope(
			array(
				'slug'       => 's',
				'metric_ids' => array( 'm' ),
				'limit'      => 10,
				'tags'       => array( 'env' => 'prod', 42 => 'nope', 'weird-key' => array( 'x' ) ),
			)
		);
		$this->assertSame( array( 'env' => 'prod' ), $e->get_tags() );
	}
}
