<?php
/**
 * Tool Queue Trait for NV oOS.
 *
 * Provides default queue configuration behavior for tools.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait providing default queue configuration behavior.
 *
 * Tools can use this trait to get sensible defaults while still
 * implementing the WP_MCP_AI_Tool_Queue_Interface.
 */
trait WP_MCP_AI_Tool_Queue_Trait {

	/**
	 * Get default queue configuration.
	 *
	 * Override this method in your tool to customize queue behavior.
	 *
	 * @return array Queue configuration.
	 */
	public function get_queue_config() {
		return array(
			'queue'          => 'tool.execution',
			'priority'       => 'normal',
			'timeout'        => 300,
			'max_retries'    => 3,
			'retry_delay'    => 1000,
			'requires_queue' => false,
			'prefer_queue'   => false,
			'idempotent'     => false,
			'parallelizable' => false,
		);
	}

	/**
	 * Helper to merge custom config with defaults.
	 *
	 * @param array $custom Custom configuration values.
	 * @return array Merged configuration.
	 */
	protected function merge_queue_config( array $custom ) {
		$defaults = array(
			'queue'          => 'tool.execution',
			'priority'       => 'normal',
			'timeout'        => 300,
			'max_retries'    => 3,
			'retry_delay'    => 1000,
			'requires_queue' => false,
			'prefer_queue'   => false,
			'idempotent'     => false,
			'parallelizable' => false,
		);

		return wp_parse_args( $custom, $defaults );
	}
}
