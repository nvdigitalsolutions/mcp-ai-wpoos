<?php
/**
 * DietPi System Stats Tool — Live CPU temp, RAM, disk, load.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_System_Stats' ) ) {

	/**
	 * System Stats tool.
	 */
	class WP_MCP_AI_Tool_DietPi_System_Stats extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_system_stats';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi System Stats', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Get live system statistics: CPU temperature and frequency, RAM usage, disk space, system load, and Raspberry Pi throttling flags (undervoltage, thermal throttling, etc.).', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would encode
				// as `[]`, which strict providers (DeepSeek) reject.
				'properties' => new stdClass(),
			);
		}

		/** {@inheritdoc} */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$stats = $this->ssh()->system_stats();
			if ( is_wp_error( $stats ) ) {
				return $stats;
			}
			// Parse throttling flags from vcgencmd.
			$throttle_str = isset( $stats['throttled'] ) ? $stats['throttled'] : '';
			$throttled    = array();
			if ( '' !== $throttle_str && preg_match( '/0x([0-9a-fA-F]+)/', $throttle_str, $m ) ) {
				$v                                   = hexdec( $m[1] );
				$throttled['undervoltage_detected']  = (bool) ( $v & 0x1 );
				$throttled['arm_freq_capped']        = (bool) ( $v & 0x2 );
				$throttled['currently_throttled']    = (bool) ( $v & 0x4 );
				$throttled['soft_temp_limit_active'] = (bool) ( $v & 0x8 );
				$throttled['undervoltage_occurred']  = (bool) ( $v & 0x10000 );
				$throttled['throttling_occurred']    = (bool) ( $v & 0x40000 );
			}
			return $this->success( __( 'System stats retrieved.', 'mcp-ai-wpoos-pro' ), array_merge( $stats, array( 'throttling' => $throttled ) ) );
		}
	}
}
