<?php
/**
 * Update Term tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_Update_Term — byte-identical slug, schema, error
 * codes, and envelope).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Updates an existing taxonomy term.
 */
class UpdateTermTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'update_term';
	}

	public function getName(): string {
		return __( 'Update Taxonomy Term', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Updates an existing term (category, tag, or custom taxonomy term) with a new name, slug, description, parent, or meta fields.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The term ID to update.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (defaults to "category").', 'nvoos-content-graph-ai' ),
					'default'     => 'category',
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'New term name.', 'nvoos-content-graph-ai' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'New custom slug.', 'nvoos-content-graph-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New term description.', 'nvoos-content-graph-ai' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'New parent term ID, 0 for top-level (hierarchical taxonomies only).', 'nvoos-content-graph-ai' ),
					'minimum'     => 0,
				),
				'meta_input'  => array(
					'type'        => 'object',
					'description' => __( 'Optional term meta key-value pairs to update.', 'nvoos-content-graph-ai' ),
				),
			),
			'required'             => array( 'term_id' ),
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
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update terms.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		// Validate and sanitize inputs.
		$term_id  = isset( $arguments['term_id'] ) ? absint( $arguments['term_id'] ) : 0;
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : 'category';

		if ( $term_id <= 0 ) {
			return new \WP_Error( 'wp_mcp_ai_missing_term', __( 'Term ID is required.', 'nvoos-content-graph-ai' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'nvoos-content-graph-ai' ) );
		}

		// Verify term exists.
		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! $term || ! isset( $term->term_id ) ) {
			return new \WP_Error( 'wp_mcp_ai_term_not_found', __( 'The specified term does not exist.', 'nvoos-content-graph-ai' ) );
		}

		// Get taxonomy object to check capabilities.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The taxonomy could not be loaded.', 'nvoos-content-graph-ai' ) );
		}

		// Check if user can edit terms in this taxonomy.
		$edit_cap = isset( $tax_object->cap->edit_terms ) ? $tax_object->cap->edit_terms : 'manage_categories';

		if ( ! user_can( $current_user_id, $edit_cap ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Dynamic taxonomy capability resolved from the taxonomy object.
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit terms in this taxonomy.', 'nvoos-content-graph-ai' ) );
		}

		// Prepare term update arguments.
		$update_args = array();

		if ( isset( $arguments['name'] ) && '' !== $arguments['name'] ) {
			$update_args['name'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['slug'] ) && '' !== $arguments['slug'] ) {
			$update_args['slug'] = sanitize_title( $arguments['slug'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$update_args['description'] = sanitize_textarea_field( $arguments['description'] );
		}

		// Handle parent for hierarchical taxonomies.
		if ( isset( $arguments['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent_id = absint( $arguments['parent'] );
			if ( $parent_id > 0 ) {
				// Validate parent term exists and isn't the same term.
				if ( $parent_id === $term_id ) {
					return new \WP_Error( 'wp_mcp_ai_invalid_parent', __( 'A term cannot be its own parent.', 'nvoos-content-graph-ai' ) );
				}

				$parent_term = get_term( $parent_id, $taxonomy );
				if ( ! is_wp_error( $parent_term ) && $parent_term ) {
					$update_args['parent'] = $parent_id;
				} else {
					return new \WP_Error( 'wp_mcp_ai_invalid_parent', __( 'The specified parent term does not exist.', 'nvoos-content-graph-ai' ) );
				}
			} elseif ( 0 === $parent_id ) {
				// Explicitly set to top-level.
				$update_args['parent'] = 0;
			}
		}

		// Update the term if there are any changes.
		if ( ! empty( $update_args ) ) {
			$result = wp_update_term( $term_id, $taxonomy, $update_args );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Refresh term object.
			$term = get_term( $term_id, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'wp_mcp_ai_unknown_error', __( 'The term was updated but could not be retrieved.', 'nvoos-content-graph-ai' ) );
			}
		}

		// Handle term meta updates.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$this->update_term_meta( $term_id, $arguments['meta_input'] );
		}

		// Prepare response.
		$summary_text = sprintf(
			/* translators: 1: term name, 2: taxonomy name, 3: term ID */
			__( 'Term updated: %1$s in %2$s (ID: %3$d)', 'nvoos-content-graph-ai' ),
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
	 * Update term meta with per-key sanitization (byte-identical to the base).
	 *
	 * @param int   $term_id    Term ID.
	 * @param array $meta_input Meta key-value pairs.
	 * @return void
	 */
	private function update_term_meta( $term_id, $meta_input ) {
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

			update_term_meta( $term_id, $sanitized_key, $sanitized_value );
		}
	}
}
