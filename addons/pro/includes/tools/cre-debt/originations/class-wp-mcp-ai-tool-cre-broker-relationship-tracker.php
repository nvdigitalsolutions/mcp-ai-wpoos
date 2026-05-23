<?php
/**
 * CRE Broker Relationship Tracker — Track mortgage broker referrals and performance
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

/**
 * Manages broker relationship data stored in wp_options. Tracks referrals, conversions,
 * volume attribution, and performance metrics per broker.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const OPTION_KEY = 'wp_mcp_ai_cre_broker_relationships';

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
		return 'cre_broker_relationship_tracker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Broker Relationship Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Track mortgage broker relationships, deal referrals, conversion rates, volume attribution, and performance statistics. Supports add, update, list, and get_stats actions.', 'mcp-ai-wpoos-pro' );
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
					'enum'        => array( 'add', 'update', 'list', 'get_stats' ),
				),
				'broker_id'          => array(
					'type'        => 'string',
					'description' => __( 'Broker identifier (required for update/get_stats).', 'mcp-ai-wpoos-pro' ),
				),
				'broker_name'        => array(
					'type'        => 'string',
					'description' => __( 'Broker name.', 'mcp-ai-wpoos-pro' ),
				),
				'company'            => array(
					'type'        => 'string',
					'description' => __( 'Brokerage company name.', 'mcp-ai-wpoos-pro' ),
				),
				'deals_referred'     => array(
					'type'        => 'integer',
					'description' => __( 'Total number of deals referred.', 'mcp-ai-wpoos-pro' ),
				),
				'deals_closed'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of referred deals that closed.', 'mcp-ai-wpoos-pro' ),
				),
				'total_volume'       => array(
					'type'        => 'number',
					'description' => __( 'Total closed deal volume attributed to broker.', 'mcp-ai-wpoos-pro' ),
				),
				'avg_deal_size'      => array(
					'type'        => 'number',
					'description' => __( 'Average deal size from this broker.', 'mcp-ai-wpoos-pro' ),
				),
				'last_activity_date' => array(
					'type'        => 'string',
					'description' => __( 'Last activity date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = sanitize_text_field( $arguments['action'] ?? '' );

		switch ( $action ) {
			case 'add':
				return $this->add_broker( $arguments );
			case 'update':
				return $this->update_broker( $arguments );
			case 'list':
				return $this->list_brokers();
			case 'get_stats':
				return $this->get_broker_stats( $arguments );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use: add, update, list, or get_stats.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Add a new broker.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function add_broker( array $arguments ): array|WP_Error {
		$name = sanitize_text_field( $arguments['broker_name'] ?? '' );
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_field', __( 'broker_name is required for add action.', 'mcp-ai-wpoos-pro' ) );
		}

		$brokers   = get_option( self::OPTION_KEY, array() );
		$broker_id = 'broker_' . wp_generate_uuid4();

		$deals_referred = absint( $arguments['deals_referred'] ?? 0 );
		$deals_closed   = absint( $arguments['deals_closed'] ?? 0 );
		$total_volume   = (float) ( $arguments['total_volume'] ?? 0 );
		$avg_deal_size  = (float) ( $arguments['avg_deal_size'] ?? 0 );

		// Auto-calculate avg_deal_size if not provided.
		if ( $avg_deal_size <= 0 && $deals_closed > 0 && $total_volume > 0 ) {
			$avg_deal_size = $total_volume / $deals_closed;
		}

		$conversion = ( $deals_referred > 0 ) ? $deals_closed / $deals_referred : 0.0;

		$broker = array(
			'broker_id'          => $broker_id,
			'broker_name'        => $name,
			'company'            => sanitize_text_field( $arguments['company'] ?? '' ),
			'deals_referred'     => $deals_referred,
			'deals_closed'       => $deals_closed,
			'total_volume'       => round( $total_volume, 2 ),
			'avg_deal_size'      => round( $avg_deal_size, 2 ),
			'conversion_rate'    => round( $conversion, 4 ),
			'last_activity_date' => sanitize_text_field( $arguments['last_activity_date'] ?? current_time( 'Y-m-d' ) ),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$brokers[ $broker_id ] = $broker;
		update_option( self::OPTION_KEY, $brokers );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: broker name */
				__( 'Broker "%s" added. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$name
			),
			'data'    => $broker,
		);
	}

	/**
	 * Update an existing broker record.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function update_broker( array $arguments ): array|WP_Error {
		$broker_id = sanitize_text_field( $arguments['broker_id'] ?? '' );
		if ( empty( $broker_id ) ) {
			return new WP_Error( 'missing_field', __( 'broker_id is required for update action.', 'mcp-ai-wpoos-pro' ) );
		}

		$brokers = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $brokers[ $broker_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Broker not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$updatable = array( 'broker_name', 'company', 'deals_referred', 'deals_closed', 'total_volume', 'avg_deal_size', 'last_activity_date' );
		foreach ( $updatable as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				if ( in_array( $field, array( 'total_volume', 'avg_deal_size' ), true ) ) {
					$brokers[ $broker_id ][ $field ] = (float) $arguments[ $field ];
				} elseif ( in_array( $field, array( 'deals_referred', 'deals_closed' ), true ) ) {
					$brokers[ $broker_id ][ $field ] = absint( $arguments[ $field ] );
				} else {
					$brokers[ $broker_id ][ $field ] = sanitize_text_field( $arguments[ $field ] );
				}
			}
		}

		// Recalculate conversion rate.
		$ref                                      = $brokers[ $broker_id ]['deals_referred'];
		$cls                                      = $brokers[ $broker_id ]['deals_closed'];
		$brokers[ $broker_id ]['conversion_rate'] = ( $ref > 0 ) ? round( $cls / $ref, 4 ) : 0.0;
		$brokers[ $broker_id ]['updated_at']      = current_time( 'mysql' );

		update_option( self::OPTION_KEY, $brokers );

		return array(
			'success' => true,
			'message' => __( 'Broker updated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => $brokers[ $broker_id ],
		);
	}

	/**
	 * List all brokers sorted by total volume.
	 *
	 * @return array
	 */
	private function list_brokers(): array {
		$brokers = get_option( self::OPTION_KEY, array() );
		$list    = array_values( $brokers );

		usort(
			$list,
			function ( $a, $b ) {
				return ( $b['total_volume'] ?? 0 ) <=> ( $a['total_volume'] ?? 0 );
			}
		);

		$total_volume = 0.0;
		$total_deals  = 0;
		foreach ( $list as $b ) {
			$total_volume += $b['total_volume'];
			$total_deals  += $b['deals_closed'];
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: broker count */
				__( '%d broker(s) found. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				count( $list )
			),
			'data'    => array(
				'total_brokers'       => count( $list ),
				'total_closed_volume' => '$' . number_format( $total_volume, 0 ),
				'total_closed_deals'  => $total_deals,
				'brokers'             => $list,
			),
		);
	}

	/**
	 * Get detailed statistics for a single broker.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function get_broker_stats( array $arguments ): array|WP_Error {
		$broker_id = sanitize_text_field( $arguments['broker_id'] ?? '' );
		if ( empty( $broker_id ) ) {
			return new WP_Error( 'missing_field', __( 'broker_id is required for get_stats action.', 'mcp-ai-wpoos-pro' ) );
		}

		$brokers = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $brokers[ $broker_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Broker not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$broker = $brokers[ $broker_id ];

		// Calculate additional metrics.
		$all_volumes  = array_column( array_values( $brokers ), 'total_volume' );
		$total_all    = array_sum( $all_volumes );
		$volume_share = ( $total_all > 0 ) ? $broker['total_volume'] / $total_all : 0.0;

		// Activity status.
		$last_dt       = $broker['last_activity_date'] ?? '';
		$days_inactive = 0;
		if ( $last_dt ) {
			$last_ts = strtotime( $last_dt );
			if ( $last_ts > 0 ) {
				$days_inactive = (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS );
			}
		}

		$activity_status = __( 'Active', 'mcp-ai-wpoos-pro' );
		if ( $days_inactive > 180 ) {
			$activity_status = __( 'Dormant (>6 months)', 'mcp-ai-wpoos-pro' );
		} elseif ( $days_inactive > 90 ) {
			$activity_status = __( 'Inactive (>90 days)', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success' => true,
			'message' => __( 'Broker statistics retrieved. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'broker'          => $broker,
				'performance'     => array(
					'conversion_rate' => round( ( $broker['conversion_rate'] ?? 0 ) * 100, 1 ) . '%',
					'volume_share'    => round( $volume_share * 100, 1 ) . '%',
					'avg_deal_size'   => '$' . number_format( $broker['avg_deal_size'] ?? 0, 0 ),
				),
				'activity_status' => $activity_status,
				'days_inactive'   => $days_inactive,
			),
		);
	}
}
