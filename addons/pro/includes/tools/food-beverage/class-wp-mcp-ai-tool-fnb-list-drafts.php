<?php
/**
 * F&B tool: list drafts.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * List existing drafts.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_List_Drafts extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_list_drafts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B List Drafts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List the drafts saved so far (local mode), with name and size.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
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

		return WP_MCP_AI_Fnb_Draft_Writer::list_drafts();
	}
}
