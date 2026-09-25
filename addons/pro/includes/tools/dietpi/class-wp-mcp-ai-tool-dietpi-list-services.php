<?php
/**
 * DietPi List Services Tool
 *
 * Lists all DietPi-managed services and their running states.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_List_Services' ) ) {

	/**
	 * List services tool.
	 */
	class WP_MCP_AI_Tool_DietPi_List_Services extends WP_MCP_AI_Tool_DietPi_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_list_services';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List DietPi Services', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all DietPi-managed services with their current running states. Services include Transmission, Jackett, Sonarr, Radarr, Plex, Jellyfin, and any other software managed by DietPi.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Listing all DietPi-managed services and their current running states.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Changing a service state; use dietpi_control_service. Full system diagnostics; use dietpi_health_check.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'dietpi_control_service', 'dietpi_health_check', 'dietpi_dashboard_summary' ),
			);
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
			$result = $this->ssh()->list_services();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Categorise into managed vs other.
			$managed_apps = WP_MCP_AI_DietPi_Service_Catalogue::get_managed_apps();
			$managed      = array();
			$other        = array();

			foreach ( $result['services'] as $name => $status ) {
				$is_managed = false;
				foreach ( $managed_apps as $app ) {
					if ( false !== strpos( $name, $app ) || $app === $name ) {
						$managed[ $name ] = $status;
						$is_managed       = true;
						break;
					}
				}
				if ( ! $is_managed ) {
					$other[ $name ] = $status;
				}
			}

			return $this->success(
				/* translators: %d: number of services found. */
				sprintf( _n( 'Found %d service.', 'Found %d services.', $result['count'], 'mcp-ai-wpoos-pro' ), $result['count'] ),
				array(
					'managed_services' => $managed,
					'other_services'   => $other,
					'total_count'      => $result['count'],
				)
			);
		}
	}
}
