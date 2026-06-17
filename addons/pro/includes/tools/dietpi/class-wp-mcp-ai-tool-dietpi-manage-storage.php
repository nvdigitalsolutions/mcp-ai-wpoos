<?php
/**
 * DietPi Manage Storage Tool
 *
 * Inspect and manage storage on the DietPi device.
 * Supports listing drives/mounts, checking free space, managing
 * the DietPi drive manager, and viewing directory sizes.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Manage_Storage' ) ) {

	/**
	 * Manage storage tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Manage_Storage extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_manage_storage';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi Manage Storage', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Inspect and manage storage on the DietPi device. List all mounted drives with usage, check free space on a specific path (useful for Transmission download directory), list large directories by size, and view drive information via dietpi-drive_manager.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'description' => __( 'Storage action to perform.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'list_mounts', 'free_space', 'large_dirs', 'drive_info' ),
					),
					'path'        => array(
						'type'        => 'string',
						'description' => __( 'Filesystem path to check (for free_space and large_dirs actions). Default for free_space: /.', 'mcp-ai-wpoos-pro' ),
					),
					'min_size_mb' => array(
						'type'        => 'integer',
						'description' => __( 'Minimum directory size in MB to report (for large_dirs action). Default: 100.', 'mcp-ai-wpoos-pro' ),
						'default'     => 100,
						'minimum'     => 1,
					),
				),
				'required'   => array( 'action' ),
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
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$action = $this->sanitize_string( $arguments, 'action' );

			switch ( $action ) {
				case 'list_mounts':
					$result = $this->ssh()->exec( 'df -h --type=ext4 --type=ext3 --type=vfat --type=ntfs --type=exfat --type=fuseblk 2>/dev/null || df -h', 10 );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$mounts  = array();
					$lines   = explode( "\n", $result['stdout'] );
					$headers = array();

					foreach ( $lines as $i => $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						// Skip header line.
						if ( 0 === strpos( $line, 'Filesystem' ) ) {
							$headers = preg_split( '/\s+/', $line );
							continue;
						}
						$cols = preg_split( '/\s+/', $line, 6 );
						if ( count( $cols ) >= 6 ) {
							$mounts[] = array(
								'filesystem'  => $cols[0],
								'size'        => $cols[1],
								'used'        => $cols[2],
								'available'   => $cols[3],
								'use_percent' => $cols[4],
								'mounted_on'  => $cols[5],
							);
						}
					}

					return $this->success(
						sprintf(
							/* translators: %d: number of mount points */
							_n( 'Found %d mount point.', 'Found %d mount points.', count( $mounts ), 'mcp-ai-wpoos-pro' ),
							count( $mounts )
						),
						array( 'mounts' => $mounts )
					);

				case 'free_space':
					$path = $this->sanitize_string( $arguments, 'path', '/' );
					if ( '' === $path ) {
						$path = '/';
					}

					$result = $this->ssh()->exec(
						sprintf( 'df -h %s | tail -1', escapeshellarg( $path ) ),
						10
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$cols = preg_split( '/\s+/', trim( $result['stdout'] ), 6 );
					$info = array(
						'path' => $path,
					);
					if ( count( $cols ) >= 6 ) {
						$info = array_merge(
							$info,
							array(
								'filesystem'  => $cols[0],
								'size'        => $cols[1],
								'used'        => $cols[2],
								'available'   => $cols[3],
								'use_percent' => $cols[4],
							)
						);
					}

					return $this->success(
						sprintf(
							/* translators: %1$s: path, %2$s: available space */
							__( 'Free space on %1$s: %2$s available.', 'mcp-ai-wpoos-pro' ),
							$path,
							isset( $info['available'] ) ? $info['available'] : 'unknown'
						),
						$info
					);

				case 'large_dirs':
					$path   = $this->sanitize_string( $arguments, 'path', '/' );
					$min_mb = max( 1, $this->sanitize_int( $arguments, 'min_size_mb', 100 ) );

					if ( '' === $path ) {
						$path = '/';
					}

					$min_bytes = $min_mb * 1024;

					$result = $this->ssh()->exec(
						sprintf(
							'du -sk %s/* 2>/dev/null | sort -rn | head -20 | awk \'{if($1>=%d) printf "%.1fM\\t%s\\n", $1/1024, $2}\'',
							escapeshellarg( $path ),
							$min_bytes
						),
						30
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$dirs = array();
					foreach ( explode( "\n", trim( $result['stdout'] ) ) as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						$parts = preg_split( '/\t/', $line, 2 );
						if ( 2 === count( $parts ) ) {
							$dirs[] = array(
								'size' => $parts[0],
								'path' => $parts[1],
							);
						}
					}

					return $this->success(
						sprintf(
							/* translators: %1$d: number of directories, %2$d: minimum size in MB */
							__( 'Found %1$d directories larger than %2$d MB.', 'mcp-ai-wpoos-pro' ),
							count( $dirs ),
							$min_mb
						),
						array(
							'base_path'   => $path,
							'min_size_mb' => $min_mb,
							'directories' => $dirs,
						)
					);

				case 'drive_info':
					// Run dietpi-drive_manager to list drives.
					$result = $this->ssh()->exec( 'dietpi-drive_manager list 2>/dev/null || lsblk -o NAME,SIZE,TYPE,MOUNTPOINT,FSTYPE 2>/dev/null', 15 );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					// Also get drive temperatures if available.
					$temp_result = $this->ssh()->exec( 'hddtemp /dev/sd? 2>/dev/null || echo "NO_HDDTEMP"', 10 );
					$temps       = '';
					if ( ! is_wp_error( $temp_result ) && 'NO_HDDTEMP' !== trim( $temp_result['stdout'] ) ) {
						$temps = $temp_result['stdout'];
					}

					return $this->success(
						__( 'Drive information retrieved.', 'mcp-ai-wpoos-pro' ),
						array(
							'drive_list'   => $result['stdout'],
							'temperatures' => $temps ? $temps : null,
						)
					);

				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid storage action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
