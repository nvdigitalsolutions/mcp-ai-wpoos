<?php
/**
 * Tool for retrieving Newsletter plugin campaign statistics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches analytics (opens, clicks) for Newsletter plugin campaigns.
 */
class WP_MCP_AI_Tool_Newsletter_Get_Stats implements WP_MCP_AI_Tool_Interface {
	/**
	 * Determine whether the Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Get Stats tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_get_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Newsletter Get Campaign Statistics', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches analytics (opens, clicks) for specified Newsletter plugin campaigns.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'email_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the email/campaign to get statistics for.', 'wp-mcp-ai' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of campaigns to return statistics for (when email_id is not specified).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'additionalProperties' => false,
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
			return new WP_Error( 'wp_mcp_ai_newsletter_unavailable', __( 'The Newsletter Plugin is not available.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view newsletter statistics.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;

		$emails_table = $wpdb->prefix . 'newsletter_emails';
		$stats_table  = $wpdb->prefix . 'newsletter_stats';

		// Verify the emails table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$emails_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $emails_table ) );

		if ( ! $emails_table_exists ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_table_missing', __( 'Newsletter emails table not found.', 'wp-mcp-ai' ) );
		}

		// Check if stats table exists (optional tracking data).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats_table ) );

		if ( ! empty( $arguments['email_id'] ) ) {
			// Get stats for a specific email/campaign.
			$email_id = absint( $arguments['email_id'] );

			return $this->get_single_email_stats( $email_id, $emails_table, $stats_table, $stats_table_exists );
		}

		// Get stats for multiple recent campaigns.
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = max( 1, min( 50, $limit ) );

		return $this->get_multiple_email_stats( $limit, $emails_table, $stats_table, $stats_table_exists );
	}

	/**
	 * Get statistics for a single email campaign.
	 *
	 * @param int    $email_id           Email ID.
	 * @param string $emails_table       Emails table name.
	 * @param string $stats_table        Stats table name.
	 * @param bool   $stats_table_exists Whether stats table exists.
	 * @return array|WP_Error
	 */
	protected function get_single_email_stats( $email_id, $emails_table, $stats_table, $stats_table_exists ) {
		global $wpdb;

		// Get email details.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$email = $wpdb->get_row( $wpdb->prepare( "SELECT id, subject, status, send_on, total, sent, track FROM $emails_table WHERE id = %d", $email_id ) );

		if ( ! $email ) {
			return new WP_Error( 'wp_mcp_ai_email_not_found', __( 'Email/campaign not found.', 'wp-mcp-ai' ) );
		}

		$stats = array(
			'id'          => absint( $email->id ),
			'subject'     => sanitize_text_field( $email->subject ),
			'status'      => sanitize_text_field( $email->status ),
			'send_on'     => sanitize_text_field( $email->send_on ),
			'total'       => absint( $email->total ),
			'sent'        => absint( $email->sent ),
			'track'       => (bool) $email->track,
			'open_count'  => 0,
			'open_rate'   => 0,
			'click_count' => 0,
			'click_rate'  => 0,
		);

		if ( $stats_table_exists ) {
			// Get open and click statistics.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$open_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $stats_table WHERE email_id = %d", $email_id ) );

			$stats['open_count'] = absint( $open_count );

			if ( $stats['sent'] > 0 ) {
				$stats['open_rate'] = round( ( $stats['open_count'] / $stats['sent'] ) * 100, 2 );
			}

			// Check for clicked table (newsletter_sent tracks click data).
			$clicked_table = $wpdb->prefix . 'newsletter_sent';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$clicked_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clicked_table ) );

			if ( $clicked_table_exists ) {
				// Check if clicked column exists before querying.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$column_exists = $wpdb->get_var( "SHOW COLUMNS FROM $clicked_table LIKE 'clicked'" );

				if ( $column_exists ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$click_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $clicked_table WHERE email_id = %d AND clicked > 0", $email_id ) );

					$stats['click_count'] = absint( $click_count );

					if ( $stats['sent'] > 0 ) {
						$stats['click_rate'] = round( ( $stats['click_count'] / $stats['sent'] ) * 100, 2 );
					}
				}
			}
		}

		return array(
			'success'  => true,
			'campaign' => $stats,
		);
	}

	/**
	 * Get statistics for multiple email campaigns.
	 *
	 * @param int    $limit              Number of campaigns to retrieve.
	 * @param string $emails_table       Emails table name.
	 * @param string $stats_table        Stats table name.
	 * @param bool   $stats_table_exists Whether stats table exists.
	 * @return array
	 */
	protected function get_multiple_email_stats( $limit, $emails_table, $stats_table, $stats_table_exists ) {
		global $wpdb;

		// Get recent sent emails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$emails = $wpdb->get_results( $wpdb->prepare( "SELECT id, subject, status, send_on, total, sent, track FROM $emails_table WHERE status = 'sent' ORDER BY send_on DESC LIMIT %d", $limit ) );

		if ( empty( $emails ) ) {
			return array(
				'success'   => true,
				'campaigns' => array(),
				'message'   => __( 'No sent campaigns found.', 'wp-mcp-ai' ),
			);
		}

		$campaigns = array();

		foreach ( $emails as $email ) {
			$stats = array(
				'id'          => absint( $email->id ),
				'subject'     => sanitize_text_field( $email->subject ),
				'status'      => sanitize_text_field( $email->status ),
				'send_on'     => sanitize_text_field( $email->send_on ),
				'total'       => absint( $email->total ),
				'sent'        => absint( $email->sent ),
				'track'       => (bool) $email->track,
				'open_count'  => 0,
				'open_rate'   => 0,
				'click_count' => 0,
				'click_rate'  => 0,
			);

			if ( $stats_table_exists ) {
				// Get open count.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$open_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $stats_table WHERE email_id = %d", $email->id ) );

				$stats['open_count'] = absint( $open_count );

				if ( $stats['sent'] > 0 ) {
					$stats['open_rate'] = round( ( $stats['open_count'] / $stats['sent'] ) * 100, 2 );
				}
			}

			$campaigns[] = $stats;
		}

		return array(
			'success'   => true,
			'campaigns' => $campaigns,
			'total'     => count( $campaigns ),
		);
	}
}
