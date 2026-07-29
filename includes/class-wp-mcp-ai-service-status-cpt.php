<?php
/**
 * Service Component Custom Post Type
 *
 * Registers the `mcp_ai_service` CPT for modelling monitored service
 * components. Each post represents one component (e.g. "OpenAI API",
 * "Tool Registry") with meta fields for slug, group, status, and
 * public visibility.
 *
 * The CPT is not publicly queryable — status data is served via the
 * REST API and [nvoos_status] shortcode. The admin UI is provided
 * by the Pro addon's Status Dashboard page.
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Component CPT class.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_CPT {

	/**
	 * Post type slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_service';

	/**
	 * Register the custom post type and its meta fields.
	 *
	 * Hooks into init at priority 11.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register() {
		self::register_post_type();
		self::register_meta();
	}

	/**
	 * Register the mcp_ai_service custom post type.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function register_post_type() {
		$labels = array(
			'name'               => __( 'Service Components', 'mcp-ai-wpoos' ),
			'singular_name'      => __( 'Service Component', 'mcp-ai-wpoos' ),
			'add_new'            => __( 'Add New', 'mcp-ai-wpoos' ),
			'add_new_item'       => __( 'Add New Service Component', 'mcp-ai-wpoos' ),
			'edit_item'          => __( 'Edit Service Component', 'mcp-ai-wpoos' ),
			'new_item'           => __( 'New Service Component', 'mcp-ai-wpoos' ),
			'view_item'          => __( 'View Service Component', 'mcp-ai-wpoos' ),
			'search_items'       => __( 'Search Service Components', 'mcp-ai-wpoos' ),
			'not_found'          => __( 'No service components found.', 'mcp-ai-wpoos' ),
			'not_found_in_trash' => __( 'No service components found in Trash.', 'mcp-ai-wpoos' ),
			'all_items'          => __( 'All Service Components', 'mcp-ai-wpoos' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'rest_base'       => 'mcp-ai-services',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => array(
				'create_posts' => 'manage_options',
			),
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta fields for the service component CPT.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function register_meta() {
		$meta_fields = array(
			'_mcp_ai_service_slug'           => array(
				'type'        => 'string',
				'description' => __( 'Machine-readable slug for the service component.', 'mcp-ai-wpoos' ),
				'single'      => true,
			),
			'_mcp_ai_service_group'          => array(
				'type'        => 'string',
				'description' => __( 'Grouping category for the component.', 'mcp-ai-wpoos' ),
				'single'      => true,
			),
			'_mcp_ai_service_status'         => array(
				'type'        => 'string',
				'description' => __( 'Current status of the component.', 'mcp-ai-wpoos' ),
				'single'      => true,
				'default'     => 'operational',
			),
			'_mcp_ai_service_public'         => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this component is visible on the public status page.', 'mcp-ai-wpoos' ),
				'single'      => true,
				'default'     => true,
			),
			'_mcp_ai_service_status_updated' => array(
				'type'        => 'string',
				'description' => __( 'ISO 8601 timestamp of the last status change.', 'mcp-ai-wpoos' ),
				'single'      => true,
			),
			'_mcp_ai_service_latency_ms'     => array(
				'type'        => 'integer',
				'description' => __( 'Last measured latency in milliseconds.', 'mcp-ai-wpoos' ),
				'single'      => true,
			),
		);

		foreach ( $meta_fields as $key => $args ) {
			$args = array_merge(
				array(
					'show_in_rest' => true,
					'single'       => true,
				),
				$args
			);

			register_post_meta( self::POST_TYPE, $key, $args );
		}
	}
}
