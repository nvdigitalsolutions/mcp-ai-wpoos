<?php
/**
 * DietPi List Transmission Torrents Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_List_Transmission' ) ) {
	class WP_MCP_AI_Tool_DietPi_List_Transmission extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_list_transmission'; }
		public function get_name() { return __( 'List Transmission Torrents', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'List all torrents in Transmission with filtering by status or label. Returns torrent name, status, progress, download/upload speed, ETA, size, and label.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'status' => array( 'type' => 'string', 'description' => __( 'Filter by torrent status. If omitted, all torrents are returned.', 'mcp-ai-wpoos-pro' ), 'enum' => array( 'all', 'downloading', 'seeding', 'paused', 'checking', 'queued', 'error' ) ),
				),
			);
		}
		public function get_required_capability() { return 'edit_posts'; }
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			$fields = array( 'id', 'name', 'status', 'percentDone', 'rateDownload', 'rateUpload', 'eta', 'totalSize', 'downloadedEver', 'uploadedEver', 'labels', 'peersConnected', 'errorString', 'downloadDir', 'queuePosition' );
			$result = $this->app_client()->transmission_rpc( 'torrent-get', array( 'fields' => $fields ) );
			if ( is_wp_error( $result ) ) { return $result; }
			$torrents = isset( $result['torrents'] ) ? $result['torrents'] : array();
			$filter = $this->sanitize_string( $arguments, 'status', 'all' );
			if ( 'all' !== $filter ) {
				$status_map = array( 'downloading' => 4, 'seeding' => 6, 'paused' => 0, 'checking' => 2, 'queued' => 3, 'error' => 16 );
				$code = isset( $status_map[ $filter ] ) ? $status_map[ $filter ] : null;
				if ( null !== $code ) { $torrents = array_values( array_filter( $torrents, function( $t ) use ( $code ) { return (int) $t['status'] === $code; } ) ); }
			}
			$out = array(); foreach ( $torrents as $t ) {
				$out[] = array(
					'id' => (int) $t['id'], 'name' => $t['name'],
					'status' => (int) $t['status'], 'percent_done' => round( (float) $t['percentDone'] * 100, 1 ),
					'download_speed' => (int) $t['rateDownload'], 'upload_speed' => (int) $t['rateUpload'],
					'eta_seconds' => (int) $t['eta'], 'total_size' => (int) $t['totalSize'],
					'downloaded' => (int) $t['downloadedEver'], 'uploaded' => (int) $t['uploadedEver'],
					'labels' => isset( $t['labels'] ) ? $t['labels'] : array(), 'error' => isset( $t['errorString'] ) ? $t['errorString'] : '',
				);
			}
			return $this->success( sprintf( _n( 'Found %d torrent.', 'Found %d torrents.', count( $out ), 'mcp-ai-wpoos-pro' ), count( $out ) ), array( 'torrents' => $out ) );
		}
	}
}
