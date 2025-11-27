<?php
/**
 * Tool for managing Newsletter plugin campaigns.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages campaigns in The Newsletter Plugin - retrieve and launch campaigns.
 */
class WP_MCP_AI_Tool_Newsletter_Manage_Campaigns implements WP_MCP_AI_Tool_Interface {
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
		return __( 'The Newsletter Manage Campaigns tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_manage_campaigns';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Newsletter Manage Campaigns', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves campaigns and launches campaigns using The Newsletter Plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list (get campaigns), send (launch a campaign).', 'wp-mcp-ai' ),
					'enum'        => array( 'list', 'send' ),
					'default'     => 'list',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => __( 'Filter campaigns by status (for list action).', 'wp-mcp-ai' ),
					'enum'        => array( 'new', 'sending', 'sent', 'paused', 'error' ),
				),
				'email_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the campaign to send (for send action).', 'wp-mcp-ai' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of campaigns to return (for list action).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of campaigns to skip for pagination (for list action).', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'default'     => 0,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage newsletter campaigns.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		if ( 'send' === $action ) {
			return $this->send_campaign( $arguments );
		}

		return $this->list_campaigns( $arguments );
	}

	/**
	 * List campaigns from the Newsletter plugin.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function list_campaigns( array $arguments ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'newsletter_emails';

		// Verify the table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_table_missing', __( 'Newsletter emails table not found.', 'wp-mcp-ai' ) );
		}

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			if ( in_array( $status, array( 'new', 'sending', 'sent', 'paused', 'error' ), true ) ) {
				$where[]  = 'status = %s';
				$values[] = $status;
			}
		}

		$where_clause = implode( ' AND ', $where );

		// Build and execute the query.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare( "SELECT id, subject, status, type, send_on, total, sent, created FROM $table_name WHERE $where_clause ORDER BY created DESC LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare( "SELECT id, subject, status, type, send_on, total, sent, created FROM $table_name WHERE $where_clause ORDER BY created DESC LIMIT %d OFFSET %d", array( $limit, $offset ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $query );

		if ( null === $results ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_query_failed', __( 'Failed to retrieve campaigns.', 'wp-mcp-ai' ) );
		}

		$campaigns = array();

		foreach ( $results as $row ) {
			$campaigns[] = array(
				'id'      => absint( $row->id ),
				'subject' => sanitize_text_field( $row->subject ),
				'status'  => sanitize_text_field( $row->status ),
				'type'    => sanitize_text_field( $row->type ),
				'send_on' => sanitize_text_field( $row->send_on ),
				'total'   => absint( $row->total ),
				'sent'    => absint( $row->sent ),
				'created' => sanitize_text_field( $row->created ),
			);
		}

		// Get total count for pagination info.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_query = $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE $where_clause", $values );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var( $count_query );

		return array(
			'success'   => true,
			'campaigns' => $campaigns,
			'total'     => absint( $total ),
			'limit'     => $limit,
			'offset'    => $offset,
		);
	}

	/**
	 * Send (launch) a campaign.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function send_campaign( array $arguments ) {
		global $wpdb;

		if ( empty( $arguments['email_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_email_id', __( 'Campaign email ID is required for send action.', 'wp-mcp-ai' ) );
		}

		$email_id   = absint( $arguments['email_id'] );
		$table_name = $wpdb->prefix . 'newsletter_emails';

		// Get the campaign.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$email = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $email_id ) );

		if ( ! $email ) {
			return new WP_Error( 'wp_mcp_ai_campaign_not_found', __( 'Campaign not found.', 'wp-mcp-ai' ) );
		}

		// Check if campaign can be sent.
		if ( 'sent' === $email->status ) {
			return new WP_Error( 'wp_mcp_ai_already_sent', __( 'This campaign has already been sent.', 'wp-mcp-ai' ) );
		}

		if ( 'sending' === $email->status ) {
			return new WP_Error( 'wp_mcp_ai_already_sending', __( 'This campaign is currently being sent.', 'wp-mcp-ai' ) );
		}

		// Try to use Newsletter's send functionality.
		if ( class_exists( 'NewsletterEmails' ) && method_exists( 'NewsletterEmails', 'instance' ) ) {
			$newsletter_emails = NewsletterEmails::instance();

			if ( method_exists( $newsletter_emails, 'send' ) ) {
				$result = $newsletter_emails->send( $email );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success'  => true,
					'email_id' => $email_id,
					'message'  => __( 'Campaign has been queued for sending.', 'wp-mcp-ai' ),
				);
			}
		}

		// Fallback: Update campaign status to 'sending' to trigger Newsletter's cron.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table_name,
			array(
				'status'  => 'sending',
				'send_on' => current_time( 'mysql', true ),
			),
			array( 'id' => $email_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'wp_mcp_ai_send_failed', __( 'Failed to queue campaign for sending.', 'wp-mcp-ai' ) );
		}

		return array(
			'success'  => true,
			'email_id' => $email_id,
			'message'  => __( 'Campaign has been queued for sending.', 'wp-mcp-ai' ),
		);
	}
}
