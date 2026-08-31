<?php
/**
 * Artifact Deploy — gated promotion of evolved artifacts into the live runtime.
 *
 * Phase F of the artifact-evolution plan. Promotes content-addressed artifact
 * variants (prompts, skills) out of the Phase C population into the running
 * system, with the Phase B verification gate as a mandatory holdout, an
 * append-only audit trail, one-click rollback, and drift-triggered rollback
 * wired to the eval regression detector.
 *
 * Safety guarantees (mirroring HarnessAutoDeploy):
 *   - No promotion without holdout evidence (fail closed by default).
 *   - Holdout pass rate must reach MIN_HELD_OUT_PASS_RATE (95% default).
 *   - The incumbent is saved as the rollback target before every promotion.
 *   - The audit trail is append-only; no public mutation API.
 *   - Automatic rollback on drift is opt-in (default off — human-in-the-loop).
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Deploy class.
 *
 * @since 1.9.0
 */
class ArtifactDeploy {

	/**
	 * Artifact types that can be deployed into the live runtime.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const DEPLOYABLE_TYPES = array( 'prompt', 'skill' );

	/**
	 * Minimum held-out pass rate before a promotion is allowed.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const MIN_HELD_OUT_PASS_RATE = 0.95;

	/**
	 * Post meta prefix for the previous (rollback target) artifact payload.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const META_PREVIOUS_PREFIX = '_wp_mcp_ai_artifact_previous_';

	/**
	 * Post meta prefix for the deployment timestamp (drift baseline split).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const META_DEPLOYED_AT_PREFIX = '_wp_mcp_ai_artifact_deployed_at_';

	/**
	 * Post meta key for the append-only deployment history.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const META_DEPLOY_HISTORY = '_wp_mcp_ai_artifact_deploy_history';

	/**
	 * Maximum number of audit events retained per assistant.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_HISTORY = 100;

	/**
	 * Option key for the site-global evolved skills set.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const SKILLS_OPTION_KEY = 'wp_mcp_ai_evolved_skills';

	/**
	 * Default maximum characters for a deployed prompt (mirrors the
	 * Phase E admission gate's structural cap).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_MAX_PROMPT_CHARS = 30000;

	/**
	 * Promote a candidate artifact into the live runtime.
	 *
	 * Fails closed: no promotion without holdout evidence (a pre-computed
	 * verification payload in `$options['verification']`, or inline
	 * `$options['generators']` + `$options['suite']` which are evaluated
	 * through the artifact verification gate in `no_regression` mode).
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID owning the deployment.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @param mixed  $candidate     Candidate payload (string prompt, or skill array).
	 * @param array  $options       Optional. `candidate_hash`, `verification`,
	 *                              `generators` (array with incumbent/candidate
	 *                              callables), `suite` (WP_MCP_AI_Eval_Suite),
	 *                              `actor_id`, `reason`.
	 * @return array|WP_Error Success envelope, or WP_Error on failure.
	 */
	public static function promote( $assistant_id, $artifact_type, $candidate, array $options = array() ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_invalid_assistant',
				__( 'A valid assistant ID is required to deploy artifacts.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! in_array( $artifact_type, self::DEPLOYABLE_TYPES, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_invalid_type',
				sprintf(
					/* translators: %s: invalid artifact type */
					__( 'Artifact type "%s" cannot be deployed.', 'nvoos-content-graph-ai-platform' ),
					$artifact_type
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $assistant_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_forbidden',
				__( 'You are not allowed to deploy artifacts for this assistant.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		$validated = self::validate_candidate( $artifact_type, $candidate );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$holdout = self::resolve_holdout( $assistant_id, $artifact_type, $options );
		if ( is_wp_error( $holdout ) ) {
			return $holdout;
		}

		$hash = self::content_hash( $artifact_type, $candidate );
		if ( isset( $options['candidate_hash'] ) && '' !== (string) $options['candidate_hash'] ) {
			$hash = sanitize_key( (string) $options['candidate_hash'] );
		}

		// Save the incumbent as the rollback target before touching anything.
		$previous = self::get_deployed( $assistant_id, $artifact_type );
		update_post_meta(
			$assistant_id,
			self::META_PREVIOUS_PREFIX . $artifact_type,
			wp_json_encode(
				array(
					'payload'  => $previous,
					'hash'     => self::content_hash( $artifact_type, $previous ),
					'saved_at' => time(),
				)
			)
		);

		$applied = self::apply( $assistant_id, $artifact_type, $candidate );
		if ( is_wp_error( $applied ) || true !== $applied ) {
			// The incumbent is still live; drop the stale rollback target so
			// can_rollback() never offers a no-op rollback.
			delete_post_meta( $assistant_id, self::META_PREVIOUS_PREFIX . $artifact_type );

			return is_wp_error( $applied )
				? $applied
				: new \WP_Error(
					'wp_mcp_ai_artifact_deploy_apply_failed',
					__( 'The candidate could not be applied.', 'nvoos-content-graph-ai-platform' )
				);
		}

		update_post_meta( $assistant_id, self::META_DEPLOYED_AT_PREFIX . $artifact_type, time() );

		self::record_event(
			$assistant_id,
			'promote',
			$artifact_type,
			$hash,
			array(
				'holdout' => $holdout,
			)
		);

		return array(
			'deployed'      => true,
			'artifact_type' => $artifact_type,
			'hash'          => $hash,
			'previous_hash' => self::content_hash( $artifact_type, $previous ),
		);
	}

	/**
	 * Roll back to the last known-good artifact for a type.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @return array|WP_Error Success envelope, or WP_Error on failure.
	 */
	public static function rollback( $assistant_id, $artifact_type ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( $assistant_id <= 0 || ! in_array( $artifact_type, self::DEPLOYABLE_TYPES, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_rollback_invalid',
				__( 'Invalid assistant or artifact type for rollback.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! current_user_can( 'edit_post', $assistant_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_forbidden',
				__( 'You are not allowed to roll back artifacts for this assistant.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		$raw = get_post_meta( $assistant_id, self::META_PREVIOUS_PREFIX . $artifact_type, true );
		if ( empty( $raw ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_no_rollback_target',
				__( 'There is no rollback target for this artifact type.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$previous = json_decode( (string) $raw, true );
		if ( ! is_array( $previous ) || ! array_key_exists( 'payload', $previous ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_rollback_corrupt',
				__( 'The rollback target is unreadable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$applied = self::apply( $assistant_id, $artifact_type, $previous['payload'] );
		if ( is_wp_error( $applied ) || true !== $applied ) {
			return is_wp_error( $applied )
				? $applied
				: new \WP_Error(
					'wp_mcp_ai_artifact_deploy_apply_failed',
					__( 'The rollback payload could not be applied.', 'nvoos-content-graph-ai-platform' )
				);
		}

		delete_post_meta( $assistant_id, self::META_PREVIOUS_PREFIX . $artifact_type );
		delete_post_meta( $assistant_id, self::META_DEPLOYED_AT_PREFIX . $artifact_type );

		self::record_event(
			$assistant_id,
			'rollback',
			$artifact_type,
			isset( $previous['hash'] ) ? (string) $previous['hash'] : '',
			array()
		);

		return array(
			'rolled_back'   => true,
			'artifact_type' => $artifact_type,
		);
	}

	/**
	 * Check whether a rollback target exists for an artifact type.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return bool
	 */
	public static function can_rollback( $assistant_id, $artifact_type ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		$raw           = get_post_meta( absint( $assistant_id ), self::META_PREVIOUS_PREFIX . $artifact_type, true );

		return ! empty( $raw );
	}

	/**
	 * Read the currently deployed artifact for a type.
	 *
	 * Prompt deployments live in assistant meta; skill deployments are
	 * site-global and return the whole evolved-skills option array.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @return mixed Deployed payload ('' when none), or array for skills.
	 */
	public static function get_deployed( $assistant_id, $artifact_type ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( 'prompt' === $artifact_type ) {
			$meta_key = defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) && defined( 'WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_PROMPT_META_KEY' )
				? \WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_PROMPT_META_KEY
				: '_wp_mcp_ai_evolved_system_prompt';
			$prompt   = get_post_meta( $assistant_id, $meta_key, true );

			return is_string( $prompt ) ? $prompt : '';
		}

		$skills = get_option( self::SKILLS_OPTION_KEY, array() );

		return is_array( $skills ) ? $skills : array();
	}

	/**
	 * Read the append-only deployment history.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum events to return (1–100).
	 * @return array<int,array> Newest-first audit events.
	 */
	public static function get_history( $assistant_id, $limit = 20 ) {
		$limit = max( 1, min( self::MAX_HISTORY, (int) $limit ) );
		$raw   = get_post_meta( absint( $assistant_id ), self::META_DEPLOY_HISTORY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_slice( $raw, 0, $limit );
	}

	/**
	 * Detect post-deployment drift for an artifact using eval run-store
	 * summaries and the eval regression detector.
	 *
	 * Run summaries are split on the deployment timestamp: runs before the
	 * deployment form the baseline; runs after it are aggregated into the
	 * current summary. The detector never fires without baseline data.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @param array  $thresholds   Optional threshold overrides for the detector.
	 * @return array Drift report: actionable, detector, reason.
	 */
	public static function detect_drift( $assistant_id, $artifact_type, array $thresholds = array() ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Eval_Regression_Detector' ) || ! class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) {
			return array(
				'actionable' => false,
				'detector'   => array(),
				'reason'     => 'subsystem_unavailable',
			);
		}

		$deployed_at = (int) get_post_meta( $assistant_id, self::META_DEPLOYED_AT_PREFIX . $artifact_type, true );
		if ( $deployed_at <= 0 ) {
			return array(
				'actionable' => false,
				'detector'   => array(),
				'reason'     => 'no_deployment',
			);
		}

		// Prompts are assistant-scoped; skills are site-global, so all runs
		// recorded for the skill type participate.
		$artifact_id = 'prompt' === $artifact_type ? (string) $assistant_id : '';
		$runs        = \WP_MCP_AI_Eval_Run_Store::get_instance()->get_runs_for_artifact( $artifact_type, $artifact_id );

		$baseline = array();
		$post     = array();

		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['summary'] ) || ! is_array( $run['summary'] ) ) {
				continue;
			}
			$normalized = self::normalize_summary( $run['summary'] );
			if ( (int) ( isset( $run['started_at'] ) ? $run['started_at'] : 0 ) < $deployed_at ) {
				$baseline[] = $normalized;
			} else {
				$post[] = $normalized;
			}
		}

		if ( empty( $baseline ) ) {
			return array(
				'actionable' => false,
				'detector'   => array(),
				'reason'     => 'no_baseline_runs',
			);
		}

		if ( empty( $post ) ) {
			return array(
				'actionable' => false,
				'detector'   => array(),
				'reason'     => 'no_post_deploy_runs',
			);
		}

		$current = array(
			'pass_rate'       => self::mean_of( $post, 'pass_rate' ),
			'error_rate'      => self::mean_of( $post, 'error_rate' ),
			'abstention_rate' => self::mean_of( $post, 'abstention_rate' ),
		);

		$detector = \WP_MCP_AI_Eval_Regression_Detector::detect( $current, $baseline, $thresholds );

		return array(
			'actionable' => ! empty( $detector['is_regression'] ),
			'detector'   => $detector,
			'reason'     => ! empty( $detector['is_regression'] ) ? 'drift_detected' : 'within_thresholds',
		);
	}

	/**
	 * Detect drift and roll back automatically when the site opted in.
	 *
	 * Automatic rollback is off by default (human-in-the-loop). When disabled
	 * the drift report is returned unchanged so the admin queue (Phase G) can
	 * surface it.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @param array  $thresholds    Optional threshold overrides for the detector.
	 * @return array Drift report plus rolled_back flag.
	 */
	public static function check_and_rollback( $assistant_id, $artifact_type, array $thresholds = array() ) {
		$drift = self::detect_drift( $assistant_id, $artifact_type, $thresholds );

		/**
		 * Filters whether drift-triggered rollback may run automatically.
		 *
		 * Default false — rollbacks require human confirmation.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $auto_rollback Whether to roll back automatically.
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $artifact_type Artifact type.
		 */
		$auto_rollback = (bool) apply_filters( 'wp_mcp_ai_artifact_deploy_auto_rollback', false, $assistant_id, $artifact_type );

		if ( ! empty( $drift['actionable'] ) && $auto_rollback ) {
			$result = self::rollback( $assistant_id, $artifact_type );

			if ( ! is_wp_error( $result ) ) {
				self::record_event(
					$assistant_id,
					'rollback_drift',
					$artifact_type,
					'',
					array( 'detector' => isset( $drift['detector'] ) ? $drift['detector'] : array() )
				);
			}

			return array(
				'drift'       => $drift,
				'rolled_back' => ! is_wp_error( $result ),
			);
		}

		return array(
			'drift'       => $drift,
			'rolled_back' => false,
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate candidate shape for a deployable artifact type.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param mixed  $candidate     Candidate payload.
	 * @return true|WP_Error
	 */
	private static function validate_candidate( $artifact_type, $candidate ) {
		if ( 'prompt' === $artifact_type ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_invalid_prompt',
					__( 'A deployed prompt must be a non-empty string.', 'nvoos-content-graph-ai-platform' )
				);
			}

			/**
			 * Filters the maximum characters allowed for a deployed prompt.
			 *
			 * Shared with the Phase E admission gate.
			 *
			 * @since 1.9.0
			 *
			 * @param int $max_chars Maximum characters. Default 30000.
			 */
			$max_chars = (int) apply_filters( 'wp_mcp_ai_artifact_admission_max_chars', self::DEFAULT_MAX_PROMPT_CHARS );
			if ( strlen( $candidate ) > $max_chars ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_prompt_too_long',
					sprintf(
						/* translators: %d: maximum allowed characters */
						__( 'The prompt exceeds the %d character deployment limit.', 'nvoos-content-graph-ai-platform' ),
						$max_chars
					)
				);
			}

			return true;
		}

		if ( ! is_array( $candidate ) || empty( $candidate['name'] ) || empty( $candidate['instructions'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_invalid_skill',
				__( 'A deployed skill must include a name and instructions.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Resolve holdout evidence for a promotion, failing closed.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @param array  $options       Promotion options.
	 * @return array|WP_Error Holdout summary, or WP_Error when absent/failed.
	 */
	private static function resolve_holdout( $assistant_id, $artifact_type, array $options ) {
		/**
		 * Filters whether holdout evidence is required for promotion.
		 *
		 * Default true — promotions fail closed without verification.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $require_holdout Whether holdout evidence is required.
		 * @param int    $assistant_id    Assistant post ID.
		 * @param string $artifact_type   Artifact type.
		 */
		$require_holdout = (bool) apply_filters( 'wp_mcp_ai_artifact_deploy_require_holdout', true, $assistant_id, $artifact_type );
		if ( ! $require_holdout ) {
			return array(
				'source' => 'skipped',
				'reason' => __( 'Holdout requirement disabled by site policy.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$verification = isset( $options['verification'] ) && is_array( $options['verification'] ) ? $options['verification'] : array();

		// Inline evaluation path: run the verification gate ourselves.
		if ( empty( $verification ) ) {
			$generators = isset( $options['generators'] ) && is_array( $options['generators'] ) ? $options['generators'] : array();
			$suite      = isset( $options['suite'] ) ? $options['suite'] : null;

			if (
				! isset( $generators['incumbent'], $generators['candidate'] )
				|| ! is_callable( $generators['incumbent'] )
				|| ! is_callable( $generators['candidate'] )
				|| ! ( $suite instanceof \WP_MCP_AI_Eval_Suite )
			) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_no_holdout',
					__( 'Promotion requires holdout evidence: pass a verification payload or generators with a suite.', 'nvoos-content-graph-ai-platform' )
				);
			}

			if ( ! class_exists( __NAMESPACE__ . '\\ArtifactVerificationGate' ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_no_verification_gate',
					__( 'The artifact verification gate is not available.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$verification = ArtifactVerificationGate::evaluate(
				$generators['incumbent'],
				$generators['candidate'],
				$suite,
				array( 'mode' => ArtifactVerificationGate::MODE_NO_REGRESSION )
			);

			if ( ! is_array( $verification ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_holdout_failed',
					__( 'The holdout evaluation could not be completed.', 'nvoos-content-graph-ai-platform' )
				);
			}
		}

		$accepted  = isset( $verification['decision'] ) && 'accept' === $verification['decision'];
		$regressed = (int) ( isset( $verification['regressed_cases'] ) ? $verification['regressed_cases'] : 0 );
		$pass_rate = (float) ( isset( $verification['candidate_pass_rate'] ) ? $verification['candidate_pass_rate'] : 0.0 );

		if ( ! $accepted || $regressed > 0 || $pass_rate < self::MIN_HELD_OUT_PASS_RATE ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_holdout_rejected',
				__( 'The candidate failed the holdout gate: regressions detected or pass rate below the minimum.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return array(
			'source'       => 'verification_gate',
			'verification' => $verification,
		);
	}

	/**
	 * Write an artifact payload into the live runtime.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @param mixed  $payload       Payload to apply ('' removes the artifact).
	 * @return true|WP_Error
	 */
	private static function apply( $assistant_id, $artifact_type, $payload ) {
		if ( 'prompt' === $artifact_type ) {
			$meta_key = defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) && defined( 'WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_PROMPT_META_KEY' )
				? \WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_PROMPT_META_KEY
				: '_wp_mcp_ai_evolved_system_prompt';

			if ( ! is_string( $payload ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_deploy_invalid_prompt',
					__( 'A deployed prompt must be a string.', 'nvoos-content-graph-ai-platform' )
				);
			}

			if ( '' === $payload ) {
				delete_post_meta( $assistant_id, $meta_key );
			} else {
				update_post_meta( $assistant_id, $meta_key, $payload );
			}

			return true;
		}

		// Skills are site-global: replace (or remove) the named skill.
		if ( ! is_array( $payload ) || empty( $payload['name'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_deploy_invalid_skill',
				__( 'A deployed skill must include a name.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$skills = get_option( self::SKILLS_OPTION_KEY, array() );
		if ( ! is_array( $skills ) ) {
			$skills = array();
		}
		$skills[ sanitize_key( (string) $payload['name'] ) ] = $payload;

		return update_option( self::SKILLS_OPTION_KEY, $skills, false ) ? true : new \WP_Error(
			'wp_mcp_ai_artifact_deploy_apply_failed',
			__( 'The skill could not be saved.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Content-address an artifact exactly like the population archive.
	 *
	 * Prompt strings are wrapped in `array( 'prompt' => … )` to match the
	 * evolver's canonical archive shape (see ArtifactLineage::hash_for).
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param mixed  $payload       Artifact payload.
	 * @return string MD5 hash.
	 */
	private static function content_hash( $artifact_type, $payload ) {
		if ( 'prompt' === $artifact_type && is_string( $payload ) ) {
			$payload = array( 'prompt' => $payload );
		}

		return md5(
			wp_json_encode(
				array(
					'artifact_type' => $artifact_type,
					'artifact'      => $payload,
				)
			)
		);
	}

	/**
	 * Append an immutable audit event to the assistant's deployment history.
	 *
	 * Append-only: there is no public API to mutate or delete events; the
	 * list is capped FIFO at MAX_HISTORY entries.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $event_type   Event type (promote|rollback|rollback_drift).
	 * @param string $artifact_type Artifact type.
	 * @param string $hash         Content hash involved ('' for drift rollbacks).
	 * @param array  $extra        Extra event data.
	 * @return void
	 */
	private static function record_event( $assistant_id, $event_type, $artifact_type, $hash, array $extra ) {
		$history = self::get_history( $assistant_id, self::MAX_HISTORY );

		$event = array(
			'event'         => sanitize_key( (string) $event_type ),
			'artifact_type' => sanitize_key( (string) $artifact_type ),
			'hash'          => sanitize_key( (string) $hash ),
			'actor_id'      => get_current_user_id(),
			'timestamp'     => time(),
		);

		if ( isset( $extra['reason'] ) && '' !== (string) $extra['reason'] ) {
			$event['reason'] = sanitize_text_field( (string) $extra['reason'] );
		}

		foreach ( $extra as $key => $value ) {
			if ( in_array( $key, array( 'reason', 'holdout', 'detector' ), true ) ) {
				$event[ $key ] = $value;
			}
		}

		array_unshift( $history, $event );
		$history = array_slice( $history, 0, self::MAX_HISTORY );

		update_post_meta( $assistant_id, self::META_DEPLOY_HISTORY, $history );
	}

	/**
	 * Normalize an eval summary to the detector's three-metric shape.
	 *
	 * @since 1.9.0
	 *
	 * @param array $summary Eval runner summary.
	 * @return array{pass_rate:float,error_rate:float,abstention_rate:float}
	 */
	private static function normalize_summary( array $summary ) {
		return array(
			'pass_rate'       => isset( $summary['pass_rate'] ) ? (float) $summary['pass_rate'] : 0.0,
			'error_rate'      => isset( $summary['error_rate'] ) ? (float) $summary['error_rate'] : 0.0,
			'abstention_rate' => isset( $summary['abstention_rate'] ) ? (float) $summary['abstention_rate'] : 0.0,
		);
	}

	/**
	 * Arithmetic mean of a metric across summary rows.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int,array> $rows   Normalized summaries.
	 * @param string           $metric Metric key.
	 * @return float
	 */
	private static function mean_of( array $rows, $metric ) {
		$total = 0.0;
		$count = 0;

		foreach ( $rows as $row ) {
			if ( isset( $row[ $metric ] ) && is_numeric( $row[ $metric ] ) ) {
				$total += (float) $row[ $metric ];
				++$count;
			}
		}

		return $count > 0 ? $total / $count : 0.0;
	}
}
