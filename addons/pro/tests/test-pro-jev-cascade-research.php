<?php
/**
 * Tests for the Pro citation cascade (proposal 045, G5 consumer 1):
 * check_citations_cascade(), escalate_flagged_citations(), and
 * apply_claim_replacements().
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Pro Jev citation cascade.
 */
class Test_Pro_Jev_Cascade_Research extends WP_UnitTestCase {

	/**
	 * Canned noul probabilities served by the cascade stub, keyed by qid.
	 *
	 * @var array
	 */
	public $canned_answers = array();

	/**
	 * Number of escalation (chat) calls the stub router received.
	 *
	 * @var int
	 */
	public $escalation_calls = 0;

	/**
	 * Revision text returned by the stub router.
	 *
	 * @var string
	 */
	public $stub_revision = 'Revised claim text.';

	/**
	 * WP_Error to return from the stub router when set.
	 *
	 * @var WP_Error|null
	 */
	public $stub_router_error = null;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-verification-cascade.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';

		if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
		}

		// Retries must not sleep inside the test suite.
		add_filter( 'wp_mcp_ai_typesafe_retry_sleep', '__return_zero' );

		$this->canned_answers    = array();
		$this->escalation_calls  = 0;
		$this->stub_revision     = 'Revised claim text.';
		$this->stub_router_error = null;

		$test = $this;
		add_filter(
			'wp_mcp_ai_jev_escalation_router',
			function () use ( $test ) {
				return $test->stub_router();
			}
		);

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_typesafe_retry_sleep' );
		remove_all_filters( 'wp_mcp_ai_jev_escalation_router' );
		remove_all_filters( 'wp_mcp_ai_citation_escalation_max' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_jev_classifier_enabled' );
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Helper: enable the native TypeSafe transport in settings.
	 */
	private function enable_typesafe() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);
	}

	/**
	 * Build a stub decision client for the cascade.
	 *
	 * Serves per-source canned answers: citations citing the first source
	 * are clean (0.1), citations citing the second source fire
	 * `claim__hallucinated` at 0.95.
	 *
	 * @return Interface_WP_MCP_AI_Decision_Client
	 */
	private function stub_client() {
		$test = $this;

		return new class( $test ) implements Interface_WP_MCP_AI_Decision_Client {
			/**
			 * Owning test case.
			 *
			 * @var Test_Pro_Jev_Cascade_Research
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param Test_Pro_Jev_Cascade_Research $test Owning test.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Serve canned noul answers.
			 *
			 * @param mixed $state     State.
			 * @param array $questions Question map.
			 * @param array $options   Options.
			 * @return array|WP_Error
			 */
			public function decide( $state, $questions, $options = array() ) {
				$source = isset( $state['source_text'] ) && is_string( $state['source_text'] ) ? $state['source_text'] : '';
				$fired  = false !== strpos( $source, 'Water boils' );

				$answers = array();
				foreach ( $questions as $qid => $question ) {
					$probability = $fired && 'claim__hallucinated' === $qid ? 0.95 : 0.1;

					$answers[ $qid ] = array(
						'type' => 'noul',
						'noul' => $probability,
					);
				}

				return array(
					'model'   => 'jev-1.13.0',
					'answers' => $answers,
					'usage'   => array(
						'input_tokens'  => 10,
						'output_tokens' => 5,
					),
				);
			}

			/**
			 * Provider slug.
			 *
			 * @return string
			 */
			public function get_provider_slug() {
				return 'stub';
			}
		};
	}

	/**
	 * Build a stub router for citation escalation.
	 *
	 * @return WP_MCP_AI_Language_Model_Router
	 */
	private function stub_router() {
		$test = $this;

		return new class( $test ) extends WP_MCP_AI_Language_Model_Router {
			/**
			 * Owning test case.
			 *
			 * @var Test_Pro_Jev_Cascade_Research
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param Test_Pro_Jev_Cascade_Research $test Owning test.
			 */
			public function __construct( $test ) {
				$this->test = $test;
				parent::__construct( new WP_MCP_AI_OpenAI_Client(), new WP_MCP_AI_Gemini_Client() );
			}

			/**
			 * Serve the stub revision without any HTTP call.
			 *
			 * @param array $messages Messages.
			 * @param array $options  Options.
			 * @return array|WP_Error
			 */
			public function create_chat_completion( array $messages, array $options = array() ) {
				++$this->test->escalation_calls;

				if ( null !== $this->test->stub_router_error ) {
					return $this->test->stub_router_error;
				}

				return array( 'content' => $this->test->stub_revision );
			}
		};
	}

	/**
	 * Two sample sources: source 1 supports its claim, source 2 does not.
	 *
	 * @return array
	 */
	private function sample_sources() {
		return array(
			array(
				'url'     => 'https://example.com/sky',
				'title'   => 'Sky Colors',
				'snippet' => 'The sky is blue due to Rayleigh scattering.',
			),
			array(
				'url'     => 'https://example.com/water',
				'title'   => 'Water Facts',
				'snippet' => 'Water boils at 100 C at sea level.',
			),
		);
	}

	// -------------------------------------------------------------------------
	// check_citations_cascade().
	// -------------------------------------------------------------------------

	/**
	 * Test the cascade battery marks unsupported claims as fired.
	 */
	public function test_check_citations_cascade_flags_unsupported_claims() {
		$this->enable_typesafe();

		$cascade = new WP_MCP_AI_Verification_Cascade( $this->stub_client() );

		$report = 'The sky is blue due to Rayleigh scattering [1]. Water boils at 90 C at sea level [2].';

		$checks = WP_MCP_AI_Pro_Jev_Classifier::check_citations_cascade( $report, $this->sample_sources(), 10, $cascade );

		$this->assertCount( 2, $checks );

		$first = $checks[0];
		$this->assertEquals( 1, $first['citation'] );
		$this->assertTrue( $first['supported'] );
		$this->assertFalse( $first['fired'] );
		$this->assertEmpty( $first['fired_metrics'] );

		$second = $checks[1];
		$this->assertEquals( 2, $second['citation'] );
		$this->assertFalse( $second['supported'] );
		$this->assertTrue( $second['fired'] );
		$this->assertContains( 'claim__hallucinated', $second['fired_metrics'] );
		$this->assertNotEmpty( $second['claim'] );
	}

	/**
	 * Test the cascade returns empty when sources are missing.
	 */
	public function test_check_citations_cascade_empty_without_sources() {
		$this->enable_typesafe();

		$checks = WP_MCP_AI_Pro_Jev_Classifier::check_citations_cascade( 'Some report [1].', array() );

		$this->assertEmpty( $checks );
	}

	// -------------------------------------------------------------------------
	// escalate_flagged_citations().
	// -------------------------------------------------------------------------

	/**
	 * Test flagged claims are revised on the verification tier.
	 */
	public function test_escalate_revises_flagged_claims() {
		$this->enable_typesafe();

		$checks = array(
			array(
				'citation'      => 2,
				'claim'         => 'Water boils at 90 C at sea level.',
				'supported'     => false,
				'fired'         => true,
				'fired_metrics' => array( 'claim__hallucinated' ),
			),
		);

		$replacements = WP_MCP_AI_Pro_Jev_Classifier::escalate_flagged_citations(
			'',
			$this->sample_sources(),
			$checks
		);

		$this->assertCount( 1, $replacements );
		$this->assertTrue( $replacements[0]['replaced'] );
		$this->assertEquals( 'Revised claim text.', $replacements[0]['replacement'] );
		$this->assertNull( $replacements[0]['error'] );
		$this->assertEquals( 1, $this->escalation_calls );
	}

	/**
	 * Test an UNSUPPORTED revision keeps the original claim (fail-open).
	 */
	public function test_escalate_keeps_original_on_unsupported() {
		$this->enable_typesafe();
		$this->stub_revision = 'UNSUPPORTED';

		$replacements = WP_MCP_AI_Pro_Jev_Classifier::escalate_flagged_citations(
			'',
			$this->sample_sources(),
			array(
				array(
					'citation' => 2,
					'claim'    => 'Water boils at 90 C at sea level.',
					'fired'    => true,
				),
			)
		);

		$this->assertCount( 1, $replacements );
		$this->assertFalse( $replacements[0]['replaced'] );
		$this->assertEquals( 'unsupported', $replacements[0]['error'] );
	}

	/**
	 * Test a router error keeps the original claim (fail-open).
	 */
	public function test_escalate_fails_open_on_router_error() {
		$this->enable_typesafe();
		$this->stub_router_error = new WP_Error( 'stub_fail', 'Router failed.' );

		$replacements = WP_MCP_AI_Pro_Jev_Classifier::escalate_flagged_citations(
			'',
			$this->sample_sources(),
			array(
				array(
					'citation' => 2,
					'claim'    => 'Water boils at 90 C at sea level.',
					'fired'    => true,
				),
			)
		);

		$this->assertCount( 1, $replacements );
		$this->assertFalse( $replacements[0]['replaced'] );
		$this->assertEquals( 'stub_fail', $replacements[0]['error'] );
	}

	/**
	 * Test the escalation attempt cap is respected.
	 */
	public function test_escalate_respects_attempt_cap() {
		$this->enable_typesafe();
		add_filter(
			'wp_mcp_ai_citation_escalation_max',
			function () {
				return 1;
			}
		);

		$replacements = WP_MCP_AI_Pro_Jev_Classifier::escalate_flagged_citations(
			'',
			$this->sample_sources(),
			array(
				array(
					'citation' => 1,
					'claim'    => 'The sky is blue.',
					'fired'    => true,
				),
				array(
					'citation' => 2,
					'claim'    => 'Water boils at 90 C.',
					'fired'    => true,
				),
			)
		);

		$this->assertEquals( 1, $this->escalation_calls );
		$this->assertCount( 1, $replacements );
	}

	/**
	 * Test escalation returns empty when the classifier is unavailable.
	 */
	public function test_escalate_empty_when_unavailable() {
		delete_option( 'wp_mcp_ai_settings' );

		$replacements = WP_MCP_AI_Pro_Jev_Classifier::escalate_flagged_citations(
			'',
			$this->sample_sources(),
			array(
				array(
					'citation' => 2,
					'claim'    => 'Water boils at 90 C.',
					'fired'    => true,
				),
			)
		);

		$this->assertEmpty( $replacements );
		$this->assertEquals( 0, $this->escalation_calls );
	}

	// -------------------------------------------------------------------------
	// apply_claim_replacements().
	// -------------------------------------------------------------------------

	/**
	 * Test claim replacements are swapped into the report text.
	 */
	public function test_apply_claim_replacements_swaps_text() {
		$report = 'The sky is blue [1]. Water boils at 90 C at sea level [2].';

		$applied = WP_MCP_AI_Pro_Jev_Classifier::apply_claim_replacements(
			$report,
			array(
				array(
					'claim'       => 'Water boils at 90 C at sea level [2].',
					'replacement' => 'Water boils at 100 C at sea level [2].',
					'replaced'    => true,
				),
			)
		);

		$this->assertEquals( 1, $applied['replaced_count'] );
		$this->assertStringContainsString( 'Water boils at 100 C at sea level [2].', $applied['text'] );
		$this->assertStringNotContainsString( 'Water boils at 90 C', $applied['text'] );
	}

	/**
	 * Test replacements for claims absent from the text are skipped.
	 */
	public function test_apply_claim_replacements_skips_missing_claims() {
		$report = 'The sky is blue [1].';

		$applied = WP_MCP_AI_Pro_Jev_Classifier::apply_claim_replacements(
			$report,
			array(
				array(
					'claim'       => 'Water boils at 90 C at sea level.',
					'replacement' => 'Water boils at 100 C at sea level.',
					'replaced'    => true,
				),
			)
		);

		$this->assertEquals( 0, $applied['replaced_count'] );
		$this->assertSame( $report, $applied['text'] );
	}

	/**
	 * Test non-replaced entries never touch the text.
	 */
	public function test_apply_claim_replacements_ignores_unreplaced_entries() {
		$report = 'Water boils at 90 C at sea level [2].';

		$applied = WP_MCP_AI_Pro_Jev_Classifier::apply_claim_replacements(
			$report,
			array(
				array(
					'claim'       => 'Water boils at 90 C at sea level.',
					'replacement' => null,
					'replaced'    => false,
				),
			)
		);

		$this->assertEquals( 0, $applied['replaced_count'] );
		$this->assertSame( $report, $applied['text'] );
	}
}
