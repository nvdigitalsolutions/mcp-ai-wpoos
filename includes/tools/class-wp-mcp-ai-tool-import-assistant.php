<?php
/**
 * Tool: `import_assistant`.
 *
 * Imports AI assistants from a portable `nvoos-assistant` JSON bundle, a
 * legacy CLI export file, or a blueprint JSON payload. Supports
 * skip/overwrite/duplicate modes, a dry-run preview, and forced post status.
 * Credential hashes inside any payload are ignored.
 *
 * Because this tool creates posts and can overwrite existing assistants it
 * is flagged write/state-changing, which routes it through the destructive
 * operations gate (`confirm_destructive`) when that setting is enabled.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import assistant configurations from a portable JSON payload.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Import_Assistant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Assistant', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports one or more AI assistants from a portable JSON bundle (nvoos-assistant format), a legacy CLI export, or a blueprint JSON payload. Supports skip/overwrite/duplicate handling of existing assistants, a dry-run preview, and a forced post status. Credential hashes in the payload are ignored.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Restoring assistants from a portable nvoos-assistant bundle, legacy CLI export, or blueprint JSON.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Exporting an assistant for sharing; use export_assistant.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'export_assistant', 'create_assistant', 'duplicate_assistant' ),
			'notes'           => __( 'mode is skip, overwrite, or duplicate; dry_run previews the import without writing.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'json'                => array(
					'type'        => 'string',
					'description' => __( 'The import payload as a JSON string (bundle, legacy CLI export, or blueprint). Required unless attachment_id is provided.', 'mcp-ai-wpoos' ),
					'maxLength'   => 2097152, // 2 MB cap.
				),
				'attachment_id'       => array(
					'type'        => 'integer',
					'description' => __( 'WordPress media attachment ID of a JSON export file. Alternative to json.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'mode'                => array(
					'type'        => 'string',
					'description' => __( 'Behaviour when an imported assistant already exists (matched by slug or title). Default skip.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'skip', 'overwrite', 'duplicate' ),
					'default'     => 'skip',
				),
				'dry_run'             => array(
					'type'        => 'boolean',
					'description' => __( 'Validate and report what would happen without writing anything. Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'status_override'     => array(
					'type'        => 'string',
					'description' => __( 'Force imported assistants to this post status.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'draft', 'publish', 'private' ),
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to confirm the import when the destructive operations confirmation gate is enabled.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'required_capability' => $this->get_required_capability(),
			'parameters'          => $this->get_parameters_schema(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
		}

		$json          = isset( $arguments['json'] ) ? (string) $arguments['json'] : '';
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$mode          = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'skip';
		$mode          = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';
		$dry_run       = ! empty( $arguments['dry_run'] );
		$status        = isset( $arguments['status_override'] ) ? sanitize_key( $arguments['status_override'] ) : '';
		$status        = in_array( $status, array( 'draft', 'publish', 'private' ), true ) ? $status : '';

		if ( '' === $json && $attachment_id > 0 ) {
			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! is_readable( $file ) ) {
				return new WP_Error(
					'wp_mcp_ai_portability_attachment_unreadable',
					__( 'The attachment file is missing or unreadable.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local media attachment, not a remote URL.
			$json = (string) file_get_contents( $file );
		}

		if ( '' === trim( $json ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_missing_payload',
				__( 'Provide a JSON payload or an attachment_id.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$bundle = WP_MCP_AI_Assistant_Portability::parse_import( $json );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$report = WP_MCP_AI_Assistant_Portability::import_bundle(
			$bundle,
			array(
				'mode'            => $mode,
				'dry_run'         => $dry_run,
				'status_override' => $status,
			)
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$message = $dry_run
			? __( 'Import dry run completed. Nothing was written.', 'mcp-ai-wpoos' )
			: __( 'Assistant import completed.', 'mcp-ai-wpoos' );

		return $this->format_success_response( $message, $report );
	}
}
