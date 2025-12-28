<?php
/**
 * Tool for updating taxonomy terms.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates existing taxonomy terms (categories, tags, or custom taxonomies).
 *
 * This tool provides comprehensive term updating with support for
 * changing term properties, parent relationships, and metadata.
 */
class WP_MCP_AI_Tool_Update_Term implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Taxonomy Term', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing taxonomy term (category, tag, or custom taxonomy) with new properties, parent relationships, and metadata.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the term to update.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (e.g., "category", "post_tag", or custom taxonomy).', 'wp-mcp-ai' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'New name for the term (optional).', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'New slug for the term (optional).', 'wp-mcp-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New description for the term (optional).', 'wp-mcp-ai' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'New parent term ID for hierarchical taxonomies. Use 0 to make it a top-level term (optional).', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'meta_input'  => array(
					'type'        => 'object',
					'description' => __( 'Array of term meta key-value pairs to update or add.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'term_id', 'taxonomy' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update terms.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate required inputs.
		$term_id  = isset( $arguments['term_id'] ) ? absint( $arguments['term_id'] ) : 0;
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : '';

		if ( 0 === $term_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_term_id', __( 'Term ID is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $taxonomy ) {
			return new WP_Error( 'wp_mcp_ai_missing_taxonomy', __( 'Taxonomy name is required.', 'wp-mcp-ai' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'wp-mcp-ai' ) );
		}

		// Verify term exists.
		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! $term || ! isset( $term->term_id ) ) {
			return new WP_Error( 'wp_mcp_ai_term_not_found', __( 'The specified term does not exist.', 'wp-mcp-ai' ) );
		}

		// Get taxonomy object to check capabilities.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The taxonomy could not be loaded.', 'wp-mcp-ai' ) );
		}

		// Check if user can edit terms in this taxonomy.
		$edit_cap = isset( $tax_object->cap->edit_terms ) ? $tax_object->cap->edit_terms : 'manage_categories';

		if ( ! user_can( $current_user_id, $edit_cap ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit terms in this taxonomy.', 'wp-mcp-ai' ) );
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
					return new WP_Error( 'wp_mcp_ai_invalid_parent', __( 'A term cannot be its own parent.', 'wp-mcp-ai' ) );
				}

				$parent_term = get_term( $parent_id, $taxonomy );
				if ( ! is_wp_error( $parent_term ) && $parent_term ) {
					$update_args['parent'] = $parent_id;
				} else {
					return new WP_Error( 'wp_mcp_ai_invalid_parent', __( 'The specified parent term does not exist.', 'wp-mcp-ai' ) );
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
				return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The term was updated but could not be retrieved.', 'wp-mcp-ai' ) );
			}
		}

		// Handle term meta updates.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$this->update_term_meta( $term_id, $arguments['meta_input'] );
		}

		// Prepare response.
		$response = array(
			'summary'     => sprintf(
				/* translators: 1: term name, 2: taxonomy name, 3: term ID */
				__( 'Term updated: %1$s in %2$s (ID: %3$d)', 'wp-mcp-ai' ),
				$term->name,
				$taxonomy,
				$term->term_id
			),
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
	 * Updates term metadata.
	 *
	 * @param int   $term_id    Term ID.
	 * @param array $meta_input Array of meta key-value pairs.
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

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Updates terms.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires term editing capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Changes can be undone.
		);
	}
}
