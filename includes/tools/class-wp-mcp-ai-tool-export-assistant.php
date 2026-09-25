<?php
/**
 * Tool: `export_assistant`.
 *
 * Exports one, several, or all AI assistants as a portable `nvoos-assistant`
 * JSON bundle (format_version 1). The bundle contains titles, prompts, tool
 * assignments, model settings, skills, datasets, and every plugin-owned meta
 * key. Credential hashes are never exported. Optionally embeds A2A agent
 * cards and/or saves the payload as a media attachment so other assistants
 * or workflows can consume it.
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
 * Export assistant configurations as a portable bundle.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Export_Assistant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Assistant', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports one, several, or all AI assistants as a portable JSON bundle (titles, system prompts, tool assignments, model settings, skills, datasets, and all plugin meta). Credential tokens are never included. Optionally embeds A2A agent cards and saves the bundle as a media attachment.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Backing up or migrating assistant configurations as a portable JSON bundle.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Restoring a bundle or creating assistants from scratch; use import_assistant or create_assistant.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'import_assistant', 'create_assistant' ),
			'notes'           => __( 'Credential tokens are never exported. Formats: json, a2a, blueprint (blueprint requires a single assistant).', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'assistant_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Assistant post IDs to export. Omit (or pass an empty array) to export every assistant.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'maxItems'    => 500,
					'default'     => array(),
				),
				'include_a2a'         => array(
					'type'        => 'boolean',
					'description' => __( 'Embed an A2A agent card for each assistant in the bundle. Default true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'format'              => array(
					'type'        => 'string',
					'description' => __( 'Output format. "json" returns the canonical bundle; "a2a" returns a single A2A agent card (requires exactly one assistant). "blueprint" returns blueprint JSON for the Pro blueprint installer (single assistant only).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'json', 'a2a', 'blueprint' ),
					'default'     => 'json',
				),
				'save_to_media'       => array(
					'type'        => 'boolean',
					'description' => __( 'Save the JSON payload as a media attachment and return its attachment ID instead of the raw JSON. Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Confirmation flag (unused by this read-only tool; accepted for gate compatibility).', 'mcp-ai-wpoos' ),
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

		$ids         = isset( $arguments['assistant_ids'] ) ? (array) $arguments['assistant_ids'] : array();
		$ids         = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$include_a2a = isset( $arguments['include_a2a'] ) ? (bool) $arguments['include_a2a'] : true;
		$format      = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'json';
		$format      = in_array( $format, array( 'json', 'a2a', 'blueprint' ), true ) ? $format : 'json';
		$save_media  = ! empty( $arguments['save_to_media'] );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
		}

		// ── Resolve the payload for the requested format ──
		if ( 'a2a' === $format || 'blueprint' === $format ) {
			if ( 1 !== count( $ids ) ) {
				return new WP_Error(
					'wp_mcp_ai_portability_single_required',
					__( 'The a2a and blueprint formats export exactly one assistant.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( 'a2a' === $format ) {
				$payload = WP_MCP_AI_Assistant_Portability::export_a2a_card( $ids[0] );
			} else {
				$bundle  = WP_MCP_AI_Assistant_Portability::export_assistants( $ids, array( 'include_a2a' => false ) );
				$payload = is_wp_error( $bundle ) ? $bundle : WP_MCP_AI_Assistant_Portability::to_blueprint_json( $bundle['assistants'][0] );
			}

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		} else {
			$payload = WP_MCP_AI_Assistant_Portability::export_assistants(
				empty( $ids ) ? 'all' : $ids,
				array( 'include_a2a' => $include_a2a )
			);

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error(
				'wp_mcp_ai_portability_encode_failed',
				__( 'The export payload could not be encoded as JSON.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// ── Optionally persist as a media attachment ──
		if ( $save_media ) {
			$first_title   = isset( $payload['assistants'] )
				? sanitize_text_field( $payload['assistants'][0]['title'] )
				: sanitize_text_field( $payload['name'] ?? 'assistant' );
			$filename      = sanitize_file_name( 'nvoos-assistant-' . sanitize_title( $first_title ) . '-' . gmdate( 'Ymd-His' ) . '.json' );
			$attachment_id = self::save_json_attachment( $filename, $json );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return $this->format_success_response(
				__( 'Assistant export saved as a media attachment.', 'mcp-ai-wpoos' ),
				array(
					'attachment_id' => $attachment_id,
					'filename'      => $filename,
					'format'        => $format,
					'count'         => isset( $payload['assistants'] ) ? count( $payload['assistants'] ) : 1,
				)
			);
		}

		// ── Return the raw JSON payload ──
		return $this->format_success_response(
			__( 'Assistant export generated.', 'mcp-ai-wpoos' ),
			array(
				'json'   => $json,
				'format' => $format,
				'count'  => isset( $payload['assistants'] ) ? count( $payload['assistants'] ) : 1,
			)
		);
	}

	/**
	 * Persist a JSON payload as a media attachment.
	 *
	 * @since 1.1.80
	 *
	 * @param string $filename Attachment filename.
	 * @param string $json     JSON payload.
	 * @return int|WP_Error Attachment ID or WP_Error.
	 */
	protected static function save_json_attachment( $filename, $json ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_upload_dir',
				__( 'The WordPress uploads directory is unavailable.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$target = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-generated JSON attachment into the WordPress uploads directory.
		if ( false === file_put_contents( $target, $json ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_attachment_write_failed',
				__( 'Could not write the export attachment.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/json',
				'post_title'     => $filename,
				'post_status'    => 'inherit',
			),
			$target
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );

		return $attachment_id;
	}
}
