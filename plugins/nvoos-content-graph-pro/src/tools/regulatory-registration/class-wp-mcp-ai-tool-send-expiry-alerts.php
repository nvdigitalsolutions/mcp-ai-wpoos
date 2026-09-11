<?php
/**
 * WP_MCP_AI_Tool_Send_Expiry_Alerts (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Sends expiry alert emails.
 */
class WP_MCP_AI_Tool_Send_Expiry_Alerts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_expiry_alerts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Expiry Alerts', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends automated expiry warning emails for registrations nearing expiration with customizable thresholds and recipient lists.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'days_threshold' => array(
					'type'        => 'integer',
					'description' => __( 'Alert for registrations expiring within this many days (optional, default: 90)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'recipients'     => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify (optional, uses configured recipients if not provided)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
				),
				'countries'      => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'test_mode'      => array(
					'type'        => 'boolean',
					'description' => __( 'Test mode - generate report without sending emails (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'database-write',       // Logs sent emails.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send expiry alerts.', 'nvoos-content-graph-pro' ) );
		}

		$days_threshold = ! empty( $arguments['days_threshold'] ) ? absint( $arguments['days_threshold'] ) : 90;
		$recipients     = ! empty( $arguments['recipients'] ) && is_array( $arguments['recipients'] ) ? array_map( 'sanitize_email', $arguments['recipients'] ) : array();
		$countries      = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();
		$test_mode      = ! empty( $arguments['test_mode'] );

		// Get default recipients if not provided.
		if ( empty( $recipients ) ) {
			$notification_settings = get_option( 'wp_mcp_ai_notification_settings', array() );
			if ( ! empty( $notification_settings['expiry_alert']['recipients'] ) ) {
				$recipients = $notification_settings['expiry_alert']['recipients'];
			} else {
				$recipients = array( get_option( 'admin_email' ) );
			}
		}

		// Build query for expiring registrations.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'send_expiry_alerts', 0, 1000 ) : 1000,
			'meta_query'     => array(
				array(
					'key'     => 'expiry_date',
					'value'   => array(
						gmdate( 'Y-m-d' ),
						gmdate( 'Y-m-d', strtotime( "+{$days_threshold} days" ) ),
					),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);

		// Add country filter.
		if ( ! empty( $countries ) ) {
			$query_args['meta_query'][] = array(
				'key'     => 'country',
				'value'   => $countries,
				'compare' => 'IN',
			);
		}

		$expiring_query = new WP_Query( $query_args );

		$expiring_registrations = array();
		$today                  = time();

		if ( $expiring_query->have_posts() ) {
			foreach ( $expiring_query->posts as $post ) {
				$expiry_date    = get_post_meta( $post->ID, 'expiry_date', true );
				$expiry_time    = strtotime( $expiry_date );
				$days_to_expiry = floor( ( $expiry_time - $today ) / DAY_IN_SECONDS );

				$expiring_registrations[] = array(
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'country'        => get_post_meta( $post->ID, 'country', true ),
					'cos_number'     => get_post_meta( $post->ID, 'cos_number', true ),
					'expiry_date'    => $expiry_date,
					'days_to_expiry' => $days_to_expiry,
				);
			}
		}

		$emails_sent = 0;

		if ( ! $test_mode && ! empty( $expiring_registrations ) ) {
			// Compose email.
			$subject = sprintf(
				/* translators: %d: number of expiring registrations */
				__( 'Registration Expiry Alert: %d registrations expiring soon', 'nvoos-content-graph-pro' ),
				count( $expiring_registrations )
			);

			$message = __( 'The following registrations are expiring soon:', 'nvoos-content-graph-pro' ) . "\n\n";
			foreach ( $expiring_registrations as $reg ) {
				$message .= sprintf(
					"%s (%s) - Expires: %s (%d days)\n",
					$reg['title'],
					$reg['country'],
					$reg['expiry_date'],
					$reg['days_to_expiry']
				);
			}

			// Send emails.
			foreach ( $recipients as $recipient ) {
				if ( wp_mail( $recipient, $subject, $message ) ) {
					++$emails_sent;
				}
			}

			// Log sent alerts.
			$alert_log   = get_option( 'wp_mcp_ai_expiry_alert_log', array() );
			$alert_log[] = array(
				'timestamp'      => current_time( 'mysql' ),
				'user_id'        => $current_user_id,
				'recipients'     => $recipients,
				'registrations'  => count( $expiring_registrations ),
				'days_threshold' => $days_threshold,
			);
			update_option( 'wp_mcp_ai_expiry_alert_log', array_slice( $alert_log, -50 ), false );
		}

		return array(
			'success'                => true,
			'test_mode'              => $test_mode,
			'expiring_registrations' => count( $expiring_registrations ),
			'days_threshold'         => $days_threshold,
			'recipients'             => $recipients,
			'emails_sent'            => $emails_sent,
			'registrations'          => $expiring_registrations,
			'sent_at'                => current_time( 'mysql' ),
		);
	}
}
