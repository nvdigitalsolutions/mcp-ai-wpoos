<?php
/**
 * Tool for retrieving a single customer from the CRM.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a single customer record.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Get_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && post_type_exists( 'mcp_ai_customer' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Customer tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Customer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve a single customer record by ID with full contact details, billing data, and source attribution.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the customer to retrieve.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'customer_id' ),
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
			'read-only',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'account_executive', 'sdr' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_crm_toolkit_disabled',
				self::get_unavailable_reason(),
				array( 'status' => 403 )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		$customer_id = absint( $arguments['customer_id'] );
		$post        = get_post( $customer_id );

		if ( ! $post || 'mcp_ai_customer' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_customer_not_found',
				__( 'Customer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$customer = array(
			'id'    => $post->ID,
			'title' => $post->post_title,
			'date'  => $post->post_date,
		);

		if ( class_exists( 'WP_MCP_AI_Customer_CPT' ) ) {
			$meta     = WP_MCP_AI_Customer_CPT::get_customer_meta( $post->ID );
			$customer = array_merge( $customer, $meta );
		}

		// Enrich owner display name.
		if ( ! empty( $customer['contact_owner'] ) ) {
			$owner                  = get_userdata( (int) $customer['contact_owner'] );
			$customer['owner_name'] = $owner ? $owner->display_name : '';
		}

		return array(
			'success'  => true,
			'customer' => $customer,
		);
	}
}
