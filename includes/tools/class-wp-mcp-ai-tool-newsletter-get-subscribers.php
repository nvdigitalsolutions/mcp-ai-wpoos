<?php
/**
 * Tool for retrieving subscribers from the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to list and filter Newsletter plugin subscribers.
 */
class WP_MCP_AI_Tool_Newsletter_Get_Subscribers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' ) || class_exists( 'NewsletterSubscription' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Get Subscribers tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
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
		return __( 'Get Newsletter Subscribers', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve Newsletter plugin subscribers with filtering options. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of subscribers to retrieve.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of subscribers to skip for pagination.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by subscription status: confirmed, not_confirmed, or unsubscribed.', 'wp-mcp-ai' ),
					'enum'        => array( 'confirmed', 'not_confirmed', 'unsubscribed' ),
				),
				'list_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Filter by list ID (1-40).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 40,
				),
				'email'       => array(
					'type'        => 'string',
					'description' => __( 'Search by email address (partial match supported).', 'wp-mcp-ai' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Search by name (partial match supported).', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view newsletter subscribers.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter';

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit  = min( max( $limit, 1 ), 100 );
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		// Build WHERE clause.
		$where_clauses = array( '1=1' );
		$where_values  = array();

		// Filter by status.
		if ( ! empty( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			$status_map = array(
				'confirmed'     => 'C',
				'not_confirmed' => 'S',
				'unsubscribed'  => 'U',
			);
			if ( isset( $status_map[ $status ] ) ) {
				$where_clauses[] = 'status = %s';
				$where_values[]  = $status_map[ $status ];
			}
		}

		// Filter by list.
		if ( ! empty( $arguments['list_id'] ) ) {
			$list_id = absint( $arguments['list_id'] );
			if ( $list_id > 0 && $list_id <= 40 ) {
				$where_clauses[] = 'list_' . $list_id . ' = 1';
			}
		}

		// Filter by email.
		if ( ! empty( $arguments['email'] ) ) {
			$email = sanitize_text_field( $arguments['email'] );
			$where_clauses[] = 'email LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $email ) . '%';
		}

		// Filter by name.
		if ( ! empty( $arguments['name'] ) ) {
			$name = sanitize_text_field( $arguments['name'] );
			$where_clauses[] = '(name LIKE %s OR surname LIKE %s)';
			$where_values[]  = '%' . $wpdb->esc_like( $name ) . '%';
			$where_values[]  = '%' . $wpdb->esc_like( $name ) . '%';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Build query.
		$query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$where_values[] = $limit;
		$where_values[] = $offset;

		if ( ! empty( $where_values ) ) {
			$query = $wpdb->prepare( $query, $where_values );
		}

		$subscribers = $wpdb->get_results( $query );

		// Get total count.
		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( count( $where_values ) > 2 ) { // Exclude limit and offset from count query.
			$count_where_values = array_slice( $where_values, 0, -2 );
			if ( ! empty( $count_where_values ) ) {
				$count_query = $wpdb->prepare( $count_query, $count_where_values );
			}
		}
		$total = (int) $wpdb->get_var( $count_query );

		// Format subscribers.
		$results = array();
		foreach ( $subscribers as $subscriber ) {
			$status_map = array(
				'C' => 'confirmed',
				'S' => 'not_confirmed',
				'U' => 'unsubscribed',
			);
			$status_label = isset( $status_map[ $subscriber->status ] ) ? $status_map[ $subscriber->status ] : 'unknown';

			// Get subscribed lists.
			$lists = array();
			for ( $i = 1; $i <= 40; $i++ ) {
				$list_field = 'list_' . $i;
				if ( isset( $subscriber->$list_field ) && 1 === (int) $subscriber->$list_field ) {
					$lists[] = $i;
				}
			}

			$results[] = array(
				'id'      => (int) $subscriber->id,
				'email'   => $subscriber->email,
				'name'    => $subscriber->name,
				'surname' => isset( $subscriber->surname ) ? $subscriber->surname : '',
				'status'  => $status_label,
				'lists'   => $lists,
				'created' => isset( $subscriber->created ) ? $subscriber->created : '',
			);
		}

		return array(
			'subscribers' => $results,
			'total'       => $total,
			'limit'       => $limit,
			'offset'      => $offset,
			'count'       => count( $results ),
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
