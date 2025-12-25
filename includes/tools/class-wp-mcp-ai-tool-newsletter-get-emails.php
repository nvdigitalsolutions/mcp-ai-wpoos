<?php
/**
 * Tool for getting newsletter emails from the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to list newsletter emails from the Newsletter plugin.
 */
class WP_MCP_AI_Tool_Newsletter_Get_Emails implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' ) || class_exists( 'NewsletterEmails' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Get Emails tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_get_emails';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Newsletter Emails', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve newsletter email campaigns with filtering options. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of emails to retrieve.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => __( 'Number of emails to skip for pagination.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by status: new, sending, sent, or paused.', 'wp-mcp-ai' ),
					'enum'        => array( 'new', 'sending', 'sent', 'paused' ),
				),
				'type'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by type: message or followup.', 'wp-mcp-ai' ),
					'enum'        => array( 'message', 'followup' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_missing', __( 'Newsletter plugin is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view newsletter emails.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter_emails';

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit  = min( max( $limit, 1 ), 50 );
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		// Build WHERE clause.
		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			$where_clauses[] = 'status = %s';
			$where_values[] = $status;
		}

		if ( ! empty( $arguments['type'] ) ) {
			$type = sanitize_key( $arguments['type'] );
			$where_clauses[] = 'type = %s';
			$where_values[] = $type;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Build query.
		$query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$where_values[] = $limit;
		$where_values[] = $offset;

		if ( ! empty( $where_values ) ) {
			$query = $wpdb->prepare( $query, $where_values );
		}

		$emails = $wpdb->get_results( $query );

		// Get total count.
		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( count( $where_values ) > 2 ) {
			$count_where_values = array_slice( $where_values, 0, -2 );
			if ( ! empty( $count_where_values ) ) {
				$count_query = $wpdb->prepare( $count_query, $count_where_values );
			}
		}
		$total = (int) $wpdb->get_var( $count_query );

		// Format emails.
		$results = array();
		foreach ( $emails as $email ) {
			$stats_table = $wpdb->prefix . 'newsletter_stats';
			$sent_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$stats_table} WHERE email_id = %d", $email->id ) );
			
			$results[] = array(
				'id'         => (int) $email->id,
				'subject'    => $email->subject,
				'type'       => isset( $email->type ) ? $email->type : 'message',
				'status'     => $email->status,
				'track'      => isset( $email->track ) ? (bool) $email->track : false,
				'created'    => isset( $email->created ) ? $email->created : '',
				'updated'    => isset( $email->updated ) ? $email->updated : '',
				'sent_count' => $sent_count,
				'edit_url'   => admin_url( 'admin.php?page=newsletter_emails_edit&id=' . $email->id ),
			);
		}

		return array(
			'emails' => $results,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
			'count'  => count( $results ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'local-only',           // No external API calls.
		);
	}
}
