<?php
/**
 * Tool: paper_store_read — Read a single Paper Store record.
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
 * Paper Store — Read tool.
 */
class WP_MCP_AI_Tool_Paper_Store_Read implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'paper_store_read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Paper Store — Read Record', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads a single record from the NV oOS Paper Store by collection name and record ID. Returns the full record including metadata, tags, and body content.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection' => array(
					'type'        => 'string',
					'description' => __( 'Collection name (e.g. "knowledge", "prompts").', 'mcp-ai-wpoos' ),
				),
				'record_id'  => array(
					'type'        => 'string',
					'description' => __( 'Record ID (slug).', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'collection', 'record_id' ),
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$collection = sanitize_key( $arguments['collection'] );
		$record_id  = sanitize_key( $arguments['record_id'] );

		if ( empty( $collection ) || empty( $record_id ) ) {
			return new WP_Error( 'missing_params', __( 'Collection and record_id are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$record  = $repo->find( $record_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( null === $record ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: 1: record ID, 2: collection name */
					__( 'Record "%1$s" not found in collection "%2$s".', 'mcp-ai-wpoos' ),
					$record_id,
					$collection
				)
			);
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: %s: record ID */
				__( 'Record "%s" retrieved.', 'mcp-ai-wpoos' ),
				$record_id
			),
			array(
				'collection' => esc_html( $collection ),
				'record'     => $record,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'cacheable', 'requires-capability' );
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
			'risk_level'            => 'info',
		);
	}
}
