<?php
/**
 * DietPi Control Transmission Tool — Start/stop/remove torrents, set limits, move data.
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Control_Transmission' ) ) {
	class WP_MCP_AI_Tool_DietPi_Control_Transmission extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_control_transmission'; }
		public function get_name() { return __( 'Control Transmission Torrents', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'Control individual or all torrents in Transmission: start, stop, remove (with optional data deletion), verify, set speed limits, move data, or set labels. Destructive actions require explicit confirmation.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'action' => array( 'type' => 'string', 'description' => __( 'Control action to perform.', 'mcp-ai-wpoos-pro' ), 'enum' => array( 'start', 'stop', 'remove', 'verify', 'set_speed', 'set_location', 'set_label' ) ),
					'torrent_ids' => array( 'type' => 'array', 'description' => __( 'Torrent ID(s) to act on. Omit or set to "all" to act on all torrents.', 'mcp-ai-wpoos-pro' ), 'items' => array( 'type' => 'integer' ) ),
					'download_limit_kbps' => array( 'type' => 'integer', 'description' => __( 'Download speed limit in KB/s (for set_speed action).', 'mcp-ai-wpoos-pro' ), 'minimum' => 0 ),
					'upload_limit_kbps' => array( 'type' => 'integer', 'description' => __( 'Upload speed limit in KB/s (for set_speed action).', 'mcp-ai-wpoos-pro' ), 'minimum' => 0 ),
					'new_location' => array( 'type' => 'string', 'description' => __( 'New download directory path (for set_location action).', 'mcp-ai-wpoos-pro' ) ),
					'label' => array( 'type' => 'string', 'description' => __( 'Label to set (for set_label action).', 'mcp-ai-wpoos-pro' ) ),
					'delete_local_data' => array( 'type' => 'boolean', 'description' => __( 'Also delete downloaded files (for remove action). Default: false.', 'mcp-ai-wpoos-pro' ), 'default' => false ),
					'confirm' => wp_mcp_ai_dietpi_param_confirm(),
				),
				'required' => array( 'action' ),
			);
		}
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			$action = $this->sanitize_string( $arguments, 'action' );
			if ( '' === $action ) { return new WP_Error( 'wp_mcp_ai_missing_action', __( 'A control action is required.', 'mcp-ai-wpoos-pro' ) ); }
			$ids = isset( $arguments['torrent_ids'] ) && is_array( $arguments['torrent_ids'] ) ? array_map( 'absint', $arguments['torrent_ids'] ) : array();
			// Confirm destructive actions.
			if ( 'remove' === $action && ! $this->sanitize_confirm( $arguments ) ) { return new WP_Error( 'wp_mcp_ai_confirm_required', __( 'Removing torrents requires confirm=true. This action cannot be undone.', 'mcp-ai-wpoos-pro' ) ); }
			$delete_local = $this->sanitize_bool( $arguments, 'delete_local_data', false );
			$result = null;
			switch ( $action ) {
				case 'start': $result = $this->app_client()->transmission_rpc( 'torrent-start', empty( $ids ) ? array() : array( 'ids' => $ids ) ); break;
				case 'stop': $result = $this->app_client()->transmission_rpc( 'torrent-stop', empty( $ids ) ? array() : array( 'ids' => $ids ) ); break;
				case 'remove': $result = $this->app_client()->transmission_rpc( 'torrent-remove', array( 'ids' => $ids, 'delete-local-data' => $delete_local ) ); break;
				case 'verify': $result = $this->app_client()->transmission_rpc( 'torrent-verify', array( 'ids' => $ids ) ); break;
				case 'set_speed':
					$dl = $this->sanitize_int( $arguments, 'download_limit_kbps', -1 );
					$ul = $this->sanitize_int( $arguments, 'upload_limit_kbps', -1 );
					$speed_args = array( 'ids' => $ids );
					if ( $dl >= 0 ) { $speed_args['downloadLimit'] = $dl; $speed_args['downloadLimited'] = $dl > 0; }
					if ( $ul >= 0 ) { $speed_args['uploadLimit'] = $ul; $speed_args['uploadLimited'] = $ul > 0; }
					$result = $this->app_client()->transmission_rpc( 'torrent-set', $speed_args ); break;
				case 'set_location':
					$loc = $this->sanitize_string( $arguments, 'new_location' );
					if ( '' === $loc ) { return new WP_Error( 'wp_mcp_ai_missing_location', __( 'A new location path is required.', 'mcp-ai-wpoos-pro' ) ); }
					$result = $this->app_client()->transmission_rpc( 'torrent-set-location', array( 'ids' => $ids, 'location' => $loc, 'move' => true ) ); break;
				case 'set_label':
					$label = $this->sanitize_string( $arguments, 'label' );
					$result = $this->app_client()->transmission_rpc( 'torrent-set', array( 'ids' => $ids, 'labels' => array( $label ) ) ); break;
			}
			if ( is_wp_error( $result ) ) { return $result; }
			return $this->success( sprintf( __( 'Action "%s" completed on torrents.', 'mcp-ai-wpoos-pro' ), $action ), array( 'action' => $action, 'torrent_ids' => $ids ) );
		}
	}
}
