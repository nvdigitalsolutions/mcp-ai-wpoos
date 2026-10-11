<?php
/**
 * F&B tool: save draft (Drafts folder only).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Save an output as a draft in the Drafts folder (G-12). Never sends,
 * orders or changes records.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Save_Draft extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_save_draft';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Save Draft', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Save an assistant output (report, brief, purchase request) as a draft in the Drafts folder. Outputs are always Draft status for approval — this tool never sends messages, places orders or changes records (G-12).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'Draft title, conventionally "[R-xx] <title> — <period> — Draft — for <approver>".', 'mcp-ai-wpoos-pro' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => __( 'Markdown content of the draft.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'title', 'content' ),
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

		$title   = isset( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : '';
		$content = isset( $arguments['content'] ) ? (string) $arguments['content'] : '';

		return WP_MCP_AI_Fnb_Draft_Writer::save_draft( $title, $content );
	}
}
