<?php
/**
 * Tool for retrieving Newsletter plugin subscribers.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves a filtered list of subscribers from The Newsletter Plugin.
 */
class WP_MCP_AI_Tool_Newsletter_Get_Subscribers implements WP_MCP_AI_Tool_Interface {
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
		return __( 'The Newsletter Get Subscribers tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_get_subscribers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Newsletter Get Subscribers', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a filtered list of subscribers from The Newsletter Plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'email'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by email address (partial match).', 'wp-mcp-ai' ),
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by status: C (confirmed), S (not confirmed), U (unsubscribed).', 'wp-mcp-ai' ),
					'enum'        => array( 'C', 'S', 'U' ),
				),
				'list'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by list ID.', 'wp-mcp-ai' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of subscribers to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => __( 'Number of subscribers to skip for pagination.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view newsletter subscribers.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'newsletter';
		// Verify the table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_table_missing', __( 'Newsletter subscriber table not found.', 'wp-mcp-ai' ) );
		}

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $arguments['email'] ) ) {
			$where[]  = 'email LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_email( $arguments['email'] ) ) . '%';
		}

		if ( ! empty( $arguments['status'] ) ) {
			$status = strtoupper( sanitize_text_field( $arguments['status'] ) );
			if ( in_array( $status, array( 'C', 'S', 'U' ), true ) ) {
				$where[]  = 'status = %s';
				$values[] = $status;
			}
		}

		if ( ! empty( $arguments['list'] ) ) {
			$list_id = absint( $arguments['list'] );
			// Newsletter plugin supports lists 1-40, validate the list ID.
			if ( $list_id > 0 && $list_id <= 40 ) {
				// Build the column name safely - only digits allowed after validation.
				$where[]  = 'list_' . $list_id . ' = %d';
				$values[] = 1;
			}
		}

		$where_clause = implode( ' AND ', $where );

		// Build and execute the query.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare( "SELECT id, email, name, surname, status, created FROM $table_name WHERE $where_clause ORDER BY created DESC LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare( "SELECT id, email, name, surname, status, created FROM $table_name WHERE $where_clause ORDER BY created DESC LIMIT %d OFFSET %d", array( $limit, $offset ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $query );

		if ( null === $results ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_query_failed', __( 'Failed to retrieve subscribers.', 'wp-mcp-ai' ) );
		}

		$subscribers = array();

		foreach ( $results as $row ) {
			$subscribers[] = array(
				'id'         => absint( $row->id ),
				'email'      => sanitize_email( $row->email ),
				'first_name' => sanitize_text_field( $row->name ),
				'last_name'  => sanitize_text_field( $row->surname ),
				'status'     => sanitize_text_field( $row->status ),
				'created'    => sanitize_text_field( $row->created ),
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
			'subscribers' => $subscribers,
			'total'       => absint( $total ),
			'limit'       => $limit,
			'offset'      => $offset,
		);
	}
}
