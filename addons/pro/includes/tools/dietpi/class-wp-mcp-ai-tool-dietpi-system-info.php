<?php
/**
 * DietPi System Info Tool — Pi model, OS version, kernel, uptime.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_System_Info' ) ) {

	/**
	 * System Info tool.
	 */
	class WP_MCP_AI_Tool_DietPi_System_Info extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_system_info';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi System Info', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Get detailed system information about the Raspberry Pi including model, OS version, kernel version, DietPi version, and uptime.', 'mcp-ai-wpoos-pro' );
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
			$pi_info = $this->ssh()->raspberry_pi_info();
			if ( is_wp_error( $pi_info ) ) {
				return $pi_info;
			}
			$os_info = $this->ssh()->exec( 'echo "OS:$(cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d \'\\"\')";echo "KERNEL:$(uname -r)";echo "DIETPI:$(cat /boot/dietpi/.version 2>/dev/null || echo unknown)";echo "HOSTNAME:$(hostname)";echo "UPTIME:$(uptime -p | sed \'s/up //\')"' );
			$os      = array();
			$os_raw  = is_wp_error( $os_info ) ? '' : $os_info['stdout'];
			foreach ( explode( "\n", $os_raw ) as $line ) {
				$p = explode( ':', trim( $line ), 2 );
				if ( 2 === count( $p ) ) {
					$os[ strtolower( $p[0] ) ] = $p[1];
				}
			}
			return $this->success( __( 'System information retrieved.', 'mcp-ai-wpoos-pro' ), array_merge( $pi_info, $os ) );
		}
	}
}
