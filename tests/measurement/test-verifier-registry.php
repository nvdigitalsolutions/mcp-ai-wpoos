<?php
/**
 * Tests for the Verifier Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Fixture verifier that always passes with a fixed score.
 */
class WP_MCP_AI_Test_Fake_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->slug                 = 'fake_verifier';
		$this->label                = 'Fake';
		$this->kind                 = 'rule';
		$this->independence_profile = array(
			'disallowed_providers' => array( 'openai' ),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
	}

	/**
	 * @param array $subject Subject.
	 * @param array $context Context.
	 * @return array
	 */
	public function verify( array $subject, array $context = array() ) {
		return $this->result_pass( 0.9, 0.8, array( 'ok' ), array( 'input' => $subject ) );
	}
}

/**
 * Test Verifier Registry.
 */
class Test_WP_MCP_AI_Verifier_Registry extends WP_UnitTestCase {

	/**
	 * Registry.
	 *
	 * @var WP_MCP_AI_Verifier_Registry
	 */
	private $registry;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Verifier_Registry::reset_instance();
		$this->registry = WP_MCP_AI_Verifier_Registry::get_instance();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Verifier_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Register and lookup.
	 */
	public function test_register_and_get() {
		$v = new WP_MCP_AI_Test_Fake_Verifier();
		$this->assertTrue( $this->registry->register( $v ) );
		$this->assertSame( $v, $this->registry->get( 'fake_verifier' ) );
	}

	/**
	 * Duplicate registration is rejected.
	 */
	public function test_duplicate_registration_rejected() {
		$this->registry->register( new WP_MCP_AI_Test_Fake_Verifier() );
		$this->assertFalse( $this->registry->register( new WP_MCP_AI_Test_Fake_Verifier() ) );
	}

	/**
	 * Independence check blocks disallowed provider.
	 */
	public function test_independence_blocks_disallowed_provider() {
		$v      = new WP_MCP_AI_Test_Fake_Verifier();
		$this->registry->register( $v );
		$result = $this->registry->run(
			'fake_verifier',
			array( 'output' => 'x' ),
			array(),
			array( 'provider' => 'openai' )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_verifier_not_independent', $result->get_error_code() );
	}

	/**
	 * Independence check allows unrelated provider.
	 */
	public function test_independence_allows_other_provider() {
		$v = new WP_MCP_AI_Test_Fake_Verifier();
		$this->registry->register( $v );
		$result = $this->registry->run(
			'fake_verifier',
			array( 'output' => 'x' ),
			array(),
			array( 'provider' => 'gemini' )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['passed'] );
	}

	/**
	 * Unknown verifier slug returns WP_Error.
	 */
	public function test_run_unknown_returns_error() {
		$result = $this->registry->run( 'nope', array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Verifier_result action fires with correct args.
	 */
	public function test_verifier_result_action_fires() {
		$this->registry->register( new WP_MCP_AI_Test_Fake_Verifier() );
		$fired = false;
		add_action(
			'wp_mcp_ai_verifier_result',
			function ( $result, $verifier, $subject, $context ) use ( &$fired ) {
				$fired = true;
				$this->assertTrue( $result['passed'] );
				$this->assertInstanceOf( 'WP_MCP_AI_Verifier_Interface', $verifier );
				$this->assertSame( array( 'output' => 'x' ), $subject );
			},
			10,
			4
		);
		$this->registry->run( 'fake_verifier', array( 'output' => 'x' ) );
		$this->assertTrue( $fired );
	}
}
