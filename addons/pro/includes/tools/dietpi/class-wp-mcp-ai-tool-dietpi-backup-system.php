<?php
/**
 * DietPi Backup System Tool
 *
 * Trigger and manage DietPi system backups via dietpi-backup.
 * Supports listing existing backups, creating new backups (full or app-data only),
 * and checking backup status.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Backup_System' ) ) {

	/**
	 * Backup system tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Backup_System extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_backup_system';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi Backup System', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Manage DietPi system backups. List existing backups and their dates/sizes, create a new full system backup or app-data-only backup, and check backup status. Backups are created via dietpi-backup and stored in the configured backup location.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'description' => __( 'Backup action to perform.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'list', 'create', 'status' ),
					),
					'backup_type' => array(
						'type'        => 'string',
						'description' => __( 'Type of backup to create (for create action). "full" backs up the entire system. "app_data" backs up only application data and settings.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'full', 'app_data' ),
						'default'     => 'full',
					),
				),
				'required'   => array( 'action' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'performance-impact', 'may-timeout' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage backups.', 'mcp-ai-wpoos-pro' ) );
			}

			$action = $this->sanitize_string( $arguments, 'action' );

			switch ( $action ) {
				case 'list':
					// List existing backups from the default backup directory.
					$result = $this->ssh()->exec(
						'echo "BACKUP_DIR:$(grep -E "^[^#]*BACKUP_DIR" /boot/dietpi.txt 2>/dev/null | cut -d= -f2 || echo /mnt/dietpi_userdata/backup)";' .
						'BACKUP_DIR=$(grep -E "^[^#]*BACKUP_DIR" /boot/dietpi.txt 2>/dev/null | cut -d= -f2 || echo /mnt/dietpi_userdata/backup);' .
						'ls -lh "$BACKUP_DIR"/*.tar.gz 2>/dev/null || echo "NO_BACKUPS_FOUND"',
						15
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$lines      = explode( "\n", $result['stdout'] );
					$backup_dir = '';
					$backups    = array();

					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						if ( 0 === strpos( $line, 'BACKUP_DIR:' ) ) {
							$backup_dir = trim( substr( $line, 11 ) );
							continue;
						}
						if ( 'NO_BACKUPS_FOUND' === $line ) {
							continue;
						}
						// Parse ls -lh output: "-rw-r--r-- 1 root root 256M Jan 15 10:30 backup_file.tar.gz".
						if ( preg_match( '/^[-drwx]+\s+\d+\s+\S+\s+\S+\s+(\S+)\s+(\S+\s+\d+)\s+(\S+)\s+(.+)$/', $line, $m ) ) {
							$backups[] = array(
								'size' => $m[1],
								'date' => trim( $m[2] ),
								'time' => $m[3],
								'name' => $m[4],
							);
						}
					}

					return $this->success(
						sprintf(
							/* translators: %d: number of backups */
							_n( 'Found %d backup.', 'Found %d backups.', count( $backups ), 'mcp-ai-wpoos-pro' ),
							count( $backups )
						),
						array(
							'backup_directory' => $backup_dir,
							'backups'          => $backups,
						)
					);

				case 'create':
					$backup_type = $this->sanitize_string( $arguments, 'backup_type', 'full' );

					if ( 'full' === $backup_type ) {
						$cmd = '/boot/dietpi/dietpi-backup 1';
					} else {
						$cmd = '/boot/dietpi/dietpi-backup 2';
					}

					$result = $this->ssh()->exec( $cmd, 120 ); // Backups can take a while.

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$success = ( 0 === $result['exit_code'] || false !== strpos( $result['stdout'] . $result['stderr'], 'success' ) );

					return $this->success(
						$success
							? sprintf(
								/* translators: %s: backup type (full system or app data) */
								__( '%s backup completed successfully.', 'mcp-ai-wpoos-pro' ),
								'full' === $backup_type ? __( 'Full system', 'mcp-ai-wpoos-pro' ) : __( 'App data', 'mcp-ai-wpoos-pro' )
							)
							: __( 'Backup completed with warnings. Check stderr for details.', 'mcp-ai-wpoos-pro' ),
						array(
							'backup_type' => $backup_type,
							'stdout'      => $result['stdout'],
							'stderr'      => $result['stderr'],
							'exit_code'   => $result['exit_code'],
							'duration_ms' => $result['duration_ms'],
						)
					);

				case 'status':
					// Check if any backup is currently running and show disk usage of backup dir.
					$result = $this->ssh()->exec(
						'BACKUP_DIR=$(grep -E "^[^#]*BACKUP_DIR" /boot/dietpi.txt 2>/dev/null | cut -d= -f2 || echo /mnt/dietpi_userdata/backup);' .
						'echo "BACKUP_DIR:$BACKUP_DIR";' .
						'echo "DISK_USAGE:$(du -sh "$BACKUP_DIR" 2>/dev/null | awk \'{print $1}\' || echo "0")";' .
						'echo "BACKUP_COUNT:$(ls "$BACKUP_DIR"/*.tar.gz 2>/dev/null | wc -l)";',
						15
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$status = array();
					foreach ( explode( "\n", $result['stdout'] ) as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						$parts = explode( ':', $line, 2 );
						if ( 2 === count( $parts ) ) {
							$status[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
						}
					}

					return $this->success(
						__( 'Backup status retrieved.', 'mcp-ai-wpoos-pro' ),
						$status
					);

				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid backup action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
