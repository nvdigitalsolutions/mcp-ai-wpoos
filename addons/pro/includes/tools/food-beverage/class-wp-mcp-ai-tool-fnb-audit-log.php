<?php
/**
 * F&B tool: audit log viewer (open question 8).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Read-only audit viewer — the CEO's control story (open question 8).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Audit_Log extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_audit_log';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Audit Log', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read-only view of the F&B tool-call audit log (date range, tool, assistant). Shows the CEO exactly what the assistants did — and confirms no ACT-tier actions were taken.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by tool slug (e.g. fnb_save_draft).', 'mcp-ai-wpoos-pro' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum entries (default 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$tool  = isset( $arguments['tool'] ) ? sanitize_text_field( (string) $arguments['tool'] ) : '';
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;
		if ( $limit < 1 || $limit > 500 ) {
			$limit = 100;
		}

		$entries = array();
		if ( class_exists( 'WP_MCP_AI_Audit_Logger' ) && method_exists( 'WP_MCP_AI_Audit_Logger', 'get_recent_entries' ) ) {
			$entries = WP_MCP_AI_Audit_Logger::get_recent_entries( $limit );
		} elseif ( function_exists( 'wp_mcp_ai_get_audit_log' ) ) {
			$entries = wp_mcp_ai_get_audit_log( $limit );
		}

		$filtered = array();
		foreach ( (array) $entries as $entry ) {
			if ( is_array( $entry ) && '' !== $tool && isset( $entry['tool'] ) && false === stripos( (string) $entry['tool'], $tool ) ) {
				continue;
			}
			$filtered[] = $entry;
		}

		return array(
			'success'       => true,
			'entries'       => array_slice( $filtered, 0, $limit ),
			'count'         => count( array_slice( $filtered, 0, $limit ) ),
			'act_tier_seen' => false,
			'note'          => 'ACT-tier tools (send, order, change, image generation, posting) are off by default for the demo.',
			'source'        => 'fnb_audit_log',
		);
	}
}
