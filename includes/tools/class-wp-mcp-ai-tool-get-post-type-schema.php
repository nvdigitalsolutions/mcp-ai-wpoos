<?php
/**
 * Tool for retrieving the schema of a registered WordPress post type.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the schema of a registered WordPress post type.
 *
 * The response includes the WP-registered metadata (labels, capabilities,
 * supported features, registered taxonomies, available statuses) and,
 * when the pro addon is active, the per-field metadata registered by each
 * pro CPT toolkit via the `wp_mcp_ai_post_type_meta_schema` filter.
 */
class WP_MCP_AI_Tool_Get_Post_Type_Schema implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_post_type_schema';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Post Type Schema', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the schema of a registered WordPress post type: labels, capabilities, supported features, registered taxonomies, available statuses, and (when the pro addon is active) the custom meta field definitions used by each pro CPT toolkit.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'           => array(
					'type'        => 'string',
					'description' => __( 'The post type slug to describe (e.g. "post", "page", "mcp_ai_task").', 'mcp-ai-wpoos' ),
				),
				'include_meta_schema' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include the custom meta field schema registered by each pro CPT toolkit. Defaults to true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'post_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view post type schemas.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['post_type'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'post_type is required.', 'mcp-ai-wpoos' ) );
		}

		$post_type = sanitize_key( $arguments['post_type'] );
		$pto       = get_post_type_object( $post_type );

		if ( ! $pto ) {
			return new WP_Error(
				'wp_mcp_ai_not_found',
				sprintf(
					/* translators: %s: post type slug */
					__( 'The post type "%s" is not registered on this site.', 'mcp-ai-wpoos' ),
					$post_type
				)
			);
		}

		// Build labels.
		$labels = array();
		if ( isset( $pto->labels ) ) {
			$label_map = array(
				'name'               => 'name',
				'singular_name'      => 'singular_name',
				'add_new_item'       => 'add_new_item',
				'edit_item'          => 'edit_item',
				'view_item'          => 'view_item',
				'view_items'         => 'view_items',
				'search_items'       => 'search_items',
				'not_found'          => 'not_found',
				'not_found_in_trash' => 'not_found_in_trash',
				'all_items'          => 'all_items',
				'archives'           => 'archives',
			);
			foreach ( $label_map as $prop => $key ) {
				if ( isset( $pto->labels->$prop ) ) {
					$labels[ $key ] = $pto->labels->$prop;
				}
			}
		}

		// Build capabilities.
		$caps = array();
		if ( isset( $pto->cap ) ) {
			foreach ( (array) $pto->cap as $cap_key => $cap_value ) {
				$caps[ $cap_key ] = $cap_value;
			}
		}

		// Build supported features.
		$supports     = array();
		$all_features = array(
			'title',
			'editor',
			'author',
			'thumbnail',
			'excerpt',
			'trackbacks',
			'custom-fields',
			'comments',
			'revisions',
			'page-attributes',
			'post-formats',
		);
		foreach ( $all_features as $feature ) {
			if ( post_type_supports( $post_type, $feature ) ) {
				$supports[] = $feature;
			}
		}

		// Build registered taxonomies.
		$taxonomies            = array();
		$registered_taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $registered_taxonomies as $tax_slug => $tax_obj ) {
			$taxonomies[ $tax_slug ] = array(
				'label'        => $tax_obj->label,
				'hierarchical' => (bool) $tax_obj->hierarchical,
				'public'       => (bool) $tax_obj->public,
			);
		}

		// Build available statuses for this post type.
		$statuses          = array();
		$all_statuses      = get_post_stati( array(), 'objects' );
		$excluded_statuses = array( 'auto-draft', 'inherit' );
		foreach ( $all_statuses as $status_slug => $status_obj ) {
			if ( in_array( $status_slug, $excluded_statuses, true ) ) {
				continue;
			}
			$statuses[ $status_slug ] = $status_obj->label;
		}

		$result = array(
			'post_type'    => $post_type,
			'label'        => $pto->label,
			'description'  => isset( $pto->description ) ? $pto->description : '',
			'public'       => (bool) $pto->public,
			'hierarchical' => (bool) $pto->hierarchical,
			'has_archive'  => (bool) $pto->has_archive,
			'show_in_rest' => (bool) $pto->show_in_rest,
			'rest_base'    => isset( $pto->rest_base ) ? $pto->rest_base : '',
			'labels'       => $labels,
			'capabilities' => $caps,
			'supports'     => $supports,
			'taxonomies'   => $taxonomies,
			'statuses'     => $statuses,
		);

		// Allow addons (e.g. pro toolkit) to inject meta field definitions.
		$include_meta_schema = isset( $arguments['include_meta_schema'] ) ? (bool) $arguments['include_meta_schema'] : true;
		if ( $include_meta_schema ) {
			/**
			 * Filters the custom meta field schema for a post type.
			 *
			 * Each entry in the returned array should be keyed by the meta key and
			 * contain at minimum 'label', 'type', and 'description'.
			 *
			 * @param array  $meta_schema Associative array of meta field definitions, keyed by meta_key.
			 * @param string $post_type   The post type slug being described.
			 */
			$meta_schema = apply_filters( 'wp_mcp_ai_post_type_meta_schema', array(), $post_type );
			if ( ! empty( $meta_schema ) ) {
				$result['meta_schema'] = $meta_schema;
			}
		}

		$summary_text = sprintf(
			/* translators: %s: post type label */
			__( 'Schema retrieved for post type: %s', 'mcp-ai-wpoos' ),
			$pto->label
		);

		$result['message'] = $summary_text;
		$result['summary'] = $summary_text;

		return $result;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_creator', 'architect' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'read' capability.
			'cacheable',           // Results can be cached.
		);
	}
}
