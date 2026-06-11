<?php
/**
 * DietPi Control Service Tool
 * Start/stop/restart a DietPi-managed service.
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Control_Service' ) ) {
	class WP_MCP_AI_Tool_DietPi_Control_Service extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_control_service'; }
		public function get_name() { return __( 'Control DietPi Service', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'Start, stop, or restart a DietPi-managed service (e.g. sonarr, radarr, transmission-daemon, jackett, plexmediaserver, jellyfin). Requires manage_options capability.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'service_name' => wp_mcp_ai_dietpi_param_service_name(),
					'action' => wp_mcp_ai_dietpi_param_service_action(),
				),
				'required' => array( 'service_name', 'action' ),
			);
		}
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing' ) );
		}
		public function execute( array $arguments = array(), array $context = array() ) {
			$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to control services.', 'mcp-ai-wpoos-pro' ) );
			}
			$service = $this->sanitize_service_name( $arguments );
			if ( '' === $service ) { return new WP_Error( 'wp_mcp_ai_missing_service', __( 'A valid service_name is required.', 'mcp-ai-wpoos-pro' ) ); }
			$action = $this->sanitize_service_action( $arguments );
			if ( '' === $action ) { return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action. Must be start, stop, restart, or status.', 'mcp-ai-wpoos-pro' ) ); }
			$result = $this->ssh()->dietpi_services( $action, $service );
			if ( is_wp_error( $result ) ) { return $result; }
			return $this->success(
				sprintf( __( 'Service "%1$s" %2$s completed.', 'mcp-ai-wpoos-pro' ), $service, $action ),
				array( 'service_name' => $service, 'action' => $action, 'stdout' => $result['stdout'], 'stderr' => $result['stderr'] )
			);
		}
	}
}
