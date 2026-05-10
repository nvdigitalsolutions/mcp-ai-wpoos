<?php
/**
 * Tests for WP_MCP_AI_Memory_Capture_Service.
 *
 * Covers singleton behaviour, envelope validation (agent_id, wing, room, content),
 * redaction filter hand-off, tier/sensitivity capping, and wing override lookups.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Memory_Capture_Service.
 */
class Test_Service_Memory_Capture extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Memory_Capture_Service
	 */
	private $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Disable the transient store leg so tests are headless (no context-manager dep).
		add_filter( 'wp_mcp_ai_memory_capture_skip_transient', '__return_true' );

		$this->service = WP_MCP_AI_Memory_Capture_Service::get_instance();

		// Clean up any wing retention overrides set by previous tests.
		delete_option( WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_memory_capture_skip_transient', '__return_true' );
		delete_option( WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION );
		parent::tearDown();
	}

	/**
	 * Test that get_instance always returns the same object.
	 */
	public function test_get_instance_returns_singleton() {
		$a = WP_MCP_AI_Memory_Capture_Service::get_instance();
		$b = WP_MCP_AI_Memory_Capture_Service::get_instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * Test that store() fails with error when agent_id is absent.
	 */
	public function test_store_fails_without_agent_id() {
		$result = $this->service->store( array(
			'wing'    => 'patient/test',
			'room'    => 'vitals',
			'content' => 'Test content',
		) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertSame( 'mempalace_capture_missing_agent', $result['code'] );
	}

	/**
	 * Test that store() fails with error when wing is absent.
	 */
	public function test_store_fails_without_wing() {
		$result = $this->service->store( array(
			'agent_id' => 'agent-1',
			'room'     => 'vitals',
			'content'  => 'Test content',
		) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'mempalace_capture_missing_wing', $result['code'] );
	}

	/**
	 * Test that store() fails with error when room is absent.
	 */
	public function test_store_fails_without_room() {
		$result = $this->service->store( array(
			'agent_id' => 'agent-1',
			'wing'     => 'patient/test',
			'content'  => 'Test content',
		) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'mempalace_capture_missing_room', $result['code'] );
	}

	/**
	 * Test that store() fails with error when content is absent.
	 */
	public function test_store_fails_without_content() {
		$result = $this->service->store( array(
			'agent_id' => 'agent-1',
			'wing'     => 'patient/test',
			'room'     => 'vitals',
		) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'mempalace_capture_missing_content', $result['code'] );
	}

	/**
	 * Test that store() succeeds with a valid minimal envelope.
	 */
	public function test_store_succeeds_with_valid_minimal_envelope() {
		$result = $this->service->store( array(
			'agent_id' => 'agent-1',
			'wing'     => 'patient/test',
			'room'     => 'vitals',
			'content'  => 'Patient blood pressure 120/80.',
		) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'context_id', $result );
		$this->assertNotEmpty( $result['context_id'] );
		$this->assertArrayHasKey( 'tier', $result );
		$this->assertArrayHasKey( 'wing', $result );
		$this->assertArrayHasKey( 'room', $result );
	}

	/**
	 * Test that get_wing_overrides returns empty array for unknown wing.
	 */
	public function test_get_wing_overrides_returns_empty_for_unknown_wing() {
		$overrides = $this->service->get_wing_overrides( 'no/such/wing' );
		$this->assertIsArray( $overrides );
		$this->assertEmpty( $overrides );
	}

	/**
	 * Test that get_wing_overrides returns empty array when wing is empty string.
	 */
	public function test_get_wing_overrides_returns_empty_for_empty_wing() {
		$overrides = $this->service->get_wing_overrides( '' );
		$this->assertIsArray( $overrides );
		$this->assertEmpty( $overrides );
	}

	/**
	 * Test that per-wing tier_ceiling is enforced during store().
	 *
	 * If a wing has tier_ceiling = 'recall', a caller requesting 'core'
	 * must be capped down to 'recall'.
	 */
	public function test_store_respects_tier_ceiling_override() {
		update_option(
			WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION,
			array(
				'patient/test' => array(
					'tier_ceiling' => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
				),
			)
		);

		$result = $this->service->store( array(
			'agent_id' => 'agent-1',
			'wing'     => 'patient/test',
			'room'     => 'vitals',
			'content'  => 'Test capture.',
			'tier'     => WP_MCP_AI_Memory_Capture_Service::TIER_CORE, // should be capped
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_RECALL, $result['tier'] );
	}

	/**
	 * Test that the pre-store transform filter is applied.
	 */
	public function test_store_applies_pre_store_transform_filter() {
		$filter_fired = false;

		add_filter(
			'wp_mcp_ai_memory_pre_store_transform',
			function ( $envelope ) use ( &$filter_fired ) {
				$filter_fired = true;
				return $envelope;
			},
			10,
			2
		);

		$this->service->store( array(
			'agent_id' => 'agent-1',
			'wing'     => 'patient/test',
			'room'     => 'vitals',
			'content'  => 'Test capture.',
		) );

		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );

		$this->assertTrue( $filter_fired, 'wp_mcp_ai_memory_pre_store_transform filter was not applied.' );
	}
}
