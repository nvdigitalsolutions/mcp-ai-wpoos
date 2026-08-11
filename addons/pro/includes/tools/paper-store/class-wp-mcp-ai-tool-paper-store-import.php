<?php
/**
 * Tool: paper_store_import — Bulk import records from JSON.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Paper Store — Import tool.
 */
class WP_MCP_AI_Tool_Paper_Store_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Paper_Store_Remote;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'paper_store_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Paper Store — Import Records', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Bulk import records into a Paper Store collection from a JSON array. Each item must have at minimum an "id" and "title" field. Existing records with matching IDs will be overwritten.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection'    => array(
					'type'        => 'string',
					'description' => __( 'Target collection name.', 'mcp-ai-wpoos-pro' ),
				),
				'records'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'Array of record objects to import. Each must have "id" and "title".', 'mcp-ai-wpoos-pro' ),
				),
				'overwrite'     => array(
					'type'        => 'boolean',
					'description' => __( 'Overwrite existing records with matching IDs. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'connection_id' => $this->get_connection_id_schema(),
			),
			'required'   => array( 'collection', 'records' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'manage_options';
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
		$overwrite     = isset( $arguments['overwrite'] ) ? (bool) $arguments['overwrite'] : true;
		$records       = isset( $arguments['records'] ) && is_array( $arguments['records'] ) ? $arguments['records'] : array();
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $collection ) || empty( $records ) ) {
			return new WP_Error( 'missing_params', __( 'Collection and records are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Remote dispatch.
		if ( ! empty( $connection_id ) ) {
			return $this->execute_remote(
				$connection_id,
				'mcp-ai/v1/paper-store/' . $collection . '/import',
				'POST',
				array(
					'records'   => $records,
					'overwrite' => $overwrite,
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				++$skipped;
				continue;
			}

			if ( ! $overwrite && $repo->exists( sanitize_key( $record['id'] ) ) ) {
				++$skipped;
				continue;
			}

			$result = $repo->save( $record );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'id'    => $record['id'] ?? 'unknown',
					'error' => $result->get_error_message(),
				);
			} else {
				++$imported;
			}
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: 1: imported count, 2: skipped count, 3: collection */
				__( 'Imported %1$d record(s) into "%3$s" (%2$d skipped).', 'mcp-ai-wpoos-pro' ),
				$imported,
				$skipped,
				$collection
			),
			array(
				'collection' => esc_html( $collection ),
				'imported'   => $imported,
				'skipped'    => $skipped,
				'errors'     => $errors,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability', 'pro' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'paper_store',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'medium',
		);
	}
}
