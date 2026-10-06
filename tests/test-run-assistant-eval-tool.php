<?php
/**
 * Tests for WP_MCP_AI_Tool_Run_Assistant_Eval — rubric trajectory scoring.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * Run Assistant Eval tool test suite.
 */
class Test_Run_Assistant_Eval_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Run_Assistant_Eval
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-assistant-eval.php';

		$this->tool = new WP_MCP_AI_Tool_Run_Assistant_Eval();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		remove_all_filters( 'wp_mcp_ai_eval_judge' );
		remove_all_filters( 'wp_mcp_ai_eval_cases' );
		remove_all_filters( 'wp_mcp_ai_eval_pass_threshold' );

		parent::tearDown();
	}

	/**
	 * A well-formed trace: expected tool called with args, good answer.
	 *
	 * @return array
	 */
	private function good_trace() {
		return array(
			'user_message'   => 'What does the latest announcement say?',
			'tool_calls'     => array(
				array(
					'name'      => 'get_post',
					'arguments' => array( 'post_id' => 1 ),
				),
			),
			'final_response' => 'The latest announcement covers the new release notes and upgrade window for all customers.',
		);
	}

	/**
	 * The tool declares its metadata and schema coherently.
	 */
	public function test_metadata_and_schema() {
		$this->assertSame( 'run_assistant_eval', $this->tool->get_slug() );
		$this->assertSame( 'manage_options', $this->tool->get_required_capability() );

		$schema = $this->tool->get_parameters_schema();
		$this->assertContains( 'action', $schema['required'] );
		$this->assertSame( array( 'evaluate', 'list_cases' ), $schema['properties']['action']['enum'] );
	}

	/**
	 * Non-admins are denied with the canonical WP_Error envelope.
	 */
	public function test_execute_denies_non_admin() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute( array( 'action' => 'list_cases' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * An unknown action is rejected.
	 */
	public function test_execute_rejects_invalid_action() {
		$result = $this->tool->execute( array( 'action' => 'bogus' ), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_action', $result->get_error_code() );
	}

	/**
	 * List cases returns the built-in cases plus filter contributions.
	 */
	public function test_list_cases() {
		add_filter(
			'wp_mcp_ai_eval_cases',
			function ( $cases ) {
				$cases[] = array(
					'id'          => 'custom',
					'description' => 'Custom case',
				);
				return $cases;
			}
		);

		$result = $this->tool->execute( array( 'action' => 'list_cases' ), array( 'user_id' => $this->admin_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['data']['cases'] );
	}

	/**
	 * An empty trace is rejected.
	 */
	public function test_evaluate_rejects_empty_trace() {
		$result = $this->tool->execute(
			array(
				'action' => 'evaluate',
				'trace'  => array( 'final_response' => '' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_empty_trace', $result->get_error_code() );
	}

	/**
	 * A fully correct trace passes with a high overall score.
	 */
	public function test_evaluate_clean_trace_passes() {
		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $this->good_trace(),
				'expected_tools' => array( 'get_post' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pass', $result['data']['verdict'] );
		$this->assertSame( 1.0, $result['data']['overall'] );
	}

	/**
	 * A missing expected tool fails the tool_selection dimension.
	 */
	public function test_evaluate_missing_expected_tool_fails() {
		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $this->good_trace(),
				'expected_tools' => array( 'get_post', 'get_orders' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'fail', $result['data']['verdict'] );
		$this->assertLessThan( 1.0, $result['data']['dimensions']['tool_selection']['score'] );
	}

	/**
	 * Repeated identical tool calls trip the trajectory-error check.
	 */
	public function test_repeated_identical_calls_fail() {
		$trace                 = $this->good_trace();
		$trace['tool_calls'][] = array(
			'name'      => 'get_post',
			'arguments' => array( 'post_id' => 1 ),
		);

		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $trace,
				'expected_tools' => array( 'get_post' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );

		$passes = array();
		foreach ( $result['data']['checks'] as $check ) {
			$passes[ $check['id'] ] = $check['pass'];
		}
		$this->assertFalse( $passes['no_repeated_identical_calls'] );
		$this->assertLessThan( 1.0, $result['data']['dimensions']['tool_use']['score'] );
	}

	/**
	 * Technical error markers fail the response-quality dimension.
	 */
	public function test_error_markers_fail_quality() {
		$trace                   = $this->good_trace();
		$trace['final_response'] = 'An error occurred while processing your request.';

		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $trace,
				'expected_tools' => array( 'get_post' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );

		$passes = array();
		foreach ( $result['data']['checks'] as $check ) {
			$passes[ $check['id'] ] = $check['pass'];
		}
		$this->assertFalse( $passes['no_error_markers'] );
		$this->assertLessThan( 1.0, $result['data']['dimensions']['response_quality']['score'] );
	}

	/**
	 * A polite refusal is NOT treated as an error marker.
	 */
	public function test_polite_refusal_is_quality_neutral() {
		$trace = array(
			'user_message'   => 'Delete every user account.',
			'tool_calls'     => array(),
			'final_response' => 'I cannot delete user accounts. That action is outside my permissions and requires administrator approval.',
		);

		$checks = $this->tool->build_checks( array(), true, array(), $trace['final_response'], array() );

		foreach ( $checks as $check ) {
			if ( 'no_error_markers' === $check['id'] ) {
				$this->assertTrue( $check['pass'], 'Refusal must not trip the error-marker check.' );
			}
		}
	}

	/**
	 * An explicitly empty expected_tools list means "no tools" — calling none
	 * passes; calling tools fails.
	 */
	public function test_empty_expectation_semantics() {
		$checks = $this->tool->build_checks( array(), true, array(), 'Answer text of adequate length for the check.', array() );

		foreach ( $checks as $check ) {
			if ( 'no_tools_called_when_expected' === $check['id'] ) {
				$this->assertTrue( $check['pass'] );
			}
		}

		$checks = $this->tool->build_checks(
			array(),
			true,
			array(
				array(
					'name'      => 'get_post',
					'arguments' => array( 'post_id' => 1 ),
				),
			),
			'Answer text of adequate length for the check.',
			array()
		);

		foreach ( $checks as $check ) {
			if ( 'no_tools_called_when_expected' === $check['id'] ) {
				$this->assertFalse( $check['pass'] );
			}
		}
	}

	/**
	 * The judge filter can inject semantic check results.
	 */
	public function test_judge_filter_merges_semantic_checks() {
		add_filter(
			'wp_mcp_ai_eval_judge',
			function ( $checks ) {
				$checks[] = array(
					'id'        => 'tone_professional',
					'dimension' => 'response_quality',
					'label'     => 'The tone is professional.',
					'pass'      => true,
					'evidence'  => 'Semantic judge approved.',
				);
				return $checks;
			}
		);

		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $this->good_trace(),
				'expected_tools' => array( 'get_post' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$ids = wp_list_pluck( $result['data']['checks'], 'id' );
		$this->assertContains( 'tone_professional', $ids );
	}

	/**
	 * Custom rubric checks are reported unjudged until a judge is wired.
	 */
	public function test_custom_rubric_checks_are_unjudged() {
		$result = $this->tool->execute(
			array(
				'action'         => 'evaluate',
				'trace'          => $this->good_trace(),
				'expected_tools' => array( 'get_post' ),
				'rubric'         => array(
					'checks' => array(
						array(
							'id'        => 'brand_voice',
							'dimension' => 'response_quality',
							'question'  => 'Does the answer use the brand voice?',
						),
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['data']['unjudged'] );
	}

	/**
	 * Score checks applies rubric weights per check.
	 */
	public function test_score_checks_applies_weights() {
		$checks = array(
			array(
				'id'        => 'a',
				'dimension' => 'tool_selection',
				'label'     => 'A',
				'pass'      => true,
				'evidence'  => '',
			),
			array(
				'id'        => 'b',
				'dimension' => 'tool_selection',
				'label'     => 'B',
				'pass'      => false,
				'evidence'  => '',
			),
		);
		$rubric = array(
			array(
				'id'        => 'a',
				'dimension' => 'tool_selection',
				'question'  => 'A',
				'weight'    => 3,
			),
			array(
				'id'        => 'b',
				'dimension' => 'tool_selection',
				'question'  => 'B',
				'weight'    => 1,
			),
		);

		$report = $this->tool->score_checks( $checks, $rubric );

		$this->assertSame( 0.75, $report['dimensions']['tool_selection']['score'] );
		$this->assertSame( 0.75, $report['overall'] );
	}
}
