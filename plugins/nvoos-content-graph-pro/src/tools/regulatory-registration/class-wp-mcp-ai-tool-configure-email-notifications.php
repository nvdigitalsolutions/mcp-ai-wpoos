<?php
/**
 * WP_MCP_AI_Tool_Configure_Email_Notifications (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Configures email notification rules.
 */
class WP_MCP_AI_Tool_Configure_Email_Notifications implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'configure_email_notifications';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Configure Email Notifications', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Configures automated email notification rules for registration events, expiry alerts, and status changes with recipient management.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'notification_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of notification to configure (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'expiry_alert', 'status_change', 'submission_confirmation', 'approval_notice' ),
				),
				'enabled'           => array(
					'type'        => 'boolean',
					'description' => __( 'Enable or disable notification (required)', 'nvoos-content-graph-pro' ),
				),
				'recipients'        => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify (optional)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
				),
				'conditions'        => array(
					'type'        => 'object',
					'description' => __( 'Notification trigger conditions (optional)', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'days_before_expiry' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'countries'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'status_from'        => array( 'type' => 'string' ),
						'status_to'          => array( 'type' => 'string' ),
					),
				),
			),
			'required'             => array( 'notification_type', 'enabled' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Saves configuration.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to configure email notifications.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['notification_type'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Notification type is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! isset( $arguments['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Enabled status is required.', 'nvoos-content-graph-pro' ) );
		}

		$notification_type = sanitize_text_field( $arguments['notification_type'] );
		$enabled           = (bool) $arguments['enabled'];
		$recipients        = ! empty( $arguments['recipients'] ) && is_array( $arguments['recipients'] ) ? array_map( 'sanitize_email', $arguments['recipients'] ) : array();
		$conditions        = ! empty( $arguments['conditions'] ) && is_array( $arguments['conditions'] ) ? $arguments['conditions'] : array();

		// Get current notification settings.
		$notification_settings = get_option( 'wp_mcp_ai_notification_settings', array() );

		// Update configuration for this notification type.
		$notification_settings[ $notification_type ] = array(
			'enabled'    => $enabled,
			'recipients' => $recipients,
			'conditions' => $conditions,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => $current_user_id,
		);

		// Save settings.
		update_option( 'wp_mcp_ai_notification_settings', $notification_settings );

		// Log configuration change.
		$log_entry = array(
			'timestamp'         => current_time( 'mysql' ),
			'user_id'           => $current_user_id,
			'notification_type' => $notification_type,
			'action'            => $enabled ? 'enabled' : 'disabled',
		);

		$notification_log   = get_option( 'wp_mcp_ai_notification_log', array() );
		$notification_log[] = $log_entry;
		update_option( 'wp_mcp_ai_notification_log', array_slice( $notification_log, -100 ), false ); // Keep last 100 entries.

		return array(
			'success'           => true,
			'notification_type' => $notification_type,
			'enabled'           => $enabled,
			'recipients_count'  => count( $recipients ),
			'has_conditions'    => ! empty( $conditions ),
			'configured_at'     => current_time( 'mysql' ),
			'message'           => sprintf(
				/* translators: 1: notification type, 2: enabled/disabled */
				__( 'Email notification "%1$s" has been %2$s.', 'nvoos-content-graph-pro' ),
				$notification_type,
				$enabled ? __( 'enabled', 'nvoos-content-graph-pro' ) : __( 'disabled', 'nvoos-content-graph-pro' )
			),
		);
	}
}
