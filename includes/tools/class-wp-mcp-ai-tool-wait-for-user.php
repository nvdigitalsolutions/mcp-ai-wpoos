<?php
/**
 * Wait for User — Voice No-Op Tool for NV oOS.
 *
 * Provides a no-operation tool for realtime voice sessions that the model
 * can call when it hears silence, background noise, hold music, TV audio,
 * or side conversations that do not require a spoken response. This prevents
 * the model from generating unnecessary "I'm here" / "I didn't catch that"
 * style filler responses to non-speech audio.
 *
 * Per OpenAI's Realtime 2.0 prompting guide, this tool should be paired with
 * prompt instructions directing the model to call it for non-addressed audio.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wait for User tool class.
 */
class WP_MCP_AI_Tool_Wait_For_User {

	/**
	 * Get the unique tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'wait_for_user';
	}

	/**
	 * Get the tool definition for AI model consumption.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'wait_for_user',
			'description'         => __(
				'Call this when the latest audio does not need a spoken response, such as silence, background noise, hold music, TV audio, side conversation, or speech not addressed to the assistant. This tool helps end the turn without a spoken reply.',
				'mcp-ai-wpoos'
			),
			'required_capability' => 'read',
			'parameters'          => array(
				'type'       => 'object',
				'properties' => array(),
				'required'   => array(),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * Returns a success envelope indicating the model should wait silently.
	 *
	 * @param array $arguments Tool arguments (none expected).
	 * @param array $context   Execution context.
	 * @return array Success envelope.
	 */
	public function execute( $arguments = array(), $context = array() ) {
		unset( $arguments, $context );
		return array(
			'success' => true,
			'action'  => 'waiting',
			'message' => __( 'No response needed. Waiting for user input.', 'mcp-ai-wpoos' ),
		);
	}
}
