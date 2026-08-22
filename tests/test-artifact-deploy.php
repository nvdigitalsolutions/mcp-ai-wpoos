<?php
/**
 * Tests for the Artifact Deploy class (Phase F.1/F.2/F.4).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test gated artifact promotion, rollback, holdout and drift.
 */
class Test_Artifact_Deploy extends WP_UnitTestCase {

	/**
	 * Assistant post ID used across tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Verifier slug used by inline-holdout test cases.
	 *
	 * @var string
	 */
	private $verifier_slug = 'deploy_test_required';

	/**
	 * Set up an assistant post and the deterministic holdout verifier.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Artifact_Deploy' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Deploy class not available.' );
		}

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		if ( class_exists( 'WP_MCP_AI_Rule_Verifier' ) && class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			WP_MCP_AI_Verifier_Registry::reset_instance();
			WP_MCP_AI_Verifier_Registry::get_instance()->register(
				new WP_MCP_AI_Rule_Verifier(
					$this->verifier_slug,
					array(
						array(
							'type' => 'required',
							'path' => 'value',
						),
					)
				)
			);
		}

		wp_set_current_user( 1 );
	}

	/**
	 * Remove the deploy filters and reset the current user.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_deploy_require_holdout' );
		remove_all_filters( 'wp_mcp_ai_artifact_deploy_auto_rollback' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A passing holdout payload (pre-computed verification).
	 *
	 * @return array<string,mixed>
	 */
	private function passing_verification() {
		return array(
			'decision'            => 'accept',
			'regressed_cases'     => 0,
			'candidate_pass_rate' => 1.0,
		);
	}

