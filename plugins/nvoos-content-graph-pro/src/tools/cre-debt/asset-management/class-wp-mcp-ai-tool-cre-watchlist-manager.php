<?php
/**
 * asset-management/class-wp-mcp-ai-tool-cre-watchlist-manager.php (ecosystem port — Wave F4, cre-debt asset-management batch + blueprint).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-watchlist-manager.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the `__DIR__` calculator require resolves from the
 * addon's already-ported `src/tools/cre-debt/` copy and the BLUEPRINTS_DIR/installer paths resolve
 * from the addon's `src/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Manages a CRE loan watchlist with escalation levels, trigger events,
 * action plans, and resolution tracking. Supports add, update, remove,
 * and list operations with portfolio risk summary.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Watchlist_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const OPTION_KEY = 'wp_mcp_ai_cre_watchlist';

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_watchlist_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Watchlist Manager', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Manage a CRE loan watchlist with escalation levels, trigger events, action plans, and resolution tracking. Supports add, update, remove, and list operations with portfolio risk summary.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'                 => array(
					'type'        => 'string',
					'description' => __( 'Watchlist action to perform.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'add', 'update', 'remove', 'list' ),
				),
				'loan_name'              => array(
					'type'        => 'string',
					'description' => __( 'Loan or property name.', 'nvoos-content-graph-pro' ),
				),
				'balance'                => array(
					'type'        => 'number',
					'description' => __( 'Current outstanding loan balance.', 'nvoos-content-graph-pro' ),
				),
				'trigger_event'          => array(
					'type'        => 'string',
					'description' => __( 'Event that triggered the watchlist addition.', 'nvoos-content-graph-pro' ),
				),
				'escalation_level'       => array(
					'type'        => 'string',
					'description' => __( 'Escalation level for the loan.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'watch', 'elevated', 'critical' ),
				),
				'action_plan'            => array(
					'type'        => 'string',
					'description' => __( 'Planned actions to resolve the issue.', 'nvoos-content-graph-pro' ),
				),
				'resolution_target_date' => array(
					'type'        => 'string',
					'description' => __( 'Target resolution date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
				),
				'resolution_status'      => array(
					'type'        => 'string',
					'description' => __( 'Current resolution status.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'open', 'in_progress', 'resolved' ),
				),
				'watchlist_id'           => array(
					'type'        => 'string',
					'description' => __( 'Unique watchlist entry identifier (required for update/remove).', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = sanitize_text_field( $arguments['action'] ?? '' );

		switch ( $action ) {
			case 'add':
				return $this->add_entry( $arguments );
			case 'update':
				return $this->update_entry( $arguments );
			case 'remove':
				return $this->remove_entry( $arguments );
			case 'list':
				return $this->list_entries();
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use: add, update, remove, or list.', 'nvoos-content-graph-pro' ) );
		}
	}

	/**
	 * Add a new watchlist entry.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function add_entry( array $arguments ): array|\WP_Error {
		$loan_name = sanitize_text_field( $arguments['loan_name'] ?? '' );
		$balance   = (float) ( $arguments['balance'] ?? 0 );

		if ( empty( $loan_name ) ) {
			return new WP_Error( 'missing_field', __( 'loan_name is required for add action.', 'nvoos-content-graph-pro' ) );
		}
		if ( $balance <= 0 ) {
			return new WP_Error( 'missing_field', __( 'balance is required and must be greater than zero for add action.', 'nvoos-content-graph-pro' ) );
		}

		$calc         = WP_MCP_AI_CRE_Debt_Calculator::class;
		$watchlist_id = 'wl_' . wp_generate_uuid4();
		$now          = current_time( 'mysql' );

		$entry = array(
			'watchlist_id'           => $watchlist_id,
			'loan_name'              => $loan_name,
			'balance'                => round( $balance, 2 ),
			'trigger_event'          => sanitize_text_field( $arguments['trigger_event'] ?? '' ),
			'escalation_level'       => sanitize_text_field( $arguments['escalation_level'] ?? 'watch' ),
			'action_plan'            => sanitize_text_field( $arguments['action_plan'] ?? '' ),
			'resolution_target_date' => sanitize_text_field( $arguments['resolution_target_date'] ?? '' ),
			'resolution_status'      => sanitize_text_field( $arguments['resolution_status'] ?? 'open' ),
			'added_date'             => $now,
			'updated_date'           => $now,
		);

		$watchlist                  = get_option( self::OPTION_KEY, array() );
		$watchlist[ $watchlist_id ] = $entry;
		update_option( self::OPTION_KEY, $watchlist );

		$output            = $entry;
		$output['balance'] = $calc::format_currency( $balance );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: loan name */
				__( 'Watchlist entry for "%s" added successfully. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
				$loan_name
			),
			'data'       => $output,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Update an existing watchlist entry.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function update_entry( array $arguments ): array|\WP_Error {
		$watchlist_id = sanitize_text_field( $arguments['watchlist_id'] ?? '' );
		if ( empty( $watchlist_id ) ) {
			return new WP_Error( 'missing_field', __( 'watchlist_id is required for update action.', 'nvoos-content-graph-pro' ) );
		}

		$watchlist = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $watchlist[ $watchlist_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Watchlist entry not found.', 'nvoos-content-graph-pro' ) );
		}

		$updatable_text    = array( 'loan_name', 'trigger_event', 'escalation_level', 'action_plan', 'resolution_target_date', 'resolution_status' );
		$updatable_numeric = array( 'balance' );

		foreach ( $updatable_text as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$watchlist[ $watchlist_id ][ $field ] = sanitize_text_field( $arguments[ $field ] );
			}
		}
		foreach ( $updatable_numeric as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$watchlist[ $watchlist_id ][ $field ] = round( (float) $arguments[ $field ], 2 );
			}
		}

		$watchlist[ $watchlist_id ]['updated_date'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $watchlist );

		$calc              = WP_MCP_AI_CRE_Debt_Calculator::class;
		$output            = $watchlist[ $watchlist_id ];
		$output['balance'] = $calc::format_currency( $output['balance'] );

		return array(
			'success'    => true,
			'message'    => __( 'Watchlist entry updated successfully. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
			'data'       => $output,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Remove a watchlist entry.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function remove_entry( array $arguments ): array|\WP_Error {
		$watchlist_id = sanitize_text_field( $arguments['watchlist_id'] ?? '' );
		if ( empty( $watchlist_id ) ) {
			return new WP_Error( 'missing_field', __( 'watchlist_id is required for remove action.', 'nvoos-content-graph-pro' ) );
		}

		$watchlist = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $watchlist[ $watchlist_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Watchlist entry not found.', 'nvoos-content-graph-pro' ) );
		}

		$removed_name = $watchlist[ $watchlist_id ]['loan_name'];
		unset( $watchlist[ $watchlist_id ] );
		update_option( self::OPTION_KEY, $watchlist );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: loan name */
				__( 'Watchlist entry "%s" removed successfully. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
				$removed_name
			),
			'data'       => array(
				'watchlist_id' => $watchlist_id,
				'removed'      => true,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * List all watchlist entries with portfolio risk summary.
	 *
	 * @return array
	 */
	private function list_entries(): array {
		$watchlist   = get_option( self::OPTION_KEY, array() );
		$all_entries = array_values( $watchlist );
		$calc        = WP_MCP_AI_CRE_Debt_Calculator::class;

		$total_balance       = 0.0;
		$total_days          = 0.0;
		$count_by_escalation = array(
			'watch'    => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'elevated' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'critical' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
		);
		$count_by_status     = array(
			'open'        => array( 'count' => 0 ),
			'in_progress' => array( 'count' => 0 ),
			'resolved'    => array( 'count' => 0 ),
		);

		$formatted = array();
		$now_ts    = strtotime( current_time( 'mysql' ) );

		foreach ( $all_entries as $entry ) {
			$balance           = (float) $entry['balance'];
			$total_balance    += $balance;
			$days_on_watchlist = 0;

			if ( ! empty( $entry['added_date'] ) ) {
				$days_on_watchlist = (int) floor( ( $now_ts - strtotime( $entry['added_date'] ) ) / DAY_IN_SECONDS );
			}
			$total_days += $days_on_watchlist;

			$escalation = $entry['escalation_level'] ?? 'watch';
			if ( isset( $count_by_escalation[ $escalation ] ) ) {
				++$count_by_escalation[ $escalation ]['count'];
				$count_by_escalation[ $escalation ]['balance'] += $balance;
			}

			$status = $entry['resolution_status'] ?? 'open';
			if ( isset( $count_by_status[ $status ] ) ) {
				++$count_by_status[ $status ]['count'];
			}

			$output                      = $entry;
			$output['balance']           = $calc::format_currency( $balance );
			$output['days_on_watchlist'] = $days_on_watchlist;
			$formatted[]                 = $output;
		}

		$entry_count = count( $formatted );
		$avg_days    = ( $entry_count > 0 ) ? round( $total_days / $entry_count, 1 ) : 0;

		// Format escalation balances.
		$formatted_escalation = array();
		foreach ( $count_by_escalation as $level => $data ) {
			$formatted_escalation[ $level ] = array(
				'count'   => $data['count'],
				'balance' => $calc::format_currency( $data['balance'] ),
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: entry count */
				__( '%d watchlist entry(ies) found. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
				$entry_count
			),
			'data'       => array(
				'total_entries' => $entry_count,
				'summary'       => array(
					'total_watchlist_balance' => $calc::format_currency( $total_balance ),
					'count_by_escalation'     => $formatted_escalation,
					'count_by_status'         => $count_by_status,
					'avg_days_on_watchlist'   => $avg_days,
				),
				'entries'       => $formatted,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}
}
