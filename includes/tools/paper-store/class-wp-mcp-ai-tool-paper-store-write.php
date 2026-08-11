<?php
/**
 * Tool: paper_store_write — Create a new Paper Store record.
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
 * Paper Store — Write tool.
 *
 * Creates a new record in a Paper Store collection. Requires edit_posts capability.
 */
class WP_MCP_AI_Tool_Paper_Store_Write implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Paper_Store_Remote;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'paper_store_write';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Paper Store — Create Record', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new record in a Paper Store collection. The record will be stored as a JSON file in the collection directory. Requires an "id" slug, a "title", and optional tags, status, description, and body content.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection'    => array(
					'type'        => 'string',
					'description' => __( 'Collection name (e.g. "knowledge", "prompts").', 'mcp-ai-wpoos' ),
				),
				'id'            => array(
					'type'        => 'string',
					'description' => __( 'Unique record ID (slug). Lowercase, no spaces. e.g. "dior-sauvage".', 'mcp-ai-wpoos' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Human-readable title for the record.', 'mcp-ai-wpoos' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional. Short description or summary of the record.', 'mcp-ai-wpoos' ),
				),
				'tags'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional. Array of tag strings for categorisation.', 'mcp-ai-wpoos' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional. Record status. Defaults to "published".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'published', 'draft', 'archived' ),
				),
				'body'          => array(
					'type'        => 'object',
					'description' => __( 'Optional. Free-form body content for the record. Any JSON-serialisable value.', 'mcp-ai-wpoos' ),
				),
				'meta'          => array(
					'type'        => 'object',
					'description' => __( 'Optional. Arbitrary metadata key-value pairs.', 'mcp-ai-wpoos' ),
				),
				'connection_id' => $this->get_connection_id_schema(),
			),
			'required'   => array( 'collection', 'id', 'title' ),
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
		$collection    = sanitize_key( $arguments['collection'] );
		$id            = sanitize_key( $arguments['id'] );
		$title         = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description   = isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '';
		$status        = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'published';
		$body          = isset( $arguments['body'] ) ? $arguments['body'] : null;
		$meta          = isset( $arguments['meta'] ) && is_array( $arguments['meta'] ) ? $arguments['meta'] : null;
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		// Sanitize tags.
		$tags = array();
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			foreach ( $arguments['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				if ( ! empty( $tag ) ) {
					$tags[] = $tag;
				}
			}
		}

		// Remote dispatch.
		if ( ! empty( $connection_id ) ) {
			$remote_body = array(
				'id'          => $id,
				'title'       => $title,
				'description' => $description,
				'tags'        => $tags,
				'status'      => $status,
			);
			if ( null !== $body ) {
				$remote_body['body'] = $body;
			}
			if ( null !== $meta ) {
				$remote_body['meta'] = $meta;
			}
			return $this->execute_remote(
				$connection_id,
				'mcp-ai/v1/paper-store/' . $collection,
				'POST',
				$remote_body
			);
		}

		if ( empty( $collection ) || empty( $id ) || empty( $title ) ) {
			return new WP_Error( 'missing_params', __( 'Collection, id, and title are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		// Check for duplicates.
		if ( $repo->exists( $id ) ) {
			return new WP_Error(
				'duplicate_id',
				sprintf(
					/* translators: %s: record ID */
					__( 'A record with ID "%s" already exists in this collection. Use paper_store_update to modify it.', 'mcp-ai-wpoos' ),
					$id
				)
			);
		}

		$record = array(
			'id'          => $id,
			'type'        => $collection,
			'title'       => $title,
			'description' => $description,
			'tags'        => $tags,
			'status'      => $status,
		);

		if ( null !== $body ) {
			$record['body'] = $body;
		}

		if ( null !== $meta ) {
			$record['meta'] = $meta;
		}

		$saved = $repo->save( $record );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: 1: record ID, 2: collection name */
				__( 'Record "%1$s" created in collection "%2$s".', 'mcp-ai-wpoos' ),
				$id,
				$collection
			),
			array(
				'collection' => esc_html( $collection ),
				'record'     => $saved,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability', 'idempotent' );
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
