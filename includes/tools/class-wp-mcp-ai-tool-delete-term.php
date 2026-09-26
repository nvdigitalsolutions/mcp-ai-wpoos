<?php
/**
 * Tool: delete_term — Permanently deletes a taxonomy term.
 *
 * Port of mcp-wordpress wp_delete_category tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permanently deletes a taxonomy term (category, tag, or custom taxonomy) by ID.
 */
class WP_MCP_AI_Tool_Delete_Term implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Taxonomy Term', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently deletes a taxonomy term (category, tag, or custom taxonomy). Posts assigned to the term keep the default term or lose the assignment, per WordPress behavior; deletion is permanent.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Permanently removing a known term ID from a taxonomy.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Removing a term that should stay recoverable; term deletion cannot be undone.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_terms', 'create_term', 'update_term', 'get_term' ),
			'notes'           => __( 'Deletion is permanent and cannot be undone. Posts assigned to the term keep the taxonomy default or lose the assignment, per WordPress behavior.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the term to delete.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy the term belongs to (e.g., "category", "post_tag", or a custom taxonomy).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'term_id', 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'term_id' ),
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
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete taxonomy terms.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$term_id  = isset( $arguments['term_id'] ) ? absint( $arguments['term_id'] ) : 0;
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : '';

		if ( ! $term_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'term_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === $taxonomy ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'taxonomy is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'mcp-ai-wpoos' ) );
		}

		// Get taxonomy object to check capabilities.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The taxonomy could not be loaded.', 'mcp-ai-wpoos' ) );
		}

		// Check if user can delete terms in this taxonomy.
		$delete_cap = isset( $tax_object->cap->delete_terms ) ? $tax_object->cap->delete_terms : 'manage_categories';

		if ( ! user_can( $acting_user_id, $delete_cap ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Dynamic per-taxonomy capability, same pattern as create_term.
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete terms in this taxonomy.', 'mcp-ai-wpoos' ) );
		}

		// Verify the term exists before attempting deletion.
		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'wp_mcp_ai_term_not_found', __( 'The requested term could not be found in the specified taxonomy.', 'mcp-ai-wpoos' ) );
		}

		$term_name = $term->name;

		$result = wp_delete_term( $term_id, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_term_delete_failed',
				__( 'The term could not be deleted.', 'mcp-ai-wpoos' ),
				array( 'reason' => $result->get_error_message() )
			);
		}

		if ( true !== $result ) {
			return new WP_Error( 'wp_mcp_ai_term_not_found', __( 'The requested term could not be found in the specified taxonomy.', 'mcp-ai-wpoos' ) );
		}

		$summary_text = sprintf(
			/* translators: 1: term name, 2: term ID, 3: taxonomy */
			__( 'Term %1$s (ID: %2$d) was permanently deleted from %3$s.', 'mcp-ai-wpoos' ),
			$term_name,
			$term_id,
			$taxonomy
		);

		return array(
			'message'  => $summary_text,
			'term_id'  => absint( $term_id ),
			'taxonomy' => esc_html( $taxonomy ),
			'deleted'  => true,
		);
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
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'content_strategist' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies state (deletes data).
			'local-only',           // No external API calls.
			'requires-capability',  // Requires the taxonomy's delete_terms capability.
			'state-changing',       // Modifies database state.
			'data-destruction',     // Permanently removes the term beyond recovery.
		);
	}
}
