<?php
/**
 * Tool: `duplicate_assistant`.
 *
 * Clones an existing AI assistant into a new post (draft by default) with a
 * full copy of its plugin meta. Credential hashes are never copied — the
 * duplicate gets fresh, unissued credentials.
 *
 * Implemented as an export/import round-trip through the canonical
 * portability engine, so the duplicate is guaranteed to carry the same
 * field set as any exported bundle.
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
 * Duplicate an existing assistant.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Duplicate_Assistant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'duplicate_assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Duplicate Assistant', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Clones an existing AI assistant into a new draft with a full copy of its configuration (prompts, tools, model settings, skills, datasets). Credential tokens are never copied — the duplicate starts with fresh credentials.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Cloning an existing assistant into a new draft with its prompts, tools, model settings, skills, and datasets.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'For building a new assistant from scratch use create_assistant; for moving between sites use export_assistant and import_assistant.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'create_assistant', 'export_assistant', 'import_assistant' ),
			'notes'           => __( 'Credential tokens are never copied; the duplicate starts with fresh credentials.', 'mcp-ai-wpoos' ),
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
		return array( 'write', 'state-changing', 'requires-capability' );
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
					'description' => __( 'The assistant post ID to duplicate.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'title'               => array(
					'type'        => 'string',
					'description' => __( 'Title for the duplicate. Defaults to "<original> (Copy)".', 'mcp-ai-wpoos' ),
					'maxLength'   => 200,
				),
				'status'              => array(
					'type'        => 'string',
					'description' => __( 'Status for the duplicate. Default draft.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'draft', 'publish', 'private' ),
					'default'     => 'draft',
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to confirm when the destructive operations gate is enabled.', 'mcp-ai-wpoos' ),
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

		$source_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		$title     = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$status    = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'draft';
		$status    = in_array( $status, array( 'draft', 'publish', 'private' ), true ) ? $status : 'draft';

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants(
			array( $source_id ),
			array( 'include_a2a' => false )
		);

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$assistant = $bundle['assistants'][0];

		if ( '' === $title ) {
			/* translators: %s: original assistant title */
			$title = sprintf( __( '%s (Copy)', 'mcp-ai-wpoos' ), $assistant['title'] );
		}

		$assistant['title']  = $title;
		$assistant['slug']   = '';
		$assistant['status'] = $status;

		$report = WP_MCP_AI_Assistant_Portability::import_bundle(
			array(
				'format'         => WP_MCP_AI_Assistant_Portability::FORMAT,
				'format_version' => WP_MCP_AI_Assistant_Portability::FORMAT_VERSION,
				'assistants'     => array( $assistant ),
			),
			array( 'mode' => 'duplicate' )
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		if ( ! empty( $report['items'][0]['assistant_id'] ) ) {
			$new_id = (int) $report['items'][0]['assistant_id'];

			return $this->format_success_response(
				__( 'Assistant duplicated.', 'mcp-ai-wpoos' ),
				array(
					'assistant_id' => $new_id,
					'title'        => $title,
					'edit_url'     => get_edit_post_link( $new_id, 'raw' ),
				)
			);
		}

		return new WP_Error(
			'wp_mcp_ai_portability_duplicate_failed',
			__( 'The duplicate could not be created.', 'mcp-ai-wpoos' ),
			array( 'status' => 500 )
		);
	}
}
