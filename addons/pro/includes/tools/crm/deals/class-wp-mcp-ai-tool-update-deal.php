<?php
/**
 * Tool for updating CRM deals/opportunities.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update Deal Tool.
 *
 * Updates an existing deal/opportunity with:
 * - Editable fields: name, amount, expected close date, owner, notes
 * - Stage progression guard (prevents changes from closed_won/lost)
 * - Uses canonical envelope for success/WP_Error for failure
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Update_Deal implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store
	 */
	private $data_store;

	/**
	 * Determine whether the tool is available.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Update Deal tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'crm', 'deals' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_deal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Deal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Update an existing CRM deal/opportunity. Prevents modification of closed-won or closed-lost deals.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Deal ID to update (required)', 'mcp-ai-wpoos-pro' ),
				),
				'deal_name'           => array(
					'type'        => 'string',
					'description' => __( 'Updated deal name', 'mcp-ai-wpoos-pro' ),
				),
				'amount'              => array(
					'type'        => 'number',
					'description' => __( 'Updated deal amount', 'mcp-ai-wpoos-pro' ),
				),
				'expected_close_date' => array(
					'type'        => 'string',
					'description' => __( 'Updated expected close date (Y-m-d)', 'mcp-ai-wpoos-pro' ),
				),
				'deal_owner'          => array(
					'type'        => 'integer',
					'description' => __( 'Updated deal owner user ID', 'mcp-ai-wpoos-pro' ),
				),
				'notes'               => array(
					'type'        => 'string',
					'description' => __( 'Updated deal notes', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! $this->data_store ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gateway 1: Sanitize inputs.
		$deal_id = isset( $arguments['deal_id'] ) ? absint( $arguments['deal_id'] ) : 0;

		if ( ! $deal_id ) {
			return new WP_Error(
				'invalid_deal',
				__( 'A valid deal ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Retrieve existing deal.
		$existing = $this->data_store->get_item( $deal_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		// Guard: prevent updating closed deals.
		$current_stage = isset( $existing['pipeline_stage'] ) ? $existing['pipeline_stage'] : '';
		if ( WP_MCP_AI_CRM_Pipeline_Stages::is_won( $current_stage )
			|| WP_MCP_AI_CRM_Pipeline_Stages::is_lost( $current_stage )
		) {
			return new WP_Error(
				'deal_closed',
				sprintf(
					/* translators: %s: current pipeline stage */
					__( 'Cannot update a deal that is already in "%s". Use move_deal_stage to change stages.', 'mcp-ai-wpoos-pro' ),
					esc_html( $current_stage )
				)
			);
		}

		// Build update data — only include provided fields.
		$update_data = array();

		if ( isset( $arguments['deal_name'] ) ) {
			$update_data['deal_name'] = sanitize_text_field( $arguments['deal_name'] );
		}

		if ( isset( $arguments['amount'] ) ) {
			$update_data['amount'] = (float) $arguments['amount'];
		}

		if ( isset( $arguments['expected_close_date'] ) ) {
			$update_data['expected_close_date'] = sanitize_text_field( $arguments['expected_close_date'] );
		}

		if ( isset( $arguments['deal_owner'] ) ) {
			$update_data['deal_owner'] = absint( $arguments['deal_owner'] );
		}

		if ( isset( $arguments['notes'] ) ) {
			$update_data['notes'] = sanitize_textarea_field( $arguments['notes'] );
		}

		if ( empty( $update_data ) ) {
			return new WP_Error(
				'no_fields',
				__( 'No fields provided to update.', 'mcp-ai-wpoos-pro' )
			);
		}

		$update_data['updated_at'] = current_time( 'mysql' );

		// Update using data store.
		$result = $this->data_store->update_item( $deal_id, $update_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_updated',
				'deal',
				$deal_id,
				array(
					'updated_fields' => implode( ',', array_keys( $update_data ) ),
					'action'         => 'update',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal updated successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'        => $deal_id,
				'updated_fields' => array_keys( $update_data ),
				'storage_type'   => $this->data_store->get_storage_type(),
			)
		);
	}
}
