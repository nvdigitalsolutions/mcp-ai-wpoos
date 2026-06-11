<?php
/**
 * DietPi Update System Tool
 *
 * Check for and apply DietPi OS updates, software updates, and package upgrades.
 * Supports checking available updates and running dietpi-update.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Update_System' ) ) {

	/**
	 * Update system tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Update_System extends WP_MCP_AI_Tool_DietPi_Base {

		public function get_slug()        { return 'dietpi_update_system'; }
		public function get_name()        { return __( 'DietPi Update System', 'mcp-ai-wpoos-pro' ); }
		public function get_description() {
			return __( 'Check for available DietPi OS and software updates, and apply them. Supports checking the current version, listing pending updates, and running dietpi-update to upgrade the system. Applying updates requires explicit confirmation.', 'mcp-ai-wpoos-pro' );
		}

		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'description' => __( 'Update action to perform.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'check', 'apply', 'list_packages' ),
					),
					'confirm' => wp_mcp_ai_dietpi_param_confirm(),
				),
				'required'   => array( 'action' ),
			);
		}

		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'performance-impact', 'may-timeout', 'network-dependent' ) );
		}

		public function execute( array $arguments = array(), array $context = array() ) {
			$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage system updates.', 'mcp-ai-wpoos-pro' ) );
			}

			$action = $this->sanitize_string( $arguments, 'action' );

			switch ( $action ) {
				case 'check':
					// Get current version info and check for updates.
					$result = $this->ssh()->exec(
						'echo "DIETPI_VERSION:$(cat /boot/dietpi/.version 2>/dev/null || echo unknown)";' .
						'echo "OS:$(cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\')";' .
						'echo "KERNEL:$(uname -r)";' .
						'echo "LAST_APT_UPDATE:$(stat -c %Y /var/cache/apt/pkgcache.bin 2>/dev/null | xargs -I{} date -d @{} "+%Y-%m-%d %H:%M" 2>/dev/null || echo "never")";' .
						'echo "PENDING_APT_COUNT:$(apt list --upgradable 2>/dev/null | grep -c upgradable || echo 0)";' .
						'echo "DIETPI_UPDATE_AVAILABLE:$(/boot/dietpi/dietpi-update 1 2>/dev/null | grep -c "available" || echo "unknown")";',
						20
					);
					if ( is_wp_error( $result ) ) { return $result; }

					$info = array();
					foreach ( explode( "\n", $result['stdout'] ) as $line ) {
						$line = trim( $line );
						if ( '' === $line ) { continue; }
						$parts = explode( ':', $line, 2 );
						if ( 2 === count( $parts ) ) {
							$info[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
						}
					}

					$pending = isset( $info['pending_apt_count'] ) ? (int) $info['pending_apt_count'] : 0;

					return $this->success(
						$pending > 0
							? sprintf( _n( '%d package update available.', '%d package updates available.', $pending, 'mcp-ai-wpoos-pro' ), $pending )
							: __( 'System is up to date.', 'mcp-ai-wpoos-pro' ),
						$info
					);

				case 'apply':
					if ( ! $this->sanitize_confirm( $arguments ) ) {
						return new WP_Error(
							'wp_mcp_ai_confirm_required',
							__( 'Applying system updates requires confirm=true. This will modify the operating system.', 'mcp-ai-wpoos-pro' )
						);
					}

					// Run dietpi-update non-interactively (mode 1 = check and apply).
					$result = $this->ssh()->exec( '/boot/dietpi/dietpi-update 1', 300 );

					if ( is_wp_error( $result ) ) { return $result; }

					return $this->success(
						__( 'System update process completed. Check stdout for details.', 'mcp-ai-wpoos-pro' ),
						array(
							'stdout'      => $result['stdout'],
							'stderr'      => $result['stderr'],
							'exit_code'   => $result['exit_code'],
							'duration_ms' => $result['duration_ms'],
						)
					);

				case 'list_packages':
					// List upgradable apt packages.
					$result = $this->ssh()->exec(
						'apt list --upgradable 2>/dev/null | tail -n +2 | awk -F/ \'{printf "%s (%s -> %s)\\n", $1, $2, $3}\' || echo "No upgradable packages."',
						15
					);
					if ( is_wp_error( $result ) ) { return $result; }

					$packages = array_filter( array_map( 'trim', explode( "\n", $result['stdout'] ) ) );

					return $this->success(
						sprintf( _n( '%d upgradable package.', '%d upgradable packages.', count( $packages ), 'mcp-ai-wpoos-pro' ), count( $packages ) ),
						array( 'packages' => array_values( $packages ) )
					);

				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid update action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
