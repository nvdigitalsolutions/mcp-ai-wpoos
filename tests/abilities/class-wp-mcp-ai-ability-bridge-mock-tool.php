<?php
/**
 * Mock tool for WP_MCP_AI_Ability_Bridge tests.
 *
 * Implements both the base tool interface and the optional capability flags
 * interface so bridge tests can verify annotation mapping, context passing,
 * and error handling.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Mock tool implementation for bridge testing.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Bridge_Mock_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Tool slug.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private $slug = 'test_tool';

	/**
	 * Required capability.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private $capability = 'edit_posts';

	/**
	 * Capability flags.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $flags = array( 'read-only', 'idempotent', 'local-only' );

	/**
	 * Pre-set execute result.
	 *
	 * @since 2.0.0
	 * @var mixed
	 */
	public $last_execute_result = array(
		'success' => true,
		'data'    => 'ok',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Test Tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'A test tool for bridge testing.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return $this->capability;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return $this->flags;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return mixed|WP_Error
	 */
	public function execute( $arguments = array(), $context = array() ) {
		return $this->last_execute_result;
	}

	/**
	 * Set the tool slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug New slug.
	 * @return void
	 */
	public function set_slug( $slug ) {
		$this->slug = $slug;
	}

	/**
	 * Set the required capability.
	 *
	 * @since 2.0.0
	 *
	 * @param string $cap Capability string.
	 * @return void
	 */
	public function set_capability( $cap ) {
		$this->capability = $cap;
	}

	/**
	 * Set the capability flags.
	 *
	 * @since 2.0.0
	 *
	 * @param array $flags Capability flag strings.
	 * @return void
	 */
	public function set_flags( $flags ) {
		$this->flags = $flags;
	}

	/**
	 * Set the pre-determined execute result.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $result The result to return from execute().
	 * @return void
	 */
	public function set_result( $result ) {
		$this->last_execute_result = $result;
	}
}
