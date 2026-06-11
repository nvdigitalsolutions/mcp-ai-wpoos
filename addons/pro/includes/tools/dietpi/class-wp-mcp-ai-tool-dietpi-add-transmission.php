<?php
/**
 * DietPi Add Transmission Torrent Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Add_Transmission' ) ) {
	class WP_MCP_AI_Tool_DietPi_Add_Transmission extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_add_transmission'; }
		public function get_name() { return __( 'Add Transmission Torrent', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'Add a new torrent to Transmission by URL, magnet link, or base64-encoded .torrent file. Optionally specify a download directory, whether to start paused, and a label.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'source' => array( 'type' => 'string', 'description' => __( 'URL, magnet link, or base64-encoded .torrent file content.', 'mcp-ai-wpoos-pro' ) ),
					'download_dir' => array( 'type' => 'string', 'description' => __( 'Custom download directory. Uses Transmission default if omitted.', 'mcp-ai-wpoos-pro' ) ),
					'paused' => array( 'type' => 'boolean', 'description' => __( 'Add torrent in paused state. Default: false.', 'mcp-ai-wpoos-pro' ), 'default' => false ),
					'label' => array( 'type' => 'string', 'description' => __( 'Label/tag to assign to the torrent.', 'mcp-ai-wpoos-pro' ) ),
				),
				'required' => array( 'source' ),
			);
		}
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			$source = $this->sanitize_string( $arguments, 'source' );
			if ( '' === $source ) { return new WP_Error( 'wp_mcp_ai_missing_source', __( 'A torrent source (URL, magnet, or base64 .torrent) is required.', 'mcp-ai-wpoos-pro' ) ); }
			$args = array();
			if ( 0 === strpos( $source, 'magnet:' ) ) { $args['filename'] = $source; }
			elseif ( 0 === strpos( $source, 'http' ) ) { $args['filename'] = $source; }
			else { $args['metainfo'] = $source; }
			$dir = $this->sanitize_string( $arguments, 'download_dir' );
			if ( '' !== $dir ) { $args['download-dir'] = $dir; }
			$args['paused'] = $this->sanitize_bool( $arguments, 'paused', false );
			$label = $this->sanitize_string( $arguments, 'label' );
			if ( '' !== $label ) { $args['labels'] = array( $label ); }
			$result = $this->app_client()->transmission_rpc( 'torrent-add', $args );
			if ( is_wp_error( $result ) ) { return $result; }
			$added = isset( $result['torrent-added'] ) ? $result['torrent-added'] : ( isset( $result['torrent-duplicate'] ) ? $result['torrent-duplicate'] : null );
			return $this->success( __( 'Torrent added successfully.', 'mcp-ai-wpoos-pro' ), array( 'torrent' => $added, 'paused' => $args['paused'] ) );
		}
	}
}
