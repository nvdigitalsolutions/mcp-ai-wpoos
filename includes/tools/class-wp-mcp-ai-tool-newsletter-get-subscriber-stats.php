<?php
/**
 * Tool for getting subscriber statistics from the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to get Newsletter plugin subscriber statistics.
 */
class WP_MCP_AI_Tool_Newsletter_Get_Subscriber_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Newsletter Get Subscriber Stats tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_get_subscriber_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Newsletter Subscriber Statistics', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Get statistical overview of Newsletter plugin subscribers including counts by status and lists. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'include_lists' => array(
					'type'        => 'boolean',
					'description' => __( 'Include subscriber counts per list. Default: true.', 'wp-mcp-ai' ),
					'default'     => true,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view newsletter statistics.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter';

		// Get total counts by status.
		$stats = array(
			'total'         => 0,
			'confirmed'     => 0,
			'not_confirmed' => 0,
			'unsubscribed'  => 0,
		);

		$stats['total']         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$stats['confirmed']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'C' ) );
		$stats['not_confirmed'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'S' ) );
		$stats['unsubscribed']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'U' ) );

		// Get subscribers per list if requested.
		$include_lists = isset( $arguments['include_lists'] ) ? (bool) $arguments['include_lists'] : true;
		$list_stats    = array();

		if ( $include_lists ) {
			for ( $i = 1; $i <= 40; $i++ ) {
				$list_field = 'list_' . $i;
				$count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$list_field} = 1 AND status = 'C'" );
				if ( $count > 0 ) {
					$list_stats[ $i ] = $count;
				}
			}
		}

		$result = array(
			'total_subscribers'  => $stats['total'],
			'confirmed'          => $stats['confirmed'],
			'not_confirmed'      => $stats['not_confirmed'],
			'unsubscribed'       => $stats['unsubscribed'],
			'active_subscribers' => $stats['confirmed'],
		);

		if ( $include_lists && ! empty( $list_stats ) ) {
			$result['subscribers_by_list'] = $list_stats;
			$result['active_lists_count']  = count( $list_stats );
		}

		return $result;
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