	/**
	 * Promotion with valid holdout evidence writes the prompt + audit trail.
	 */
	public function test_promote_prompt_with_holdout_succeeds() {
		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Be concise and honest.',
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deployed'] );
		$this->assertSame( 'prompt', $result['artifact_type'] );

		$this->assertSame(
			'Be concise and honest.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);

		$history = WP_MCP_AI_Artifact_Deploy::get_history( $this->assistant_id, 5 );
		$this->assertNotEmpty( $history );
		$this->assertSame( 'promote', $history[0]['event'] );
		$this->assertSame( $result['hash'], $history[0]['hash'] );

		$this->assertTrue( WP_MCP_AI_Artifact_Deploy::can_rollback( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * Promotion without holdout evidence fails closed.
	 */
	public function test_promote_without_holdout_fails_closed() {
		$result = WP_MCP_AI_Artifact_Deploy::promote( $this->assistant_id, 'prompt', 'Unverified.' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_no_holdout', $result->get_error_code() );

		// Nothing was deployed.
		$this->assertSame( '', (string) get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true ) );
	}

	/**
	 * A rejected holdout verdict blocks promotion.
	 */
	public function test_promote_rejects_holdout_rejection() {
		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Regressed variant.',
			array(
				'verification' => array(
					'decision'            => 'reject',
					'regressed_cases'     => 2,
					'candidate_pass_rate' => 0.4,
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_holdout_rejected', $result->get_error_code() );
	}

	/**
	 * A low pass rate is rejected even when the gate accepted it.
	 */
	public function test_promote_rejects_pass_rate_below_minimum() {
		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Weak variant.',
			array(
				'verification' => array(
					'decision'            => 'accept',
					'regressed_cases'     => 0,
					'candidate_pass_rate' => 0.80,
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_holdout_rejected', $result->get_error_code() );
	}

	/**
	 * Users without edit capability cannot promote.
	 */
	public function test_promote_requires_capability() {
		wp_set_current_user( 0 );

		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Forbidden.',
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_forbidden', $result->get_error_code() );
	}

	/**
	 * Structurally invalid candidates are rejected before any write.
	 */
	public function test_promote_rejects_invalid_candidates() {
		$empty = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'   ',
			array( 'verification' => $this->passing_verification() )
		);
		$this->assertWPError( $empty );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_invalid_prompt', $empty->get_error_code() );

		$nameless = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'skill',
			array( 'instructions' => 'Do a thing.' ),
			array( 'verification' => $this->passing_verification() )
		);
		$this->assertWPError( $nameless );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_invalid_skill', $nameless->get_error_code() );

		$bad_type = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'role',
			'x',
			array( 'verification' => $this->passing_verification() )
		);
		$this->assertWPError( $bad_type );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_invalid_type', $bad_type->get_error_code() );
	}

	/**
	 * Inline holdout: promote() runs the verification gate itself.
	 */
	public function test_promote_runs_inline_holdout() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite' ) || ! class_exists( 'WP_MCP_AI_Rule_Verifier' ) ) {
			$this->markTestSkipped( 'Eval suite or rule verifier not available.' );
		}

		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'deploy-holdout',
				'cases' => array(
					array(
						'slug'          => 'h1',
						'verifier_slug' => $this->verifier_slug,
					),
					array(
						'slug'          => 'h2',
						'verifier_slug' => $this->verifier_slug,
					),
				),
			)
		);

		$generators = array(
			'incumbent' => static function () {
				return array( 'output' => '' );
			},
			'candidate' => static function () {
				return array( 'output' => 'A useful answer.' );
			},
		);

		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'A useful answer.',
			array(
				'generators' => $generators,
				'suite'      => $suite,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deployed'] );
	}

	/**
	 * Rollback restores the exact incumbent and logs the event.
	 */
	public function test_rollback_restores_incumbent() {
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', 'Old prompt.' );

		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'New prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertSame(
			'New prompt.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);

		$result = WP_MCP_AI_Artifact_Deploy::rollback( $this->assistant_id, 'prompt' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['rolled_back'] );
		$this->assertSame(
			'Old prompt.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);
		$this->assertFalse( WP_MCP_AI_Artifact_Deploy::can_rollback( $this->assistant_id, 'prompt' ) );

		$history = WP_MCP_AI_Artifact_Deploy::get_history( $this->assistant_id, 5 );
		$this->assertSame( 'rollback', $history[0]['event'] );
	}

	/**
	 * Rollback without a target returns a WP_Error.
	 */
	public function test_rollback_without_target_fails() {
		$result = WP_MCP_AI_Artifact_Deploy::rollback( $this->assistant_id, 'prompt' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_deploy_no_rollback_target', $result->get_error_code() );
	}

	/**
	 * Skill promotion merges into the site-global skill option.
	 */
	public function test_promote_skill_writes_option() {
		$skill = array(
			'name'         => 'greeter',
			'description'  => 'Greets users.',
			'instructions' => 'Say hello.',
		);

		$result = WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'skill',
			$skill,
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertNotWPError( $result );

		$skills = get_option( 'wp_mcp_ai_evolved_skills', array() );
		$this->assertIsArray( $skills );
		$this->assertSame( 'Say hello.', $skills['greeter']['instructions'] );
	}

	/**
	 * Drift detection without a deployment is not actionable.
	 */
	public function test_detect_drift_requires_deployment() {
		$report = WP_MCP_AI_Artifact_Deploy::detect_drift( $this->assistant_id, 'prompt' );

		$this->assertFalse( $report['actionable'] );
		$this->assertSame( 'no_deployment', $report['reason'] );
	}

	/**
	 * A pass-rate drop after deployment is flagged by the regression detector.
	 */
	public function test_detect_drift_flags_regression() {
		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Deployed prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$deployed_at = (int) get_post_meta( $this->assistant_id, '_wp_mcp_ai_artifact_deployed_at_prompt', true );
		$this->assertGreaterThan( 0, $deployed_at );

		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record(
			'deploy-drift-suite',
			array(
				'pass_rate'       => 0.90,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at - 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);
		$store->record(
			'deploy-drift-suite',
			array(
				'pass_rate'       => 0.80,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at + 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);

		$report = WP_MCP_AI_Artifact_Deploy::detect_drift( $this->assistant_id, 'prompt' );

		$this->assertTrue( $report['actionable'] );
		$this->assertSame( 'drift_detected', $report['reason'] );
		$this->assertNotEmpty( $report['detector']['reasons'] );
		$this->assertSame( 'pass_rate', $report['detector']['reasons'][0]['metric'] );
	}

	/**
	 * Without baseline runs the detector stays silent.
	 */
	public function test_detect_drift_requires_baseline() {
		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Deployed prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$deployed_at = (int) get_post_meta( $this->assistant_id, '_wp_mcp_ai_artifact_deployed_at_prompt', true );

		WP_MCP_AI_Eval_Run_Store::get_instance()->record(
			'deploy-nobaseline-suite',
			array(
				'pass_rate'       => 0.5,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at + 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);

		$report = WP_MCP_AI_Artifact_Deploy::detect_drift( $this->assistant_id, 'prompt' );

		$this->assertFalse( $report['actionable'] );
		$this->assertSame( 'no_baseline_runs', $report['reason'] );
	}

	/**
	 * Automatic rollback is opt-in; the default only reports the drift.
	 */
	public function test_check_and_rollback_default_does_not_rollback() {
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', 'Old prompt.' );

		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Deployed prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$deployed_at = (int) get_post_meta( $this->assistant_id, '_wp_mcp_ai_artifact_deployed_at_prompt', true );
		$store       = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record(
			'deploy-rollback-suite',
			array(
				'pass_rate'       => 0.90,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at - 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);
		$store->record(
			'deploy-rollback-suite',
			array(
				'pass_rate'       => 0.80,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at + 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);

		$result = WP_MCP_AI_Artifact_Deploy::check_and_rollback( $this->assistant_id, 'prompt' );

		$this->assertFalse( $result['rolled_back'] );
		$this->assertTrue( $result['drift']['actionable'] );
		$this->assertSame(
			'Deployed prompt.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);
	}

	/**
	 * With the auto-rollback filter on, drift triggers rollback + audit event.
	 */
	public function test_check_and_rollback_auto_rolls_back_when_enabled() {
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', 'Old prompt.' );

		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Deployed prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$deployed_at = (int) get_post_meta( $this->assistant_id, '_wp_mcp_ai_artifact_deployed_at_prompt', true );
		$store       = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record(
			'deploy-auto-rollback-suite',
			array(
				'pass_rate'       => 0.90,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at - 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);
		$store->record(
			'deploy-auto-rollback-suite',
			array(
				'pass_rate'       => 0.80,
				'error_rate'      => 0.0,
				'abstention_rate' => 0.0,
			),
			$deployed_at + 100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => (string) $this->assistant_id,
			)
		);

		add_filter( 'wp_mcp_ai_artifact_deploy_auto_rollback', '__return_true' );

		$result = WP_MCP_AI_Artifact_Deploy::check_and_rollback( $this->assistant_id, 'prompt' );

		$this->assertTrue( $result['rolled_back'] );
		$this->assertSame(
			'Old prompt.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);

		$history = WP_MCP_AI_Artifact_Deploy::get_history( $this->assistant_id, 5 );
		$this->assertSame( 'rollback_drift', $history[0]['event'] );
	}

	/**
	 * The audit trail is append-only and capped.
	 */
	public function test_audit_trail_is_capped() {
		for ( $i = 1; $i <= 105; $i++ ) {
			WP_MCP_AI_Artifact_Deploy::promote(
				$this->assistant_id,
				'prompt',
				'Prompt iteration ' . $i,
				array( 'verification' => $this->passing_verification() )
			);
		}

		$history = WP_MCP_AI_Artifact_Deploy::get_history( $this->assistant_id, 200 );

		$this->assertCount( 100, $history );
		$this->assertSame( 'promote', $history[0]['event'] );
	}
}
