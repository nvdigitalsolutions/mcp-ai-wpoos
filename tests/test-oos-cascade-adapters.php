<?php
/**
 * Tests for the OOS cascade adapters (Proposal 056, P1 OOS-path wiring).
 *
 * The adapters bridge the WordPress cascade filter seams into the
 * framework-agnostic classifier/validator contracts consumed by
 * `Nvoos\Core\Application\Provider\CascadeRouter` on the OOS engine path.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * OOS cascade adapter test suite.
 */
class Test_OOS_Cascade_Adapters extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'lib/core/src/Domain/Contract/ComplexityClassifierInterface.php';
		require_once WP_MCP_AI_PATH . 'lib/core/src/Domain/Contract/ResponseValidatorInterface.php';
		require_once WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/Adapter/CascadeClassifier.php';
		require_once WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/Adapter/CascadeValidator.php';
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_cascade_enabled' );
		remove_all_filters( 'wp_mcp_ai_cascade_classifier' );
		remove_all_filters( 'wp_mcp_ai_cascade_validator' );

		parent::tearDown();
	}

	/**
	 * Disabled cascade → every request classifies complex (primary only).
	 */
	public function test_classifier_fails_closed_when_disabled() {
		$classifier = new Nvoos\WordPress\Adapter\CascadeClassifier();

		$verdict = $classifier->classify(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertSame( 'complex', $verdict['tier'] );
		$this->assertSame( 'cascade-disabled', $verdict['reason'] );
	}

	/**
	 * Enabled but no listener supplies a verdict → complex.
	 */
	public function test_classifier_fails_closed_without_listener() {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );
		$classifier = new Nvoos\WordPress\Adapter\CascadeClassifier();

		$verdict = $classifier->classify(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertSame( 'complex', $verdict['tier'] );
		$this->assertSame( 'no-cascade-classifier', $verdict['reason'] );
	}

	/**
	 * A simple verdict from a listener maps through with its confidence.
	 */
	public function test_classifier_maps_simple_verdict() {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_cascade_classifier',
			function () {
				return array(
					'tier'       => 'simple',
					'confidence' => 0.8,
					'reason'     => 'fixture',
				);
			}
		);
		$classifier = new Nvoos\WordPress\Adapter\CascadeClassifier();

		$verdict = $classifier->classify(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertSame( 'simple', $verdict['tier'] );
		$this->assertSame( 0.8, $verdict['confidence'] );
		$this->assertSame( 'fixture', $verdict['reason'] );
	}

	/**
	 * A complex verdict passes through as complex.
	 */
	public function test_classifier_maps_complex_verdict() {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_cascade_classifier',
			function () {
				return array(
					'tier'       => 'complex',
					'confidence' => 0.1,
					'reason'     => 'frontier needed',
				);
			}
		);
		$classifier = new Nvoos\WordPress\Adapter\CascadeClassifier();

		$verdict = $classifier->classify(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertSame( 'complex', $verdict['tier'] );
	}

	/**
	 * Deterministic default validator: errors and empty content fail.
	 */
	public function test_validator_deterministic_defaults() {
		$validator = new Nvoos\WordPress\Adapter\CascadeValidator();
		$messages  = array(
			array(
				'role'    => 'user',
				'content' => 'Hello.',
			),
		);

		$this->assertFalse( $validator->validate( new WP_Error( 'x', 'boom' ), $messages, array() )['acceptable'] );
		$this->assertFalse( $validator->validate( array( 'error' => 'boom' ), $messages, array() )['acceptable'] );
		$this->assertFalse( $validator->validate( array( 'choices' => array( array( 'message' => array( 'content' => '  ' ) ) ) ), $messages, array() )['acceptable'] );

		$verdict = $validator->validate( array( 'choices' => array( array( 'message' => array( 'content' => 'Real answer.' ) ) ) ), $messages, array() );
		$this->assertTrue( $verdict['acceptable'] );
		$this->assertSame( 0.9, $verdict['confidence'] );
	}

	/**
	 * A semantic judge filter overrides the deterministic default.
	 */
	public function test_validator_filter_override_wins() {
		add_filter(
			'wp_mcp_ai_cascade_validator',
			function () {
				return array(
					'acceptable' => false,
					'confidence' => 0.2,
					'reason'     => 'semantic judge rejected',
				);
			}
		);
		$validator = new Nvoos\WordPress\Adapter\CascadeValidator();

		$verdict = $validator->validate(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Plausible answer.' ) ) ) ),
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertFalse( $verdict['acceptable'] );
		$this->assertSame( 0.2, $verdict['confidence'] );
		$this->assertSame( 'semantic judge rejected', $verdict['reason'] );
	}

	/**
	 * A garbage verdict from a listener fails closed.
	 */
	public function test_validator_fails_closed_on_garbage_verdict() {
		add_filter(
			'wp_mcp_ai_cascade_validator',
			function () {
				return 'not-an-array';
			}
		);
		$validator = new Nvoos\WordPress\Adapter\CascadeValidator();

		$verdict = $validator->validate(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Plausible answer.' ) ) ) ),
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertFalse( $verdict['acceptable'] );
		$this->assertSame( 'invalid-validator-verdict', $verdict['reason'] );
	}

	/**
	 * The OOS engine factory injects the cascade-aware router into the
	 * ChatOrchestrator (Proposal 056 P1 OOS-path wiring).
	 */
	public function test_oos_factory_injects_cascade_router() {
		if ( ! function_exists( 'wp_mcp_ai_oos_orchestrator' ) ) {
			// The bridge bails early when the lib/ extraction is absent
			// (pruned local copies / wp.org base builds).
			$this->markTestSkipped( 'oos-bridge not loaded (lib/ absent).' );
		}

		$orchestrator = wp_mcp_ai_oos_orchestrator();

		$this->assertInstanceOf( 'Nvoos\Core\Application\Chat\ChatOrchestrator', $orchestrator );

		$reflection = new ReflectionProperty( $orchestrator, 'providers' );
		$reflection->setAccessible( true );

		$this->assertInstanceOf( 'Nvoos\Core\Application\Provider\CascadeRouter', $reflection->getValue( $orchestrator ) );
	}
}
