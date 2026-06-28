<?php
/**
 * CRE Deal Pipeline Manager — Track and manage CRE loan origination deals through pipeline stages
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
 * Manages a CRE deal pipeline stored in wp_options. Supports create, update,
 * list, get, and delete actions. Each deal tracks property type, loan amount,
 * stage, borrower, originator, and notes.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const OPTION_KEY = 'wp_mcp_ai_cre_deal_pipeline';

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
		return 'cre_deal_pipeline_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Deal Pipeline Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Track and manage CRE loan origination deals through pipeline stages (sourced → screened → LOI → IC review → approved → closing → closed). Supports create, update, list, get, and delete operations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Pipeline action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'update', 'list', 'get', 'delete' ),
				),
				'deal_id'        => array(
					'type'        => 'string',
					'description' => __( 'Unique deal identifier (required for update/get/delete).', 'mcp-ai-wpoos-pro' ),
				),
				'deal_name'      => array(
					'type'        => 'string',
					'description' => __( 'Name or title for the deal.', 'mcp-ai-wpoos-pro' ),
				),
				'property_type'  => array(
					'type'        => 'string',
					'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
				),
				'loan_amount'    => array(
					'type'        => 'number',
					'description' => __( 'Requested loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'property_value' => array(
					'type'        => 'number',
					'description' => __( 'Estimated property value.', 'mcp-ai-wpoos-pro' ),
				),
				'stage'          => array(
					'type'        => 'string',
					'description' => __( 'Current pipeline stage.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sourced', 'screened', 'loi', 'ic_review', 'approved', 'closing', 'closed', 'dead' ),
				),
				'borrower_name'  => array(
					'type'        => 'string',
					'description' => __( 'Borrower or sponsor name.', 'mcp-ai-wpoos-pro' ),
				),
				'originator'     => array(
					'type'        => 'string',
					'description' => __( 'Originator or loan officer name.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Free-form notes about the deal.', 'mcp-ai-wpoos-pro' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = sanitize_text_field( $arguments['action'] ?? '' );

		switch ( $action ) {
			case 'create':
				return $this->create_deal( $arguments );
			case 'update':
				return $this->update_deal( $arguments );
			case 'list':
				return $this->list_deals( $arguments );
			case 'get':
				return $this->get_deal( $arguments );
			case 'delete':
				return $this->delete_deal( $arguments );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use: create, update, list, get, or delete.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Create a new deal in the pipeline.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function create_deal( array $arguments ): array|WP_Error {
		$deal_name = sanitize_text_field( $arguments['deal_name'] ?? '' );
		if ( empty( $deal_name ) ) {
			return new WP_Error( 'missing_field', __( 'deal_name is required for create action.', 'mcp-ai-wpoos-pro' ) );
		}

		$pipeline = get_option( self::OPTION_KEY, array() );
		$deal_id  = 'deal_' . wp_generate_uuid4();

		$deal = array(
			'deal_id'        => $deal_id,
			'deal_name'      => $deal_name,
			'property_type'  => sanitize_text_field( $arguments['property_type'] ?? 'other' ),
			'loan_amount'    => (float) ( $arguments['loan_amount'] ?? 0 ),
			'property_value' => (float) ( $arguments['property_value'] ?? 0 ),
			'stage'          => sanitize_text_field( $arguments['stage'] ?? 'sourced' ),
			'borrower_name'  => sanitize_text_field( $arguments['borrower_name'] ?? '' ),
			'originator'     => sanitize_text_field( $arguments['originator'] ?? '' ),
			'notes'          => sanitize_textarea_field( $arguments['notes'] ?? '' ),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$pipeline[ $deal_id ] = $deal;
		update_option( self::OPTION_KEY, $pipeline );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: deal name */
				__( 'Deal "%s" created successfully. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$deal_name
			),
			'data'    => $deal,
		);
	}

	/**
	 * Update an existing deal.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function update_deal( array $arguments ): array|WP_Error {
		$deal_id = sanitize_text_field( $arguments['deal_id'] ?? '' );
		if ( empty( $deal_id ) ) {
			return new WP_Error( 'missing_field', __( 'deal_id is required for update action.', 'mcp-ai-wpoos-pro' ) );
		}

		$pipeline = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $pipeline[ $deal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Deal not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$updatable = array( 'deal_name', 'property_type', 'loan_amount', 'property_value', 'stage', 'borrower_name', 'originator', 'notes' );
		foreach ( $updatable as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				if ( in_array( $field, array( 'loan_amount', 'property_value' ), true ) ) {
					$pipeline[ $deal_id ][ $field ] = (float) $arguments[ $field ];
				} elseif ( 'notes' === $field ) {
					$pipeline[ $deal_id ][ $field ] = sanitize_textarea_field( $arguments[ $field ] );
				} else {
					$pipeline[ $deal_id ][ $field ] = sanitize_text_field( $arguments[ $field ] );
				}
			}
		}
		$pipeline[ $deal_id ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $pipeline );

		return array(
			'success' => true,
			'message' => __( 'Deal updated successfully. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => $pipeline[ $deal_id ],
		);
	}

	/**
	 * List all deals, optionally filtered by stage or property type.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function list_deals( array $arguments ): array {
		$pipeline = get_option( self::OPTION_KEY, array() );
		$deals    = array_values( $pipeline );

		$filter_stage = sanitize_text_field( $arguments['stage'] ?? '' );
		$filter_type  = sanitize_text_field( $arguments['property_type'] ?? '' );

		if ( $filter_stage ) {
			$deals = array_values(
				array_filter(
					$deals,
					function ( $d ) use ( $filter_stage ) {
						return $d['stage'] === $filter_stage;
					}
				)
			);
		}
		if ( $filter_type ) {
			$deals = array_values(
				array_filter(
					$deals,
					function ( $d ) use ( $filter_type ) {
						return $d['property_type'] === $filter_type;
					}
				)
			);
		}

		$total_volume = 0.0;
		$stage_counts = array();
		foreach ( $deals as $d ) {
			$total_volume      += $d['loan_amount'];
			$s                  = $d['stage'];
			$stage_counts[ $s ] = ( $stage_counts[ $s ] ?? 0 ) + 1;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: deal count */
				__( '%d deal(s) found. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				count( $deals )
			),
			'data'    => array(
				'total_deals'   => count( $deals ),
				'total_volume'  => round( $total_volume, 2 ),
				'stage_summary' => $stage_counts,
				'deals'         => $deals,
			),
		);
	}

	/**
	 * Get a single deal by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function get_deal( array $arguments ): array|WP_Error {
		$deal_id = sanitize_text_field( $arguments['deal_id'] ?? '' );
		if ( empty( $deal_id ) ) {
			return new WP_Error( 'missing_field', __( 'deal_id is required for get action.', 'mcp-ai-wpoos-pro' ) );
		}
		$pipeline = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $pipeline[ $deal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Deal not found.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Deal retrieved. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => $pipeline[ $deal_id ],
		);
	}

	/**
	 * Delete a deal from the pipeline.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function delete_deal( array $arguments ): array|WP_Error {
		$deal_id = sanitize_text_field( $arguments['deal_id'] ?? '' );
		if ( empty( $deal_id ) ) {
			return new WP_Error( 'missing_field', __( 'deal_id is required for delete action.', 'mcp-ai-wpoos-pro' ) );
		}
		$pipeline = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $pipeline[ $deal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Deal not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$deleted = $pipeline[ $deal_id ];
		unset( $pipeline[ $deal_id ] );
		update_option( self::OPTION_KEY, $pipeline );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: deal name */
				__( 'Deal "%s" deleted. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$deleted['deal_name']
			),
			'data'    => $deleted,
		);
	}
}
