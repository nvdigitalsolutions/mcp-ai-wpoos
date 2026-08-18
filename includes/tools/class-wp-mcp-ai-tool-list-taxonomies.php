<?php
/**
 * Tool for listing registered taxonomies.
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
 * Lists registered taxonomies (built-in and custom).
 *
 * Read-only discovery companion to `list_terms` / `create_term` /
 * `update_term` so agents can discover which taxonomies exist and their
 * properties before working with terms.
 */
class WP_MCP_AI_Tool_List_Taxonomies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_taxonomies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Taxonomies', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists registered taxonomies (e.g., category, post_tag, product_cat) with their labels, hierarchy, and object types. Use this to discover which taxonomies exist on the site before listing or creating terms.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'public'      => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by public visibility. Omit to list all taxonomies.', 'mcp-ai-wpoos' ),
				),
				'object_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional post type to filter taxonomies that apply to it (e.g., "post", "product").', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list taxonomies.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Gate 1: sanitise all inputs at entry.
		$public      = isset( $arguments['public'] ) ? (bool) $arguments['public'] : null;
		$object_type = isset( $arguments['object_type'] ) ? sanitize_key( $arguments['object_type'] ) : '';

		// Build get_taxonomies() arguments.
		$tax_args = array();

		if ( null !== $public ) {
			$tax_args['public'] = $public;
		}

		if ( '' !== $object_type ) {
			$tax_args['object_type'] = array( $object_type );
		}

		$taxonomies = get_taxonomies( $tax_args, 'objects' );

		if ( is_wp_error( $taxonomies ) ) {
			return $taxonomies;
		}

		$results = array();
		foreach ( $taxonomies as $name => $tax_object ) {
			$item = array(
				'name'         => sanitize_key( $name ),
				'label'        => sanitize_text_field( $tax_object->label ),
				'hierarchical' => (bool) $tax_object->hierarchical,
				'public'       => (bool) $tax_object->public,
				'show_ui'      => (bool) $tax_object->show_ui,
				'object_types' => array_map( 'sanitize_key', (array) $tax_object->object_type ),
			);

			if ( ! empty( $tax_object->rest_base ) ) {
				$item['rest_base'] = sanitize_key( $tax_object->rest_base );
			}

			$results[] = $item;
		}

		// Stable ordering by name for predictable agent output.
		usort(
			$results,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		$summary_text = sprintf(
			/* translators: %d: number of taxonomies */
			__( 'Found %d registered taxonomies.', 'mcp-ai-wpoos' ),
			count( $results )
		);

		return array(
			'message'     => $summary_text, // Chat client display.
			'summary'     => $summary_text, // Backward compatibility.
			'total_found' => count( $results ),
			'taxonomies'  => $results,
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'content_strategist', 'seo_specialist' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'read' capability.
		);
	}
}
