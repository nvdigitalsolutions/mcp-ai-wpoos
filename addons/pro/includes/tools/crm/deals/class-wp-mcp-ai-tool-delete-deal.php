<?php
/**
 * Tool for deleting CRM deals/opportunities.
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
 * Delete Deal Tool.
 *
 * Permanently deletes a CRM deal/opportunity with:
 * - Confirmation requirement
 * - Destructive operation classification
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Delete_Deal implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Delete Deal tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'delete_deal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Deal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently delete a CRM deal/opportunity. Requires explicit confirmation as this action cannot be undone.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_id' => array(
					'type'        => 'integer',
					'description' => __( 'Deal ID to delete (required)', 'mcp-ai-wpoos-pro' ),
				),
				'confirm' => array(
					'type'        => 'boolean',
					'description' => __( 'Explicit confirmation flag. Must be true to proceed.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_id', 'confirm' ),
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
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'destructive',
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

		// Confirmation guard.
		if ( empty( $arguments['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Deletion requires explicit confirmation. Set "confirm" to true to proceed. This action cannot be undone.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify deal exists before deletion.
		$existing = $this->data_store->get_item( $deal_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		// Perform deletion.
		$result = $this->data_store->delete_item( $deal_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record destructive action in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_deleted',
				'deal',
				$deal_id,
				array(
					'action' => 'delete',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal deleted permanently.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'      => $deal_id,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
