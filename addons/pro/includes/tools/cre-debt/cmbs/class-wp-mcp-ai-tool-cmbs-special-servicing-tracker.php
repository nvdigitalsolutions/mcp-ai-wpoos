<?php
/**
 * CMBS Special Servicing Tracker — Track and manage specially serviced loans
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Tracks loans transferred to special servicing with CRUD operations. Stores
 * records in wp_options, tracking transfer reasons, workout strategies,
 * modification terms, resolution outcomes, and recovery rates.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Special_Servicing_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Option key for storing special servicing records.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_cmbs_special_servicing';

	/**
	 * Allowed transfer reasons.
	 *
	 * @var array
	 */
	private static $transfer_reasons = array(
		'payment_default',
		'maturity_default',
		'imminent_default',
		'borrower_request',
	);

	/**
	 * Allowed workout strategies.
	 *
	 * @var array
	 */
	private static $workout_strategies = array(
		'modification',
		'extension',
		'foreclosure',
		'note_sale',
		'reo',
		'discounted_payoff',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cmbs_special_servicing_tracker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Special Servicing Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Track loans transferred to special servicing. Add, update, list, and retrieve specially serviced loan records including transfer reasons, workout strategies, and resolution outcomes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'             => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'update', 'list', 'get' ),
				),
				'loan_id'            => array(
					'type'        => 'string',
					'description' => __( 'Unique loan identifier (required for update/get).', 'mcp-ai-wpoos-pro' ),
				),
				'loan_name'          => array(
					'type'        => 'string',
					'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
				),
				'balance'            => array(
					'type'        => 'number',
					'description' => __( 'Current loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'transfer_date'      => array(
					'type'        => 'string',
					'description' => __( 'Date transferred to special servicing (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'transfer_reason'    => array(
					'type'        => 'string',
					'description' => __( 'Reason for transfer.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'payment_default', 'maturity_default', 'imminent_default', 'borrower_request' ),
				),
				'workout_strategy'   => array(
					'type'        => 'string',
					'description' => __( 'Proposed workout strategy.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'modification', 'extension', 'foreclosure', 'note_sale', 'reo', 'discounted_payoff' ),
				),
				'modification_terms' => array(
					'type'        => 'string',
					'description' => __( 'Description of loan modification terms.', 'mcp-ai-wpoos-pro' ),
				),
				'resolution_date'    => array(
					'type'        => 'string',
					'description' => __( 'Date the loan was resolved (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'recovery_amount'    => array(
					'type'        => 'number',
					'description' => __( 'Amount recovered upon resolution.', 'mcp-ai-wpoos-pro' ),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = sanitize_text_field( $arguments['action'] ?? '' );

		if ( ! in_array( $action, array( 'add', 'update', 'list', 'get' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'Action must be one of: add, update, list, get.', 'mcp-ai-wpoos-pro' ) );
		}

		$records = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}

		switch ( $action ) {
			case 'add':
				return $this->handle_add( $arguments, $records );
			case 'update':
				return $this->handle_update( $arguments, $records );
			case 'list':
				return $this->handle_list( $records );
			case 'get':
				return $this->handle_get( $arguments, $records );
			default:
				return new WP_Error( 'invalid_action', __( 'Unknown action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Add a new special servicing record.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $records   Existing records.
	 * @return array|WP_Error
	 */
	private function handle_add( array $arguments, array $records ) {
		$loan_name = sanitize_text_field( $arguments['loan_name'] ?? '' );
		$balance   = (float) ( $arguments['balance'] ?? 0 );
		$transfer  = sanitize_text_field( $arguments['transfer_date'] ?? '' );
		$reason    = sanitize_text_field( $arguments['transfer_reason'] ?? '' );
		$workout   = sanitize_text_field( $arguments['workout_strategy'] ?? '' );
		$mod_terms = sanitize_text_field( $arguments['modification_terms'] ?? '' );

		if ( empty( $loan_name ) || $balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan name and positive balance are required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! empty( $reason ) && ! in_array( $reason, self::$transfer_reasons, true ) ) {
			return new WP_Error( 'invalid_input', __( 'Invalid transfer reason.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! empty( $workout ) && ! in_array( $workout, self::$workout_strategies, true ) ) {
			return new WP_Error( 'invalid_input', __( 'Invalid workout strategy.', 'mcp-ai-wpoos-pro' ) );
		}

		$loan_id = 'ss_' . wp_generate_uuid4();

		$record = array(
			'loan_id'            => $loan_id,
			'loan_name'          => $loan_name,
			'balance'            => $balance,
			'transfer_date'      => $transfer,
			'transfer_reason'    => $reason,
			'workout_strategy'   => $workout,
			'modification_terms' => $mod_terms,
			'resolution_date'    => '',
			'recovery_amount'    => 0.0,
			'status'             => 'active',
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
		);

		$records[ $loan_id ] = $record;
		update_option( self::OPTION_KEY, $records, false );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: loan name, 2: loan ID */
				__( 'Special servicing record added for %1$s (ID: %2$s).', 'mcp-ai-wpoos-pro' ),
				$loan_name,
				$loan_id
			),
			'data'    => array(
				'record'     => $record,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Update an existing special servicing record.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $records   Existing records.
	 * @return array|WP_Error
	 */
	private function handle_update( array $arguments, array $records ) {
		$loan_id = sanitize_text_field( $arguments['loan_id'] ?? '' );

		if ( empty( $loan_id ) || ! isset( $records[ $loan_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Loan record not found. Provide a valid loan_id.', 'mcp-ai-wpoos-pro' ) );
		}

		$record = $records[ $loan_id ];

		// Update allowed fields.
		$updatable = array(
			'loan_name',
			'balance',
			'transfer_date',
			'transfer_reason',
			'workout_strategy',
			'modification_terms',
			'resolution_date',
			'recovery_amount',
		);

		foreach ( $updatable as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				if ( in_array( $field, array( 'balance', 'recovery_amount' ), true ) ) {
					$record[ $field ] = (float) $arguments[ $field ];
				} else {
					$record[ $field ] = sanitize_text_field( $arguments[ $field ] );
				}
			}
		}

		// Validate transfer reason if changed.
		if ( ! empty( $record['transfer_reason'] ) && ! in_array( $record['transfer_reason'], self::$transfer_reasons, true ) ) {
			return new WP_Error( 'invalid_input', __( 'Invalid transfer reason.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate workout strategy if changed.
		if ( ! empty( $record['workout_strategy'] ) && ! in_array( $record['workout_strategy'], self::$workout_strategies, true ) ) {
			return new WP_Error( 'invalid_input', __( 'Invalid workout strategy.', 'mcp-ai-wpoos-pro' ) );
		}

		// Mark as resolved if resolution date is set.
		if ( ! empty( $record['resolution_date'] ) ) {
			$record['status'] = 'resolved';
		}

		$record['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$records[ $loan_id ] = $record;
		update_option( self::OPTION_KEY, $records, false );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: loan name */
				__( 'Special servicing record updated for %s.', 'mcp-ai-wpoos-pro' ),
				$record['loan_name']
			),
			'data'    => array(
				'record'     => $record,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * List all special servicing records with summary stats.
	 *
	 * @param array $records Existing records.
	 * @return array
	 */
	private function handle_list( array $records ) {
		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$active_count    = 0;
		$active_balance  = 0.0;
		$resolved_count  = 0;
		$total_balance   = 0.0;
		$total_recovered = 0.0;
		$by_reason       = array();
		$by_strategy     = array();

		foreach ( $records as $record ) {
			$total_balance += (float) $record['balance'];

			if ( 'resolved' === $record['status'] ) {
				++$resolved_count;
				$total_recovered += (float) $record['recovery_amount'];
			} else {
				++$active_count;
				$active_balance += (float) $record['balance'];
			}

			$reason = $record['transfer_reason'] ? $record['transfer_reason'] : 'unspecified';
			if ( ! isset( $by_reason[ $reason ] ) ) {
				$by_reason[ $reason ] = 0;
			}
			++$by_reason[ $reason ];

			$strategy = $record['workout_strategy'] ? $record['workout_strategy'] : 'pending';
			if ( ! isset( $by_strategy[ $strategy ] ) ) {
				$by_strategy[ $strategy ] = 0;
			}
			++$by_strategy[ $strategy ];
		}

		$resolved_balance = $total_balance - $active_balance;
		$resolution_rate  = ( count( $records ) > 0 ) ? $resolved_count / count( $records ) : 0;
		$recovery_rate    = ( $resolved_balance > 0 ) ? $total_recovered / $resolved_balance : 0;

		$summary = array(
			'total_records'   => count( $records ),
			'active_count'    => $active_count,
			'active_balance'  => $calc::format_currency( $active_balance ),
			'resolved_count'  => $resolved_count,
			'total_balance'   => $calc::format_currency( $total_balance ),
			'total_recovered' => $calc::format_currency( $total_recovered ),
			'resolution_rate' => $calc::format_percentage( $resolution_rate ),
			'recovery_rate'   => $calc::format_percentage( $recovery_rate ),
			'by_reason'       => $by_reason,
			'by_strategy'     => $by_strategy,
		);

		// Format records for output.
		$formatted = array();
		foreach ( $records as $record ) {
			$formatted[] = array(
				'loan_id'          => $record['loan_id'],
				'loan_name'        => $record['loan_name'],
				'balance'          => $calc::format_currency( (float) $record['balance'] ),
				'status'           => $record['status'],
				'transfer_date'    => $record['transfer_date'],
				'transfer_reason'  => $record['transfer_reason'],
				'workout_strategy' => $record['workout_strategy'],
				'resolution_date'  => $record['resolution_date'],
				'recovery_amount'  => $calc::format_currency( (float) $record['recovery_amount'] ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total records, 2: active, 3: resolved */
				__( '%1$d special servicing records: %2$d active, %3$d resolved.', 'mcp-ai-wpoos-pro' ),
				count( $records ),
				$active_count,
				$resolved_count
			),
			'data'    => array(
				'summary'    => $summary,
				'records'    => $formatted,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Retrieve a single special servicing record.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $records   Existing records.
	 * @return array|WP_Error
	 */
	private function handle_get( array $arguments, array $records ) {
		$loan_id = sanitize_text_field( $arguments['loan_id'] ?? '' );

		if ( empty( $loan_id ) || ! isset( $records[ $loan_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Loan record not found. Provide a valid loan_id.', 'mcp-ai-wpoos-pro' ) );
		}

		$record = $records[ $loan_id ];
		$calc   = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Calculate days in special servicing.
		$days_in_ss = 0;
		if ( ! empty( $record['transfer_date'] ) ) {
			$end_date = ! empty( $record['resolution_date'] ) ? $record['resolution_date'] : gmdate( 'Y-m-d' );
			$start_ts = strtotime( $record['transfer_date'] );
			$end_ts   = strtotime( $end_date );
			if ( false !== $start_ts && false !== $end_ts ) {
				$days_in_ss = max( 0, (int) ( ( $end_ts - $start_ts ) / 86400 ) );
			}
		}

		$loss     = (float) $record['balance'] - (float) $record['recovery_amount'];
		$loss_pct = ( (float) $record['balance'] > 0 ) ? $loss / (float) $record['balance'] : 0;

		$detailed = array(
			'loan_id'            => $record['loan_id'],
			'loan_name'          => $record['loan_name'],
			'balance'            => $calc::format_currency( (float) $record['balance'] ),
			'status'             => $record['status'],
			'transfer_date'      => $record['transfer_date'],
			'transfer_reason'    => $record['transfer_reason'],
			'workout_strategy'   => $record['workout_strategy'],
			'modification_terms' => $record['modification_terms'],
			'resolution_date'    => $record['resolution_date'],
			'recovery_amount'    => $calc::format_currency( (float) $record['recovery_amount'] ),
			'realized_loss'      => $calc::format_currency( max( 0, $loss ) ),
			'loss_severity'      => $calc::format_percentage( max( 0, $loss_pct ) ),
			'days_in_servicing'  => $days_in_ss,
			'created_at'         => $record['created_at'],
			'updated_at'         => $record['updated_at'],
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: loan name */
				__( 'Special servicing record retrieved for %s.', 'mcp-ai-wpoos-pro' ),
				$record['loan_name']
			),
			'data'    => array(
				'record'     => $detailed,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
