<?php
/**
 * Tool for deleting customers from the CRM.
 *
 * Deletion is compliance-aware: it moves the post to Trash rather than
 * permanently deleting, respecting WordPress trash/restore semantics.
 * Use `force_delete` to skip the trash.
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
 * Delete a customer record.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Delete_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Delete Customer tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Customer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Delete a customer record. By default moves to Trash (recoverable). Use force_delete=true for permanent deletion. Compliance-aware: records the deletion in the audit log.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the customer to delete (required).', 'mcp-ai-wpoos-pro' ),
				),
				'force_delete' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, permanently delete instead of moving to Trash.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
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
		if ( class_exists( 'WP_MCP_AI_CRM_Capabilities' ) ) {
			$map = WP_MCP_AI_CRM_Capabilities::get_map();
			return isset( $map['delete_customer'] ) ? $map['delete_customer'] : 'delete_posts';
		}
		return 'delete_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'destructive',
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
			'profession_tags'       => array( 'sales_manager', 'sales_ops' ),
			'risk_level'            => 'high',
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
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		$customer_id  = absint( $arguments['customer_id'] );
		$force_delete = ! empty( $arguments['force_delete'] );
		$post         = get_post( $customer_id );

		if ( ! $post || 'mcp_ai_customer' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_customer_not_found',
				__( 'Customer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Capture audit data before deletion.
		$email = get_post_meta( $customer_id, 'email', true );
		$name  = $post->post_title;

		/**
		 * Fires before a customer is deleted.
		 *
		 * @since 2.6.0
		 *
		 * @param int    $customer_id  Customer ID.
		 * @param bool   $force_delete Whether permanent deletion.
		 * @param array  $arguments    Original tool arguments.
		 * @param array  $context      Execution context.
		 */
		do_action( 'wp_mcp_ai_customer_before_delete', $customer_id, $force_delete, $arguments, $context );

		// Delete the post.
		$result = wp_delete_post( $customer_id, $force_delete );

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_customer_delete_failed',
				__( 'Failed to delete customer.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a customer is deleted.
		 *
		 * @since 2.6.0
		 *
		 * @param int    $customer_id  Customer ID.
		 * @param bool   $force_delete Whether permanent deletion.
		 * @param string $email        Customer email (for audit trail).
		 * @param string $name         Customer name (for audit trail).
		 */
		do_action( 'wp_mcp_ai_customer_deleted', $customer_id, $force_delete, $email, $name );

		// Record in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'customer_deleted',
				'customer',
				$customer_id,
				array(
					'action'       => 'delete',
					'force_delete' => $force_delete,
					'email'        => $email,
					'name'         => $name,
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		$delete_type = $force_delete ? __( 'permanently deleted', 'mcp-ai-wpoos-pro' ) : __( 'moved to Trash', 'mcp-ai-wpoos-pro' );

		return $this->format_success_response(
			sprintf(
				/* translators: %1$s: customer name, %2$s: delete type */
				__( 'Customer "%1$s" %2$s.', 'mcp-ai-wpoos-pro' ),
				$name,
				$delete_type
			),
			array(
				'customer_id'   => $customer_id,
				'customer_name' => $name,
				'force_delete'  => $force_delete,
			)
		);
	}
}
