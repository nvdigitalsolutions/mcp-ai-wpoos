<?php
/**
 * CRM Workflow Rule Custom Post Type — if-this-then-that automation rules.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Workflow Rule CPT registration.
 */
class WP_MCP_AI_CRM_Workflow_Rule_CPT {

	const POST_TYPE = 'mcp_ai_crm_wf_rule';

	/**
	 * Initialize the CPT.
	 */
	public static function init() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $s['enable_crm_toolkit'] ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => _x( 'Workflow Rules', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name' => _x( 'Workflow Rule', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Rule', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Rule', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Rules', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=mcp_ai_lead',
				'capability_type' => 'post',
				'has_archive'     => false,
				'supports'        => array( 'title', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}
}
