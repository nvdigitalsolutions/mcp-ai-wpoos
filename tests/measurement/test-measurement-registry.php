<?php
/**
 * Tests for the Measurement Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Measurement Registry.
 */
class Test_WP_MCP_AI_Measurement_Registry extends WP_UnitTestCase {

	/**
	 * Registry instance.
	 *
	 * @var WP_MCP_AI_Measurement_Registry
	 */
	private $registry;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		$this->registry = WP_MCP_AI_Measurement_Registry::get_instance();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Measurement_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Registry is a singleton.
	 */
	public function test_singleton() {
		$a = WP_MCP_AI_Measurement_Registry::get_instance();
		$b = WP_MCP_AI_Measurement_Registry::get_instance();
		$this->assertSame( $a, $b );
	}

	/**
	 * Register a well-formed metric succeeds.
	 */
	public function test_register_valid_metric() {
		$ok = $this->registry->register(
			array(
				'id'             => 'tool.execution.success_rate',
				'label'          => 'Tool Success Rate',
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_RATE,
				'unit'           => 'ratio',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_HIGHER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => 'tool.execution.unjustified_confidence',
				'goodhart_note'  => 'Blindly optimizing could hide abstention.',
			)
		);
		$this->assertTrue( $ok );
		$def = $this->registry->get( 'tool.execution.success_rate' );
		$this->assertNotNull( $def );
		$this->assertSame( 'rate', $def['type'] );
		$this->assertSame( 'ratio', $def['unit'] );
	}

	/**
	 * Invalid type is rejected.
	 */
	public function test_register_rejects_invalid_type() {
		$ok = $this->registry->register(
			array(
				'id'    => 'bad.metric',
				'label' => 'Bad',
				'type'  => 'not-a-real-type',
				'unit'  => 'x',
			)
		);
		$this->assertFalse( $ok );
	}

	/**
	 * Missing required fields are rejected.
	 */
	public function test_register_rejects_missing_required() {
		$this->assertFalse( $this->registry->register( array() ) );
		$this->assertFalse( $this->registry->register( array( 'id' => 'a.b', 'label' => 'x', 'type' => 'counter' ) ) );
		$this->assertFalse( $this->registry->register( array( 'id' => 'a.b', 'label' => '', 'type' => 'counter', 'unit' => 'x' ) ) );
	}

	/**
	 * Duplicate registration is a no-op.
	 */
	public function test_duplicate_registration_returns_false() {
		$def = array(
			'id'    => 'dup.metric',
			'label' => 'Dup',
			'type'  => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
			'unit'  => 'count',
		);
		$this->assertTrue( $this->registry->register( $def ) );
		$this->assertFalse( $this->registry->register( $def ) );
	}

	/**
	 * Invalid privacy tier falls back to internal.
	 */
	public function test_invalid_privacy_tier_falls_back() {
		$this->registry->register(
			array(
				'id'           => 'priv.test',
				'label'        => 'Priv',
				'type'         => WP_MCP_AI_Measurement_Registry::TYPE_GAUGE,
				'unit'         => 'ms',
				'privacy_tier' => 'bogus',
			)
		);
		$def = $this->registry->get( 'priv.test' );
		$this->assertSame( WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL, $def['privacy_tier'] );
	}

	/**
	 * Counter-metric Goodhart warning surfaces unpaired metrics.
	 */
	public function test_metrics_without_counter() {
		$this->registry->register(
			array(
				'id'    => 'solo.metric',
				'label' => 'Solo',
				'type'  => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'  => 'count',
			)
		);
		$this->registry->register(
			array(
				'id'             => 'paired.metric',
				'label'          => 'Paired',
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'           => 'count',
				'counter_metric' => 'something.else',
			)
		);
		$without = $this->registry->metrics_without_counter();
		$this->assertContains( 'solo.metric', $without );
		$this->assertNotContains( 'paired.metric', $without );
	}

	/**
	 * Boot fires the registration action exactly once.
	 */
	public function test_boot_fires_action_once() {
		$counter = 0;
		add_action(
			'wp_mcp_ai_register_metrics',
			function ( $registry ) use ( &$counter ) {
				++$counter;
				$this->assertInstanceOf( 'WP_MCP_AI_Measurement_Registry', $registry );
			}
		);
		$this->registry->boot();
		$this->registry->boot();
		$this->assertSame( 1, $counter );
	}

	/**
	 * Privacy tier filter returns the correct subset.
	 */
	public function test_by_privacy_tier() {
		$this->registry->register( array( 'id' => 'm1', 'label' => 'm1', 'type' => 'counter', 'unit' => 'n', 'privacy_tier' => 'public' ) );
		$this->registry->register( array( 'id' => 'm2', 'label' => 'm2', 'type' => 'counter', 'unit' => 'n', 'privacy_tier' => 'sensitive' ) );
		$public = $this->registry->by_privacy_tier( 'public' );
		$this->assertCount( 1, $public );
		$this->assertArrayHasKey( 'm1', $public );
	}
}
