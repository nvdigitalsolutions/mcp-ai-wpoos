<?php
/**
 * DietPi List Jackett Indexers Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_List_Jackett_Indexers' ) ) {
	class WP_MCP_AI_Tool_DietPi_List_Jackett_Indexers extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_list_jackett_indexers'; }
		public function get_name() { return __( 'List Jackett Indexers', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'List all configured Jackett indexers with their names, capabilities, and supported categories.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() { return array( 'type' => 'object', 'properties' => array() ); }
		public function get_required_capability() { return 'edit_posts'; }
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			$result = $this->app_client()->get( 'jackett', '/api/v2.0/indexers', array( 'configured' => 'true' ), 15 );
			if ( is_wp_error( $result ) ) { return $result; }
			$indexers = array();
			if ( is_array( $result ) ) {
				foreach ( $result as $idx ) {
					if ( ! is_array( $idx ) ) { continue; }
					$indexers[] = array(
						'id' => isset( $idx['id'] ) ? sanitize_text_field( $idx['id'] ) : '',
						'name' => isset( $idx['name'] ) ? sanitize_text_field( $idx['name'] ) : '',
						'description' => isset( $idx['description'] ) ? sanitize_text_field( $idx['description'] ) : '',
						'type' => isset( $idx['type'] ) ? sanitize_text_field( $idx['type'] ) : '',
						'language' => isset( $idx['language'] ) ? sanitize_text_field( $idx['language'] ) : '',
						'configured' => ! empty( $idx['configured'] ),
					);
				}
			}
			return $this->success( sprintf( _n( 'Found %d indexer.', 'Found %d indexers.', count( $indexers ), 'mcp-ai-wpoos-pro' ), count( $indexers ) ), array( 'indexers' => $indexers ) );
		}
	}
}
