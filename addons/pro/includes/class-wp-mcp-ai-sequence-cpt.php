<?php
/**
 * Outreach Sequence Custom Post Type — multi-step outreach cadence definitions.
 * @package WP_MCP_AI_Pro @subpackage CRM_Toolkit @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Sequence_CPT {
	const POST_TYPE = 'mcp_ai_sequence';
	public static function init() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $s['enable_crm_toolkit'] ) ) {
			return;
		} add_action( 'init', array( __CLASS__, 'register_post_type' ) ); }
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => _x( 'Sequences', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name' => _x( 'Sequence', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Sequence', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Sequence', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Sequences', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=mcp_ai_lead',
				'capability_type' => 'post',
				'has_archive'     => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}
}
