<?php
/**
 * CRE Warehouse Line Manager — Manage warehouse credit facility utilization and loans
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
 * Manages warehouse credit facility status, utilization, and loan-level
 * operations (add, remove, update) with persistent storage in wp_options.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Warehouse_Line_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	private const OPTION_KEY = 'wp_mcp_ai_cre_warehouse_lines';

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
		return 'cre_warehouse_line_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Warehouse Line Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Manage warehouse credit facility utilization — view status, add/remove loans on the line, and update facility terms. Data is persisted in WordPress options.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'status', 'update', 'add_loan', 'remove_loan' ),
				),
				'facility_name'    => array(
					'type'        => 'string',
					'description' => __( 'Name of the warehouse facility.', 'mcp-ai-wpoos-pro' ),
				),
				'facility_size'    => array(
					'type'        => 'number',
					'description' => __( 'Total facility commitment size.', 'mcp-ai-wpoos-pro' ),
				),
				'advance_rate_pct' => array(
					'type'        => 'number',
					'description' => __( 'Default advance rate percentage.', 'mcp-ai-wpoos-pro' ),
					'default'     => 75,
				),
				'loans_on_line'    => array(
					'type'        => 'array',
					'description' => __( 'Array of loan objects currently on the facility.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'           => array(
								'type'        => 'string',
								'description' => __( 'Loan name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'        => array(
								'type'        => 'number',
								'description' => __( 'Loan outstanding balance.', 'mcp-ai-wpoos-pro' ),
							),
							'advance_amount' => array(
								'type'        => 'number',
								'description' => __( 'Amount advanced on the warehouse line for this loan.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance' ),
					),
				),
				'interest_rate'    => array(
					'type'        => 'number',
					'description' => __( 'Facility interest rate as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
				'maturity_date'    => array(
					'type'        => 'string',
					'description' => __( 'Facility maturity date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'facility_name' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action        = sanitize_text_field( $arguments['action'] ?? '' );
		$facility_name = sanitize_text_field( $arguments['facility_name'] ?? '' );

		if ( ! in_array( $action, array( 'status', 'update', 'add_loan', 'remove_loan' ), true ) ) {
			return new \WP_Error( 'invalid_input', __( 'Invalid action. Must be status, update, add_loan, or remove_loan.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $facility_name ) ) {
			return new \WP_Error( 'invalid_input', __( 'Facility name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;
		$all  = get_option( self::OPTION_KEY, array() );
		$key  = sanitize_key( $facility_name );

		switch ( $action ) {
			case 'status':
				return $this->handle_status( $key, $facility_name, $all, $calc );

			case 'update':
				return $this->handle_update( $key, $facility_name, $arguments, $all, $calc );

			case 'add_loan':
				return $this->handle_add_loan( $key, $facility_name, $arguments, $all, $calc );

			case 'remove_loan':
				return $this->handle_remove_loan( $key, $facility_name, $arguments, $all, $calc );

			default:
				return new \WP_Error( 'invalid_action', __( 'Unknown action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Return facility status.
	 *
	 * @param string $key           Sanitized facility key.
	 * @param string $facility_name Display name.
	 * @param array  $all           All stored facilities.
	 * @param string $calc          Calculator class name.
	 * @return array
	 */
	private function handle_status( string $key, string $facility_name, array $all, string $calc ): array {
		if ( ! isset( $all[ $key ] ) ) {
			return array(
				'success'    => true,
				'message'    => __( 'No warehouse facility found with that name. Use "update" to create one.', 'mcp-ai-wpoos-pro' ),
				'data'       => array(
					'facility_name' => $facility_name,
					'exists'        => false,
				),
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$facility = $all[ $key ];
		return $this->build_status_response( $facility, $calc );
	}

	/**
	 * Update or create a facility.
	 *
	 * @param string $key           Sanitized facility key.
	 * @param string $facility_name Display name.
	 * @param array  $arguments     Tool arguments.
	 * @param array  $all           All stored facilities.
	 * @param string $calc          Calculator class name.
	 * @return array
	 */
	private function handle_update( string $key, string $facility_name, array $arguments, array $all, string $calc ): array {
		$facility = $all[ $key ] ?? array(
			'name'          => $facility_name,
			'loans_on_line' => array(),
		);

		if ( isset( $arguments['facility_size'] ) ) {
			$facility['facility_size'] = (float) $arguments['facility_size'];
		}
		if ( isset( $arguments['advance_rate_pct'] ) ) {
			$facility['advance_rate_pct'] = (float) $arguments['advance_rate_pct'];
		}
		if ( isset( $arguments['interest_rate'] ) ) {
			$facility['interest_rate'] = (float) $arguments['interest_rate'];
		}
		if ( isset( $arguments['maturity_date'] ) ) {
			$facility['maturity_date'] = sanitize_text_field( $arguments['maturity_date'] );
		}
		if ( isset( $arguments['loans_on_line'] ) && is_array( $arguments['loans_on_line'] ) ) {
			$facility['loans_on_line'] = $this->sanitize_loans( $arguments['loans_on_line'], (float) ( $facility['advance_rate_pct'] ?? 75 ) );
		}

		$facility['updated_at'] = current_time( 'mysql' );
		$all[ $key ]            = $facility;
		update_option( self::OPTION_KEY, $all, false );

		return $this->build_status_response( $facility, $calc, __( 'Facility updated successfully.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Add a loan to the facility.
	 *
	 * @param string $key           Sanitized facility key.
	 * @param string $facility_name Display name.
	 * @param array  $arguments     Tool arguments.
	 * @param array  $all           All stored facilities.
	 * @param string $calc          Calculator class name.
	 * @return array|\WP_Error
	 */
	private function handle_add_loan( string $key, string $facility_name, array $arguments, array $all, string $calc ): array|\WP_Error {
		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error( 'not_found', __( 'Facility not found. Use "update" to create it first.', 'mcp-ai-wpoos-pro' ) );
		}

		$new_loans = $arguments['loans_on_line'] ?? array();
		if ( empty( $new_loans ) || ! is_array( $new_loans ) ) {
			return new \WP_Error( 'invalid_input', __( 'Provide loans_on_line array to add.', 'mcp-ai-wpoos-pro' ) );
		}

		$facility     = $all[ $key ];
		$advance_rate = (float) ( $facility['advance_rate_pct'] ?? 75 );
		$sanitized    = $this->sanitize_loans( $new_loans, $advance_rate );

		$facility['loans_on_line'] = array_merge( $facility['loans_on_line'] ?? array(), $sanitized );
		$facility['updated_at']    = current_time( 'mysql' );
		$all[ $key ]               = $facility;
		update_option( self::OPTION_KEY, $all, false );

		return $this->build_status_response(
			$facility,
			$calc,
			sprintf(
				/* translators: %d: number of loans added */
				__( '%d loan(s) added to facility.', 'mcp-ai-wpoos-pro' ),
				count( $sanitized )
			)
		);
	}

	/**
	 * Remove a loan from the facility.
	 *
	 * @param string $key           Sanitized facility key.
	 * @param string $facility_name Display name.
	 * @param array  $arguments     Tool arguments.
	 * @param array  $all           All stored facilities.
	 * @param string $calc          Calculator class name.
	 * @return array|\WP_Error
	 */
	private function handle_remove_loan( string $key, string $facility_name, array $arguments, array $all, string $calc ): array|\WP_Error {
		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error( 'not_found', __( 'Facility not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$remove_loans = $arguments['loans_on_line'] ?? array();
		if ( empty( $remove_loans ) || ! is_array( $remove_loans ) ) {
			return new \WP_Error( 'invalid_input', __( 'Provide loans_on_line array with names to remove.', 'mcp-ai-wpoos-pro' ) );
		}

		$remove_names = array();
		foreach ( $remove_loans as $rl ) {
			$remove_names[] = sanitize_text_field( $rl['name'] ?? '' );
		}

		$facility = $all[ $key ];
		$before   = count( $facility['loans_on_line'] ?? array() );

		$facility['loans_on_line'] = array_values(
			array_filter(
				$facility['loans_on_line'] ?? array(),
				function ( $loan ) use ( $remove_names ) {
					return ! in_array( $loan['name'], $remove_names, true );
				}
			)
		);

		$removed                = $before - count( $facility['loans_on_line'] );
		$facility['updated_at'] = current_time( 'mysql' );
		$all[ $key ]            = $facility;
		update_option( self::OPTION_KEY, $all, false );

		return $this->build_status_response(
			$facility,
			$calc,
			sprintf(
				/* translators: %d: number of loans removed */
				__( '%d loan(s) removed from facility.', 'mcp-ai-wpoos-pro' ),
				$removed
			)
		);
	}

	/**
	 * Build a standard status response for a facility.
	 *
	 * @param array  $facility Facility data.
	 * @param string $calc     Calculator class name.
	 * @param string $message  Optional custom message.
	 * @return array
	 */
	private function build_status_response( array $facility, string $calc, string $message = '' ): array {
		$facility_size = (float) ( $facility['facility_size'] ?? 0 );
		$advance_rate  = (float) ( $facility['advance_rate_pct'] ?? 75 );
		$loans         = $facility['loans_on_line'] ?? array();

		$total_balance  = 0.0;
		$total_advanced = 0.0;
		$wa_advance_num = 0.0;

		foreach ( $loans as $loan ) {
			$bal             = (float) ( $loan['balance'] ?? 0 );
			$adv             = (float) ( $loan['advance_amount'] ?? 0 );
			$total_balance  += $bal;
			$total_advanced += $adv;
			if ( $bal > 0 ) {
				$wa_advance_num += ( $adv / $bal ) * $adv;
			}
		}

		$utilization    = ( $facility_size > 0 ) ? $total_advanced / $facility_size : 0;
		$available      = max( 0, $facility_size - $total_advanced );
		$wa_advance_pct = ( $total_advanced > 0 ) ? $wa_advance_num / $total_advanced : $advance_rate / 100;

		if ( empty( $message ) ) {
			$message = __( 'Warehouse facility status retrieved.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'    => true,
			'message'    => $message . ' ' . __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'facility_name'      => $facility['name'] ?? '',
				'facility_size'      => $calc::format_currency( $facility_size ),
				'advance_rate_pct'   => $advance_rate . '%',
				'interest_rate'      => isset( $facility['interest_rate'] ) ? $calc::format_percentage( (float) $facility['interest_rate'] ) : __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'maturity_date'      => $facility['maturity_date'] ?? __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'num_loans'          => count( $loans ),
				'total_loan_balance' => $calc::format_currency( $total_balance ),
				'total_advanced'     => $calc::format_currency( $total_advanced ),
				'available_capacity' => $calc::format_currency( $available ),
				'utilization_rate'   => $calc::format_percentage( $utilization ),
				'wa_advance_rate'    => $calc::format_percentage( $wa_advance_pct ),
				'updated_at'         => $facility['updated_at'] ?? __( 'N/A', 'mcp-ai-wpoos-pro' ),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize an array of loan objects.
	 *
	 * @param array $loans        Raw loan array.
	 * @param float $advance_rate Default advance rate percentage.
	 * @return array Sanitized loans.
	 */
	private function sanitize_loans( array $loans, float $advance_rate ): array {
		$result = array();
		foreach ( $loans as $loan ) {
			$balance  = (float) ( $loan['balance'] ?? 0 );
			$advance  = (float) ( $loan['advance_amount'] ?? ( $balance * $advance_rate / 100 ) );
			$result[] = array(
				'name'           => sanitize_text_field( $loan['name'] ?? '' ),
				'balance'        => $balance,
				'advance_amount' => $advance,
			);
		}
		return $result;
	}
}
