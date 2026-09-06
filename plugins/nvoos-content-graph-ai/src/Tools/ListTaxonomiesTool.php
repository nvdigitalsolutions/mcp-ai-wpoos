<?php
/**
 * List Taxonomies tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_List_Taxonomies — byte-identical slug, schema, error
 * codes, and envelope).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Lists registered taxonomies (built-in and custom).
 */
class ListTaxonomiesTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'list_taxonomies';
	}

	public function getName(): string {
		return __( 'List Taxonomies', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Lists registered taxonomies (e.g., category, post_tag, product_cat) with their labels, hierarchy, and object types. Use this to discover which taxonomies exist on the site before listing or creating terms.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'public'      => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by public visibility. Omit to list all taxonomies.', 'nvoos-content-graph-ai' ),
				),
				'object_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional post type to filter taxonomies that apply to it (e.g., "post", "product").', 'nvoos-content-graph-ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list taxonomies.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
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
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		$summary_text = sprintf(
			/* translators: %d: number of taxonomies */
			__( 'Found %d registered taxonomies.', 'nvoos-content-graph-ai' ),
			count( $results )
		);

		return array(
			'message'     => $summary_text,
			'summary'     => $summary_text,
			'total_found' => count( $results ),
			'taxonomies'  => $results,
		);
	}
}
