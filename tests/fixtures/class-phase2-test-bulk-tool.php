<?php
/**
 * Test fixture: bulk-interface tool with configurable estimate.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-bulk-operation.php';

/**
 * Bulk-interface tool fixture used by Test_Tool_Registry_Bulk_Auto_Dispatch.
 */
class Phase2_Test_Bulk_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Bulk_Operation_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Estimated row count.
	 *
	 * @var int
	 */
	private $estimate;

	/**
	 * Whether execute() ran (so tests can assert inline-vs-async).
	 *
	 * @var bool
	 */
	public $was_executed_inline = false;

	/**
	 * Constructor.
	 *
	 * @param int $estimate Estimated row count.
	 */
	public function __construct( $estimate ) {
		$this->estimate = (int) $estimate;
	}

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'phase2_test_bulk_tool';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Phase 2 Bulk Test Tool';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Test fixture for auto-async dispatch.';
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
	 * Execute the tool inline.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$this->was_executed_inline = true;
		return array( 'inline' => true );
	}

	/**
	 * Bulk batch size.
	 *
	 * @return int
	 */
	public function get_batch_size() {
		return 100;
	}

	/**
	 * Whether resumable.
	 *
	 * @return bool
	 */
	public function is_resumable() {
		return true;
	}

	/**
	 * Checkpoint key for the supplied arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	public function get_checkpoint_key( $arguments ) {
		return 'phase2-' . md5( wp_json_encode( $arguments ) );
	}

	/**
	 * Estimated total rows for the supplied arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return int
	 */
	public function estimate_total( $arguments ) {
		unset( $arguments );
		return $this->estimate;
	}
}
