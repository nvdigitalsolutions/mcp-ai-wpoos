<?php
/**
 * Tool: okf_import_bundle — Import an OKF bundle from a ZIP archive.
 *
 * @package WP_MCP_AI
 * @since   1.1.62
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Import Bundle tool.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Tool_OKF_Import_Bundle implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_import_bundle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Import Bundle', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports an OKF bundle from a ZIP archive that is already present on this server (e.g. uploaded via the WordPress media library or placed by an administrator). Provide the absolute path to the .zip file and the target bundle name. The archive is checked for ZipSlip entries, symbolic links, entry/size caps, and must contain at least one concept document. Requires administrator capability because it writes files to the knowledge directory.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'zip_path' => array(
					'type'        => 'string',
					'description' => __( 'Absolute server path to the ZIP archive to import.', 'mcp-ai-wpoos' ),
				),
				'bundle'   => array(
					'type'        => 'string',
					'description' => __( 'Target bundle name (lowercase letters, numbers, hyphens, underscores; must not already exist).', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'zip_path', 'bundle' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
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
		$zip_path = sanitize_text_field( wp_unslash( $arguments['zip_path'] ) );
		$bundle   = sanitize_text_field( $arguments['bundle'] );

		if ( empty( $zip_path ) || empty( $bundle ) ) {
			return new WP_Error( 'missing_params', __( 'zip_path and bundle are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$result  = $manager->import_bundle_zip( $zip_path, $bundle );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: bundle name, 2: concept count */
				__( 'Imported OKF bundle "%1$s" with %2$d concepts.', 'mcp-ai-wpoos' ),
				$bundle,
				$result['concepts']
			),
			array(
				'bundle'     => esc_html( $result['bundle'] ),
				'concepts'   => (int) $result['concepts'],
				'conformant' => (bool) $result['conformant'],
				'issues'     => array_map( 'esc_html', (array) $result['issues'] ),
			)
		);
	}
}
