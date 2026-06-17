<?php
/**
 * Test fixture: plain (non-bulk) tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Plain tool fixture used by Test_Tool_Registry_Bulk_Auto_Dispatch.
 */
class Phase2_Test_Inline_Tool implements WP_MCP_AI_Tool_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Whether execute() ran.
	 *
	 * @var bool
	 */
	public $was_executed_inline = false;

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'phase2_test_inline_tool';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Phase 2 Inline Test Tool';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Plain tool fixture.';
	}

	/**
	 * Parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );
		$this->was_executed_inline = true;
		return array( 'inline' => true );
	}
}
