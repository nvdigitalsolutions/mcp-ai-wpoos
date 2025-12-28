<?php
/**
 * Tool for creating taxonomy terms.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new taxonomy term (category, tag, or custom taxonomy).
 *
 * This tool provides comprehensive term creation with support for
 * hierarchical taxonomies, term metadata, and descriptions.
 */
class WP_MCP_AI_Tool_Create_Term implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Taxonomy Term', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new taxonomy term (category, tag, or custom taxonomy) with optional parent, description, and metadata.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Name of the term.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (e.g., "category", "post_tag", or custom taxonomy).', 'wp-mcp-ai' ),
					'default'     => 'category',
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Optional description for the term.', 'wp-mcp-ai' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'Optional slug for the term. If not provided, WordPress will generate one from the name.', 'wp-mcp-ai' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'Parent term ID for hierarchical taxonomies. Use 0 for top-level terms.', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'meta_input'  => array(
					'type'                 => 'object',
					'description'          => __( 'Array of term meta key-value pairs to set.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'name', 'taxonomy' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create terms.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$name     = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : 'category';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Term name is required.', 'wp-mcp-ai' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'wp-mcp-ai' ) );
		}

		// Get taxonomy object to check capabilities.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The taxonomy could not be loaded.', 'wp-mcp-ai' ) );
		}

		// Check if user can assign/manage terms in this taxonomy.
		$manage_cap = isset( $tax_object->cap->manage_terms ) ? $tax_object->cap->manage_terms : 'manage_categories';

		if ( ! user_can( $current_user_id, $manage_cap ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create terms in this taxonomy.', 'wp-mcp-ai' ) );
		}

		// Check if term already exists.
		$existing_term = term_exists( $name, $taxonomy );
		if ( $existing_term ) {
			return new WP_Error( 'wp_mcp_ai_term_exists', __( 'A term with this name already exists in the specified taxonomy.', 'wp-mcp-ai' ) );
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
					return new WP_Error( 'wp_mcp_ai_invalid_parent', __( 'The specified parent term does not exist.', 'wp-mcp-ai' ) );
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
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The term was created but could not be retrieved.', 'wp-mcp-ai' ) );
		}

		// Handle term meta.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$this->add_term_meta( $term_id, $arguments['meta_input'] );
		}

		// Prepare response.
		$response = array(
			'summary'     => sprintf(
				/* translators: 1: term name, 2: taxonomy name, 3: term ID */
				__( 'Term created: %1$s in %2$s (ID: %3$d)', 'wp-mcp-ai' ),
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
	 * Adds term metadata.
	 *
	 * @param int   $term_id    Term ID.
	 * @param array $meta_input Array of meta key-value pairs.
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

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates terms.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires term management capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the term.
		);
	}
}
