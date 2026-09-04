<?php
/**
 * Tool: conversation_import_delete — Delete imported conversation rows.
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
 * Delete conversations previously imported into the transcript CCT.
 */
class WP_MCP_AI_Tool_Conversation_Import_Delete implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether conversation import tooling is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return false;
		}

		// Gate on the physical CCT table, not just the JetEngine class: a
		// partially-loaded JetEngine (or a compatibility shim defining
		// Jet_Engine) passes class checks but has no transcript table, and
		// every query would fail silently.
		return WP_MCP_AI_JetEngine_CCT::is_storage_available();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Conversation Import Delete tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'conversation_import_delete';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Imported Conversations', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes conversations previously imported into the AI Chat Transcripts CCT, scoped by source platform (chatgpt or gemini). Supports dry-run previews and a safety-capped row limit.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'platform' => array(
					'type'        => 'string',
					'description' => __( 'Source platform of the imported conversations to delete.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'chatgpt', 'gemini', 'claude', 'sharegpt', 'openai_jsonl' ),
				),
				'user_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional. Restrict deletion to conversations imported by this WordPress user ID. 0 means all importers.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows to delete per run. 0 uses the safety cap (500).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 500,
					'default'     => 0,
				),
				'dry_run'  => array(
					'type'        => 'boolean',
					'description' => __( 'Count matching rows without deleting. Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'platform' ),
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
			'write',
			'state-changing',
			'data-destruction',
			'irreversible',
			'pii-data',
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

		if ( empty( $arguments['platform'] ) ) {
			return new WP_Error( 'wp_mcp_ai_import_delete_invalid_platform', __( 'Provide the platform slug of the imported conversations to delete.', 'mcp-ai-wpoos' ) );
		}

		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();
		$report  = $deleter->delete(
			sanitize_key( (string) $arguments['platform'] ),
			isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0,
			isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 0,
			! empty( $arguments['dry_run'] )
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$message = $report['dry_run']
			? sprintf(
				/* translators: 1: platform, 2: number of matching rows. */
				__( 'Dry run: %2$d imported %1$s conversation(s) would be deleted.', 'mcp-ai-wpoos' ),
				$report['platform'],
				$report['found']
			)
			: sprintf(
				/* translators: 1: deleted count, 2: platform, 3: failed count. */
				__( 'Deleted %1$d imported %2$s conversation(s) (%3$d failed).', 'mcp-ai-wpoos' ),
				$report['deleted'],
				$report['platform'],
				$report['failed']
			);

		return $this->format_success_response( $message, $report );
	}
}
