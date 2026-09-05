<?php
/**
 * Create Term tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_Create_Term — byte-identical slug, schema, error
 * codes, and envelope).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Creates a term in a taxonomy.
 */
class CreateTermTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'create_term';
	}

	public function getName(): string {
		return __( 'Create Taxonomy Term', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Creates a new term (category, tag, or custom taxonomy term) with an optional description, slug, parent, and meta fields.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The term name.', 'nvoos-content-graph-ai' ),
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (defaults to "category").', 'nvoos-content-graph-ai' ),
					'default'     => 'category',
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Optional term description.', 'nvoos-content-graph-ai' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'Optional custom slug.', 'nvoos-content-graph-ai' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'Optional parent term ID (hierarchical taxonomies only).', 'nvoos-content-graph-ai' ),
					'minimum'     => 0,
				),
				'meta_input'  => array(
					'type'        => 'object',
					'description' => __( 'Optional term meta key-value pairs.', 'nvoos-content-graph-ai' ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create terms.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		// Validate and sanitize inputs.
		$name     = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : 'category';

		if ( '' === $name ) {
			return new \WP_Error( 'wp_mcp_ai_missing_name', __( 'Term name is required.', 'nvoos-content-graph-ai' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'nvoos-content-graph-ai' ) );
		}

		// Get taxonomy object to check capabilities.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The taxonomy could not be loaded.', 'nvoos-content-graph-ai' ) );
		}

		// Check if user can assign/manage terms in this taxonomy.
		$manage_cap = isset( $tax_object->cap->manage_terms ) ? $tax_object->cap->manage_terms : 'manage_categories';

		if ( ! user_can( $current_user_id, $manage_cap ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Dynamic taxonomy capability resolved from the taxonomy object.
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create terms in this taxonomy.', 'nvoos-content-graph-ai' ) );
		}

		// Check if term already exists.
		$existing_term = term_exists( $name, $taxonomy );
		if ( $existing_term ) {
			return new \WP_Error( 'wp_mcp_ai_term_exists', __( 'A term with this name already exists in the specified taxonomy.', 'nvoos-content-graph-ai' ) );
		}

		// Prepare term arguments.
		$term_args = array();

		if ( isset( $arguments['description'] ) && '' !== $arguments['description'] ) {
			$term_args['description'] = sanitize_textarea_field( $arguments['description'] );
		}

		if ( isset( $arguments['slug'] ) && '' !== $arguments['slug'] ) {
			$term_args['slug'] = sanitize_title( $arguments['slug'] );
		}

		// Handle parent for hierarchical taxonomies.
		if ( isset( $arguments['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent_id = absint( $arguments['parent'] );
			if ( $parent_id > 0 ) {
				// Validate parent term exists.
				$parent_term = get_term( $parent_id, $taxonomy );
				if ( ! is_wp_error( $parent_term ) && $parent_term ) {
					$term_args['parent'] = $parent_id;
				} else {
					return new \WP_Error( 'wp_mcp_ai_invalid_parent', __( 'The specified parent term does not exist.', 'nvoos-content-graph-ai' ) );
				}
			}
		}

		// Create the term.
		$result = wp_insert_term( $name, $taxonomy, $term_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];
		$term    = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wp_mcp_ai_unknown_error', __( 'The term was created but could not be retrieved.', 'nvoos-content-graph-ai' ) );
		}

		// Handle term meta.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$this->add_term_meta( $term_id, $arguments['meta_input'] );
		}

		// Prepare response.
		$summary_text = sprintf(
			/* translators: 1: term name, 2: taxonomy name, 3: term ID */
			__( 'Term created: %1$s in %2$s (ID: %3$d)', 'nvoos-content-graph-ai' ),
			$term->name,
			$taxonomy,
			$term->term_id
		);

		$response = array(
			'message'     => $summary_text,
			'summary'     => $summary_text,
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);

		// Add edit link if available.
		$edit_link = get_edit_term_link( $term->term_id, $taxonomy );
		if ( $edit_link ) {
			$response['edit_link'] = $edit_link;
		}

		return $response;
	}

	/**
	 * Add term meta with per-key sanitization (byte-identical to the base).
	 *
	 * @param int   $term_id    Term ID.
	 * @param array $meta_input Meta key-value pairs.
	 * @return void
	 */
	private function add_term_meta( $term_id, $meta_input ) {
		foreach ( $meta_input as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			// Skip protected meta keys.
			if ( is_protected_meta( $sanitized_key, 'term' ) ) {
				continue;
			}

			// Recursively sanitize arrays.
			if ( is_array( $value ) ) {
				$sanitized_value = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized_value = sanitize_text_field( $value );
			}

			add_term_meta( $term_id, $sanitized_key, $sanitized_value );
		}
	}
}
