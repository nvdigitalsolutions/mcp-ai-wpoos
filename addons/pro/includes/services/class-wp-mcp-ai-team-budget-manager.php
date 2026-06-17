<?php
/**
 * Team Budget Manager.
 *
 * Per-team budget enforcement and namespace isolation for the Pro tier.
 * Reads/writes budget caps as post meta on the existing `mcp_ai_team` CPT
 * and tracks daily usage in a per-day option key.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Team_Budget_Manager.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Team_Budget_Manager {

	const META_BUDGET_COST    = '_wp_mcp_ai_team_budget_max_cost_usd_daily';
	const META_BUDGET_TOKENS  = '_wp_mcp_ai_team_budget_max_tokens_daily';
	const META_BUDGET_RUNS    = '_wp_mcp_ai_team_budget_max_runs_daily';
	const META_NAMESPACE      = '_wp_mcp_ai_team_namespace';
	const USAGE_OPTION_PREFIX = 'wp_mcp_ai_team_usage_';

	/**
	 * Singleton instance.
	 *
	 * @since 1.6.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance and registers hooks.
	 *
	 * @since 1.6.0
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	protected function __construct() {
		// Intentionally empty.
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	protected function register_hooks() {
		add_filter( 'wp_mcp_ai_vector_store_namespace', array( $this, 'filter_namespace' ), 10, 1 );
		add_action( 'wp_mcp_ai_after_chat_response', array( $this, 'on_chat_response' ), 10, 3 );
		add_action( 'wp_mcp_ai_workflow_run_completed', array( $this, 'on_workflow_run_completed' ), 10, 2 );
	}

	/**
	 * Static wrapper used by the daily cron hook.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public static function reset_daily_usage_static() {
		self::get_instance()->reset_daily_usage();
	}

	/**
	 * Returns the today usage option key.
	 *
	 * @since 1.6.0
	 *
	 * @param string $date Optional Y-m-d date string.
	 * @return string
	 */
	protected function usage_option_key( $date = '' ) {
		if ( '' === $date ) {
			$date = gmdate( 'Ymd' );
		} else {
			$date = preg_replace( '/[^0-9]/', '', (string) $date );
		}
		return self::USAGE_OPTION_PREFIX . $date;
	}

	/**
	 * Returns the budget caps for a team.
	 *
	 * @since 1.6.0
	 *
	 * @param int $team_id Team post ID.
	 * @return array
	 */
	public function get_team_budget( $team_id ) {
		$team_id = absint( $team_id );
		return array(
			'max_cost_usd_daily' => (float) get_post_meta( $team_id, self::META_BUDGET_COST, true ),
			'max_tokens_daily'   => (int) get_post_meta( $team_id, self::META_BUDGET_TOKENS, true ),
			'max_runs_daily'     => (int) get_post_meta( $team_id, self::META_BUDGET_RUNS, true ),
		);
	}

	/**
	 * Persists budget caps for a team.
	 *
	 * @since 1.6.0
	 *
	 * @param int   $team_id Team post ID.
	 * @param array $budget  Caps to persist.
	 * @return bool
	 */
	public function set_team_budget( $team_id, array $budget ) {
		$team_id = absint( $team_id );
		if ( $team_id <= 0 ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'DOING_TESTS' ) && DOING_TESTS ) ) {
			// Allow in CLI/test contexts; otherwise require capability.
			if ( function_exists( 'wp_doing_cron' ) && ! wp_doing_cron() ) {
				// Fall through if cron — but still require cap for normal requests.
				return false;
			}
		}

		if ( isset( $budget['max_cost_usd_daily'] ) ) {
			update_post_meta( $team_id, self::META_BUDGET_COST, (float) $budget['max_cost_usd_daily'] );
		}
		if ( isset( $budget['max_tokens_daily'] ) ) {
			update_post_meta( $team_id, self::META_BUDGET_TOKENS, absint( $budget['max_tokens_daily'] ) );
		}
		if ( isset( $budget['max_runs_daily'] ) ) {
			update_post_meta( $team_id, self::META_BUDGET_RUNS, absint( $budget['max_runs_daily'] ) );
		}
		return true;
	}

	/**
	 * Returns the namespace for a team (empty string = default).
	 *
	 * @since 1.6.0
	 *
	 * @param int $team_id Team post ID.
	 * @return string
	 */
	public function get_team_namespace( $team_id ) {
		$team_id = absint( $team_id );
		$value   = (string) get_post_meta( $team_id, self::META_NAMESPACE, true );
		return sanitize_text_field( $value );
	}

	/**
	 * Persists the namespace for a team.
	 *
	 * @since 1.6.0
	 *
	 * @param int    $team_id   Team post ID.
	 * @param string $namespace Namespace prefix.
	 * @return bool
	 */
	public function set_team_namespace( $team_id, $namespace ) {
		$team_id   = absint( $team_id );
		$namespace = sanitize_text_field( (string) $namespace );
		if ( $team_id <= 0 ) {
			return false;
		}
		return (bool) update_post_meta( $team_id, self::META_NAMESPACE, $namespace );
	}

	/**
	 * Returns today's usage totals for a team.
	 *
	 * @since 1.6.0
	 *
	 * @param int $team_id Team post ID.
	 * @return array
	 */
	public function get_team_usage_today( $team_id ) {
		$team_id = absint( $team_id );
		$option  = get_option( $this->usage_option_key(), array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		$row = isset( $option[ $team_id ] ) && is_array( $option[ $team_id ] ) ? $option[ $team_id ] : array();
		return array(
			'cost_usd' => isset( $row['cost_usd'] ) ? (float) $row['cost_usd'] : 0.0,
			'tokens'   => isset( $row['tokens'] ) ? (int) $row['tokens'] : 0,
			'runs'     => isset( $row['runs'] ) ? (int) $row['runs'] : 0,
		);
	}

	/**
	 * Records cost/token/run usage for a team.
	 *
	 * @since 1.6.0
	 *
	 * @param int   $team_id  Team post ID.
	 * @param float $cost_usd Cost in USD.
	 * @param int   $tokens   Tokens consumed.
	 * @param int   $runs     Number of runs to add.
	 * @return void
	 */
	public function record_usage( $team_id, $cost_usd, $tokens, $runs = 1 ) {
		$team_id = absint( $team_id );
		if ( $team_id <= 0 ) {
			return;
		}
		$key    = $this->usage_option_key();
		$option = get_option( $key, array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		$row = isset( $option[ $team_id ] ) && is_array( $option[ $team_id ] ) ? $option[ $team_id ] : array(
			'cost_usd' => 0.0,
			'tokens'   => 0,
			'runs'     => 0,
		);

		$row['cost_usd'] = (float) $row['cost_usd'] + (float) $cost_usd;
		$row['tokens']   = (int) $row['tokens'] + absint( $tokens );
		$row['runs']     = (int) $row['runs'] + absint( $runs );

		$option[ $team_id ] = $row;
		update_option( $key, $option, false );
	}

	/**
	 * Verifies whether the team's usage is within its caps.
	 *
	 * @since 1.6.0
	 *
	 * @param int $team_id Team post ID.
	 * @return true|WP_Error
	 */
	public function check_budget( $team_id ) {
		$team_id = absint( $team_id );
		if ( $team_id <= 0 ) {
			return true;
		}
		$budget = $this->get_team_budget( $team_id );
		$usage  = $this->get_team_usage_today( $team_id );

		$violation = '';
		if ( $budget['max_cost_usd_daily'] > 0 && $usage['cost_usd'] >= $budget['max_cost_usd_daily'] ) {
			$violation = 'cost';
		} elseif ( $budget['max_tokens_daily'] > 0 && $usage['tokens'] >= $budget['max_tokens_daily'] ) {
			$violation = 'tokens';
		} elseif ( $budget['max_runs_daily'] > 0 && $usage['runs'] >= $budget['max_runs_daily'] ) {
			$violation = 'runs';
		}

		if ( '' !== $violation ) {
			/**
			 * Fires when a team has exceeded its daily budget.
			 *
			 * @since 1.6.0
			 *
			 * @param int    $team_id    Team post ID.
			 * @param string $violation  Which cap was exceeded ('cost'|'tokens'|'runs').
			 * @param array  $budget     Budget caps.
			 * @param array  $usage      Today's usage.
			 */
			do_action( 'wp_mcp_ai_team_budget_exceeded', $team_id, $violation, $budget, $usage );

			return new WP_Error(
				'wp_mcp_ai_team_budget_exceeded',
				sprintf(
					/* translators: %s: violation kind */
					__( 'Team budget exceeded: %s.', 'mcp-ai-wpoos' ),
					esc_html( $violation )
				),
				array(
					'team_id'   => $team_id,
					'violation' => $violation,
					'budget'    => $budget,
					'usage'     => $usage,
				)
			);
		}
		return true;
	}

	/**
	 * Daily cron handler — clears yesterday's usage option to keep storage small.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function reset_daily_usage() {
		$keep_today = $this->usage_option_key();
		// Delete entries older than 7 days.
		for ( $i = 7; $i <= 30; $i++ ) {
			$old_key = $this->usage_option_key( gmdate( 'Ymd', time() - ( $i * DAY_IN_SECONDS ) ) );
			if ( $old_key !== $keep_today ) {
				delete_option( $old_key );
			}
		}
	}

	/**
	 * Filter callback for `wp_mcp_ai_vector_store_namespace`.
	 *
	 * Prepends the team namespace if the current request context exposes a
	 * `team_id`. The context is read from a shared filter so we do not couple
	 * to a specific REST controller signature.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace Incoming namespace.
	 * @return string
	 */
	public function filter_namespace( $namespace ) {
		$context = apply_filters( 'wp_mcp_ai_current_request_context', array() );
		if ( ! is_array( $context ) || empty( $context['team_id'] ) ) {
			return $namespace;
		}
		$team_id = absint( $context['team_id'] );
		if ( $team_id <= 0 ) {
			return $namespace;
		}
		$prefix = $this->get_team_namespace( $team_id );
		if ( '' === $prefix ) {
			return $namespace;
		}
		if ( '' === $namespace ) {
			return $prefix;
		}
		return $prefix . '/' . $namespace;
	}

	/**
	 * Records cost/tokens for a chat response.
	 *
	 * Expected `$context` keys: `team_id`, optional `cost_usd`, `tokens`.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $response Response payload.
	 * @param mixed $request  Request payload.
	 * @param array $context  Request context.
	 * @return void
	 */
	public function on_chat_response( $response, $request, $context = array() ) {
		unset( $response, $request );
		if ( ! is_array( $context ) || empty( $context['team_id'] ) ) {
			return;
		}
		$team_id  = absint( $context['team_id'] );
		$cost_usd = isset( $context['cost_usd'] ) ? (float) $context['cost_usd'] : 0.0;
		$tokens   = isset( $context['tokens'] ) ? absint( $context['tokens'] ) : 0;
		if ( $cost_usd > 0 || $tokens > 0 ) {
			$this->record_usage( $team_id, $cost_usd, $tokens, 0 );
		}
	}

	/**
	 * Records run count for a workflow run.
	 *
	 * @since 1.6.0
	 *
	 * @param int   $run_id  Workflow run ID.
	 * @param array $context Run context (must contain `team_id`).
	 * @return void
	 */
	public function on_workflow_run_completed( $run_id, $context = array() ) {
		unset( $run_id );
		if ( ! is_array( $context ) || empty( $context['team_id'] ) ) {
			return;
		}
		$team_id  = absint( $context['team_id'] );
		$cost_usd = isset( $context['cost_usd'] ) ? (float) $context['cost_usd'] : 0.0;
		$tokens   = isset( $context['tokens'] ) ? absint( $context['tokens'] ) : 0;
		$this->record_usage( $team_id, $cost_usd, $tokens, 1 );
	}
}
