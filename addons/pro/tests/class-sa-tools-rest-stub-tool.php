<?php
/**
 * Deterministic stub tool used to isolate the SA tools catalogue tests
 * from the live registry contents.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Stub tool for the /sa-tools REST tests.
 */
class SA_Tools_REST_Stub_Tool implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Tool slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Constructor.
	 *
	 * @param string $slug Tool slug.
	 */
	public function __construct( $slug ) {
		$this->slug = $slug;
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Probe Name ' . ucwords( str_replace( '_', ' ', $this->slug ) );
	}

	/**
	 * Get the description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Deterministic stub for the SA tools catalogue tests.';
	}

	/**
	 * Get the parameters schema.
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
	 * Execute — unused in these tests.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}
