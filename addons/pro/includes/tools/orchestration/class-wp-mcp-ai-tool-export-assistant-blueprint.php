<?php
/**
 * Tool: `export_assistant_blueprint` (Pro).
 *
 * Exports a live assistant as a curated blueprint JSON document — the exact
 * dialect consumed by WP_MCP_AI_Blueprint_Installer and the Pro blueprint
 * admin pages. Closes the loop: any assistant configured in the admin or by
 * another agent can be frozen into a shareable, re-importable blueprint.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.1.80
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export an assistant as a curated blueprint JSON document.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Export_Assistant_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_assistant_blueprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Assistant Blueprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports a live assistant as a curated blueprint JSON document in the format consumed by the Pro Blueprint Installer and the blueprint admin pages. Use it to freeze a working assistant into a shareable, re-importable blueprint.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Freezing a configured assistant into a shareable, re-importable blueprint JSON document.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Importing or recreating an assistant (use import_assistant) or creating a new one (use create_assistant).', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'export_assistant', 'import_assistant', 'create_assistant' ),
			'notes'           => __( 'Set save_to_media=true to store the JSON as a media attachment and return its attachment ID.', 'mcp-ai-wpoos-pro' ),
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
		return array( 'read-only', 'cacheable', 'requires-capability', 'pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'assistant_id'        => array(
					'type'        => 'integer',
					'description' => __( 'The assistant post ID to export as a blueprint.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'save_to_media'       => array(
					'type'        => 'boolean',
					'description' => __( 'Save the blueprint JSON as a media attachment and return its attachment ID instead of the raw JSON. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Confirmation flag (unused by this read-only tool; accepted for gate compatibility).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'assistant_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'assistant_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		$save_media   = ! empty( $arguments['save_to_media'] );

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants(
			array( $assistant_id ),
			array( 'include_a2a' => false )
		);

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$blueprint = WP_MCP_AI_Assistant_Portability::to_blueprint_json( $bundle['assistants'][0] );
		$json      = wp_json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error(
				'wp_mcp_ai_portability_encode_failed',
				__( 'The blueprint payload could not be encoded as JSON.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		if ( $save_media ) {
			$filename      = sanitize_file_name(
				'blueprint-' . sanitize_title( $blueprint['name'] ) . '-' . gmdate( 'Ymd-His' ) . '.json'
			);
			$attachment_id = $this->save_blueprint_attachment( $filename, $json );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return $this->format_success_response(
				__( 'Assistant blueprint saved as a media attachment.', 'mcp-ai-wpoos-pro' ),
				array(
					'attachment_id' => $attachment_id,
					'filename'      => $filename,
					'blueprint'     => $blueprint,
				)
			);
		}

		return $this->format_success_response(
			__( 'Assistant blueprint generated.', 'mcp-ai-wpoos-pro' ),
			array(
				'json'      => $json,
				'blueprint' => $blueprint,
			)
		);
	}

	/**
	 * Persist a blueprint payload as a media attachment.
	 *
	 * @since 1.1.80
	 *
	 * @param string $filename Attachment filename.
	 * @param string $json     Blueprint JSON payload.
	 * @return int|WP_Error Attachment ID or WP_Error.
	 */
	protected function save_blueprint_attachment( $filename, $json ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_upload_dir',
				__( 'The WordPress uploads directory is unavailable.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$target = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-generated JSON attachment into the WordPress uploads directory.
		if ( false === file_put_contents( $target, $json ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_attachment_write_failed',
				__( 'Could not write the blueprint attachment.', 'mcp-ai-wpoos-pro' ),
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
