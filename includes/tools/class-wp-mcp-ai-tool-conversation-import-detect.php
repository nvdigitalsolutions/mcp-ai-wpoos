<?php
/**
 * Tool: conversation_import_detect — Inspect a conversation export file.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect an external conversation export without importing anything.
 */
class WP_MCP_AI_Tool_Conversation_Import_Detect implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether conversation import tooling is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Conversation Import Detect tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'conversation_import_detect';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Detect Conversation Import Format', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Inspects an external AI conversation export file (ChatGPT conversations.json, Google Takeout Gemini activity) and reports the detected format and estimated conversation count without importing anything.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source' => array(
					'type'        => 'string',
					'description' => __( 'Absolute file path to the export file, or a media library attachment ID.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'source' ),
			'additionalProperties' => false,
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
		return array(
			'read-only',
			'pii-data',
			'local-only',
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['source'] ) ) {
			return new WP_Error( 'wp_mcp_ai_import_missing_source', __( 'Provide an import source: a file path or a media library attachment ID.', 'mcp-ai-wpoos' ) );
		}

		$source_path = $this->resolve_source_path( sanitize_text_field( (string) $arguments['source'] ) );
		if ( is_wp_error( $source_path ) ) {
			return $source_path;
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$result  = $manager->inspect( $source_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/* translators: %s: detected platform slug. */
		$message = sprintf( __( 'Detected conversation export format: %s.', 'mcp-ai-wpoos' ), $result['platform'] );

		return $this->format_success_response(
			$message,
			array(
				'platform'        => $result['platform'],
				'file'            => $result['file'],
				'bytes'           => $result['bytes'],
				'estimated_count' => $result['estimated_count'],
				'adapters'        => $result['adapters'],
			)
		);
	}

	/**
	 * Resolve a source string into an absolute file path.
	 *
	 * @param string $source File path or attachment ID.
	 * @return string|\WP_Error
	 */
	protected function resolve_source_path( $source ) {
		if ( is_numeric( $source ) ) {
			$path = get_attached_file( absint( $source ) );

			if ( false === $path || '' === $path || ! file_exists( $path ) ) {
				return new WP_Error(
					'wp_mcp_ai_import_attachment_missing',
					__( 'The media library attachment could not be found.', 'mcp-ai-wpoos' )
				);
			}

			return wp_normalize_path( $path );
		}

		$path = wp_normalize_path( $source );

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_source_missing',
				__( 'The import source file does not exist or is not readable.', 'mcp-ai-wpoos' )
			);
		}

		return $path;
	}
}
