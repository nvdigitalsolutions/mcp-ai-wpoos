<?php
/**
 * Tool for sending expiry alert emails.
 *
 * Allows AI assistants to send automated expiry warning emails
 * for registrations nearing expiration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Send Expiry Alerts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends automated expiry warning emails for registrations nearing expiration with customizable thresholds and recipient lists.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Alert for registrations expiring within this many days (optional, default: 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'recipients'     => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify (optional, uses configured recipients if not provided)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string', 'format' => 'email' ),
				),
				'countries'      => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'test_mode'      => array(
					'type'        => 'boolean',
					'description' => __( 'Test mode - generate report without sending emails (optional, default: false)', 'mcp-ai-wpoos-pro' ),
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
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send expiry alerts.', 'mcp-ai-wpoos-pro' ) );
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
			'posts_per_page' => -1,
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
		$today = time();

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
				__( 'Registration Expiry Alert: %d registrations expiring soon', 'mcp-ai-wpoos-pro' ),
				count( $expiring_registrations )
			);

			$message  = __( 'The following registrations are expiring soon:', 'mcp-ai-wpoos-pro' ) . "\n\n";
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
					$emails_sent++;
				}
			}

			// Log sent alerts.
			$alert_log = get_option( 'wp_mcp_ai_expiry_alert_log', array() );
			$alert_log[] = array(
				'timestamp'       => current_time( 'mysql' ),
				'user_id'         => $current_user_id,
				'recipients'      => $recipients,
				'registrations'   => count( $expiring_registrations ),
				'days_threshold'  => $days_threshold,
			);
			update_option( 'wp_mcp_ai_expiry_alert_log', array_slice( $alert_log, -50 ) );
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
