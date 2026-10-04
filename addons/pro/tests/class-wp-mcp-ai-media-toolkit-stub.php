<?php
/**
 * Configurable media toolkit tool stub for hardening regression tests.
 *
 * Registers itself under the slug in WP_MCP_AI_Media_Toolkit_Stub_Tool::$slug
 * and returns whatever WP_MCP_AI_Media_Toolkit_Stub_Tool::$result holds from
 * execute() — used to simulate nested apply_media_template /
 * process_collection tool outcomes without the real dependencies.
 *
 * @package WP_MCP_AI
 */

/**
 * Test stub for nested media toolkit tool calls.
 */
class WP_MCP_AI_Media_Toolkit_Stub_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Slug the stub registers under.
	 *
	 * @var string
	 */
	public static $slug = '';

	/**
	 * Result the stub returns from execute().
	 *
	 * @var array|WP_Error
	 */
	public static $result = array();

	/**
	 * Get slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return self::$slug;
	}

	/**
	 * Get name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Media Toolkit Stub';
	}

	/**
	 * Get description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Test stub used to simulate nested media toolkit tool outcomes.';
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * Get usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => 'Test-only stub tool.',
			'when_not_to_use' => 'Production.',
			'related_tools'   => array(),
			'notes'           => 'Test-only stub tool.',
		);
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error The configured stub result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return self::$result;
	}
}
