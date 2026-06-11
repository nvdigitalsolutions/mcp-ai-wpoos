<?php
/**
 * DietPi Provision New App Tool
 *
 * Install and configure a new software package on DietPi via dietpi-software.
 * Supports listing available software, installing a package by DietPi software ID
 * or name, and checking installation status.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Provision_New_App' ) ) {

	/**
	 * Provision new app tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Provision_New_App extends WP_MCP_AI_Tool_DietPi_Base {

		public function get_slug()        { return 'dietpi_provision_new_app'; }
		public function get_name()        { return __( 'DietPi Provision New App', 'mcp-ai-wpoos-pro' ); }
		public function get_description() {
			return __( 'Install and configure new software on the DietPi device using dietpi-software. Search for available software packages, install one or more packages by DietPi software ID or name, and check installation status. Installing software requires explicit confirmation. Supports the 200+ software titles available in the DietPi optimized software catalogue.', 'mcp-ai-wpoos-pro' );
		}

		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'description' => __( 'Provisioning action to perform.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'search', 'install', 'status' ),
					),
					'query' => array(
						'type'        => 'string',
						'description' => __( 'Search term for finding software (for search action). Searches software name and description.', 'mcp-ai-wpoos-pro' ),
					),
					'software_ids' => array(
						'type'        => 'array',
						'description' => __( 'DietPi software ID(s) to install (for install action). Use search action first to find IDs. Common IDs: 44=Transmission, 135=Jackett, 144=Sonarr, 145=Radarr, 42=Plex, 169=Jellyfin, 130=Pi-hole, 96=Home Assistant, 162=Nextcloud.', 'mcp-ai-wpoos-pro' ),
						'items'       => array( 'type' => 'integer' ),
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
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to install software.', 'mcp-ai-wpoos-pro' ) );
			}

			$action = $this->sanitize_string( $arguments, 'action' );

			switch ( $action ) {
				case 'search':
					$query = $this->sanitize_string( $arguments, 'query' );
					if ( '' === $query ) {
						return new WP_Error( 'wp_mcp_ai_missing_query', __( 'A search query is required.', 'mcp-ai-wpoos-pro' ) );
					}

					// Use dietpi-software list and grep for the query.
					$result = $this->ssh()->exec(
						sprintf(
							'dietpi-software list 2>/dev/null | grep -i %s | head -30 || echo "NO_RESULTS"',
							escapeshellarg( $query )
						),
						20
					);
					if ( is_wp_error( $result ) ) { return $result; }

					$stdout = trim( $result['stdout'] );
					if ( '' === $stdout || 'NO_RESULTS' === $stdout ) {
						return $this->success(
							sprintf( __( 'No software found matching "%s".', 'mcp-ai-wpoos-pro' ), $query ),
							array( 'query' => $query, 'results' => array() )
						);
					}

					// Parse dietpi-software list output.
					// Typical format: "ID 42   │ Plex Media Server       │ Stream your media to any device"
					$results = array();
					foreach ( explode( "\n", $stdout ) as $line ) {
						$line = trim( $line );
						if ( '' === $line ) { continue; }
						if ( preg_match( '/^\s*ID\s+(\d+)\s*[│|]\s*(.+?)\s*[│|]\s*(.+)$/u', $line, $m ) ) {
							$results[] = array(
								'id'          => (int) $m[1],
								'name'        => trim( $m[2] ),
								'description' => trim( $m[3] ),
							);
						}
					}

					return $this->success(
						sprintf( _n( 'Found %d match for "%s".', 'Found %d matches for "%s".', count( $results ), 'mcp-ai-wpoos-pro' ), count( $results ), $query ),
						array(
							'query'   => $query,
							'results' => $results,
						)
					);

				case 'install':
					if ( ! $this->sanitize_confirm( $arguments ) ) {
						return new WP_Error(
							'wp_mcp_ai_confirm_required',
							__( 'Installing software requires confirm=true. This will modify the system.', 'mcp-ai-wpoos-pro' )
						);
					}

					$ids = isset( $arguments['software_ids'] ) && is_array( $arguments['software_ids'] )
						? array_map( 'absint', $arguments['software_ids'] )
						: array();
					if ( empty( $ids ) ) {
						return new WP_Error( 'wp_mcp_ai_missing_ids', __( 'At least one software_id is required for installation.', 'mcp-ai-wpoos-pro' ) );
					}

					$id_list = implode( ',', $ids );
					$result  = $this->ssh()->exec(
						sprintf( 'dietpi-software install %s', $id_list ),
						600 // Software installation can take several minutes.
					);

					if ( is_wp_error( $result ) ) { return $result; }

					return $this->success(
						sprintf(
							/* translators: %s: comma-separated software IDs */
							__( 'Software installation initiated for IDs: %s. Check stdout for progress details.', 'mcp-ai-wpoos-pro' ),
							$id_list
						),
						array(
							'software_ids' => $ids,
							'stdout'       => $result['stdout'],
							'stderr'       => $result['stderr'],
							'exit_code'    => $result['exit_code'],
							'duration_ms'  => $result['duration_ms'],
						)
					);

				case 'status':
					// Show currently installed software list.
					$result = $this->ssh()->exec(
						'dietpi-software list 2>/dev/null | grep "=2" | head -50 || echo "NO_INSTALLED"',
						15
					);
					if ( is_wp_error( $result ) ) { return $result; }

					$installed = array();
					foreach ( explode( "\n", trim( $result['stdout'] ) ) as $line ) {
						$line = trim( $line );
						if ( '' === $line || 'NO_INSTALLED' === $line ) { continue; }
						if ( preg_match( '/^\s*ID\s+(\d+)\s*[│|]\s*(.+?)\s*[│|]/u', $line, $m ) ) {
							$installed[] = array(
								'id'   => (int) $m[1],
								'name' => trim( $m[2] ),
							);
						}
					}

					return $this->success(
						sprintf( _n( 'Found %d installed software package.', 'Found %d installed software packages.', count( $installed ), 'mcp-ai-wpoos-pro' ), count( $installed ) ),
						array( 'installed' => $installed )
					);

				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid provisioning action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
