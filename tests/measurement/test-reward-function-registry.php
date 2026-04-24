<?php
/**
 * Tests for the Reward Function Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Reward Function Registry.
 */
class Test_WP_MCP_AI_Reward_Function_Registry extends WP_UnitTestCase {

	/**
	 * Registry.
	 *
	 * @var WP_MCP_AI_Reward_Function_Registry
	 */
	private $registry;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		$this->registry = WP_MCP_AI_Reward_Function_Registry::get_instance();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * A valid reward function registers successfully.
	 */
	public function test_register_valid() {
		$ok = $this->registry->register(
			array(
				'slug'        => 'verified_success',
				'label'       => 'Verified Success',
				'callback'    => static function ( $inputs ) {
					return $inputs['success'] ? 1.0 : 0.0;
				},
				'output_min'  => 0.0,
				'output_max'  => 1.0,
				'inputs'      => array( 'success' ),
				'anti_gaming' => 'Paired with cost.per_success so agents cannot claim success cheaply.',
			)
		);
		$this->assertTrue( $ok );
	}

	/**
	 * Missing anti-gaming safeguard is rejected.
	 */
	public function test_missing_anti_gaming_rejected() {
		$result = $this->registry->register(
			array(
				'slug'       => 'no_safeguard',
				'label'      => 'No Safeguard',
				'callback'   => static function () {
					return 1.0;
				},
				'output_min' => 0.0,
				'output_max' => 1.0,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_reward_missing_anti_gaming', $result->get_error_code() );
	}

	/**
	 * Invalid range is rejected.
	 */
	public function test_invalid_range_rejected() {
		$result = $this->registry->register(
			array(
				'slug'        => 'bad_range',
				'label'       => 'Bad',
				'callback'    => static function () {
					return 1.0;
				},
				'output_min'  => 1.0,
				'output_max'  => 0.0,
				'anti_gaming' => 'ok',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_reward_invalid_range', $result->get_error_code() );
	}

	/**
	 * Invalid callback is rejected.
	 */
	public function test_invalid_callback_rejected() {
		$result = $this->registry->register(
			array(
				'slug'        => 'no_cb',
				'label'       => 'No Callback',
				'callback'    => 'does_not_exist_xyz_function',
				'output_min'  => 0.0,
				'output_max'  => 1.0,
				'anti_gaming' => 'safeguard',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_reward_invalid_callback', $result->get_error_code() );
	}

	/**
	 * Missing inputs are detected at evaluate time.
	 */
	public function test_missing_inputs_detected() {
		$this->registry->register(
			array(
				'slug'        => 'needs_inputs',
				'label'       => 'Needs inputs',
				'callback'    => static function ( $inputs ) {
					return $inputs['a'] + $inputs['b'];
				},
				'output_min'  => 0.0,
				'output_max'  => 10.0,
				'inputs'      => array( 'a', 'b' ),
				'anti_gaming' => 'bounded by [0,10] with counter-metric.',
			)
		);
		$result = $this->registry->evaluate( 'needs_inputs', array( 'a' => 1 ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_reward_missing_input', $result->get_error_code() );
	}

	/**
	 * Output is clamped into the declared range.
	 */
	public function test_output_clamped() {
		$this->registry->register(
			array(
				'slug'        => 'clamped',
				'label'       => 'Clamped',
				'callback'    => static function () {
					return 99.0;
				},
				'output_min'  => 0.0,
				'output_max'  => 1.0,
				'anti_gaming' => 'bounded',
			)
		);
		$value = $this->registry->evaluate( 'clamped', array() );
		$this->assertSame( 1.0, $value );
	}

	/**
	 * Non-numeric callback output is a WP_Error.
	 */
	public function test_non_numeric_return_is_error() {
		$this->registry->register(
			array(
				'slug'        => 'bad_return',
				'label'       => 'Bad',
				'callback'    => static function () {
					return 'not-numeric';
				},
				'output_min'  => 0.0,
				'output_max'  => 1.0,
				'anti_gaming' => 'bounded',
			)
		);
		$result = $this->registry->evaluate( 'bad_return', array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_reward_non_numeric', $result->get_error_code() );
	}
}
