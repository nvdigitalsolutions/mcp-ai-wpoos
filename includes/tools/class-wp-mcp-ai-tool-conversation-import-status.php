<?php
/**
 * Tool: conversation_import_status — Check a running conversation import.
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
 * Report progress of a running or resumed conversation import.
 */
class WP_MCP_AI_Tool_Conversation_Import_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Conversation Import Status tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'conversation_import_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Conversation Import Status', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the checkpoint status of a running conversation import by its run token.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token' => array(
					'type'        => 'string',
					'description' => __( 'Run token returned by conversation_import_run.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'token' ),
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

		if ( empty( $arguments['token'] ) ) {
			return new WP_Error( 'wp_mcp_ai_import_missing_token', __( 'Provide the import run token.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$state   = $manager->get_status( sanitize_text_field( (string) $arguments['token'] ) );

		if ( isset( $state['status'] ) && 'not_found' === $state['status'] ) {
			/* translators: %s: run token. */
			$message = sprintf( __( 'No active import checkpoint found for token %s.', 'mcp-ai-wpoos' ), $state['token'] );

			return $this->format_success_response( $message, $state );
		}

		/* translators: %s: run token. */
		$message = sprintf( __( 'Import checkpoint for token %s.', 'mcp-ai-wpoos' ), $state['token'] );

		return $this->format_success_response( $message, $state );
	}
}
