<?php
/**
 * Tool: paper_store_update — Update an existing Paper Store record.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paper Store — Update tool.
 */
class WP_MCP_AI_Tool_Paper_Store_Update implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'paper_store_update';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Paper Store — Update Record', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing record in a Paper Store collection. Only the fields you provide will be changed; omitted fields are left unchanged. The record ID cannot be changed.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection'  => array(
					'type'        => 'string',
					'description' => __( 'Collection name.', 'mcp-ai-wpoos' ),
				),
				'record_id'   => array(
					'type'        => 'string',
					'description' => __( 'The ID of the record to update.', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Optional. New title.', 'mcp-ai-wpoos' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Optional. New description.', 'mcp-ai-wpoos' ),
				),
				'tags'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional. New tags (replaces all existing tags).', 'mcp-ai-wpoos' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Optional. New status.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'published', 'draft', 'archived' ),
				),
				'body'        => array(
					'type'        => 'object',
					'description' => __( 'Optional. New body content (replaces existing body).', 'mcp-ai-wpoos' ),
				),
				'meta'        => array(
					'type'        => 'object',
					'description' => __( 'Optional. New metadata (merges with existing meta).', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'collection', 'record_id' ),
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$collection  = sanitize_key( $arguments['collection'] );
		$record_id   = sanitize_key( $arguments['record_id'] );

		if ( empty( $collection ) || empty( $record_id ) ) {
			return new WP_Error( 'missing_params', __( 'Collection and record_id are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$update_data = array();

		if ( isset( $arguments['title'] ) ) {
			$update_data['title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$update_data['description'] = sanitize_text_field( $arguments['description'] );
		}

		if ( isset( $arguments['status'] ) ) {
			$update_data['status'] = sanitize_key( $arguments['status'] );
		}

		if ( isset( $arguments['body'] ) ) {
			$update_data['body'] = $arguments['body'];
		}

		if ( isset( $arguments['meta'] ) && is_array( $arguments['meta'] ) ) {
			$update_data['meta'] = $arguments['meta'];
		}

		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tags = array();
			foreach ( $arguments['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				if ( ! empty( $tag ) ) {
					$tags[] = $tag;
				}
			}
			$update_data['tags'] = $tags;
		}

		if ( empty( $update_data ) ) {
			return new WP_Error( 'no_changes', __( 'No fields provided to update.', 'mcp-ai-wpoos' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$updated = $repo->update( $record_id, $update_data );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: %s: record ID */
				__( 'Record "%s" updated.', 'mcp-ai-wpoos' ),
				$record_id
			),
			array(
				'collection' => esc_html( $collection ),
				'record'     => $updated,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'paper_store',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'low',
		);
	}
}
