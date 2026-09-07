<?php
/**
 * CRM Workflow Rule Custom Post Type — if-this-then-that automation rules.
 *
 * Ported from the base Pro addon for the standalone
 * nvoos-content-graph-pro addon (Wave F2 pilot). Global class name kept
 * byte-identical; the base Pro addon owns the class in monolith installs.
 * Deviations: strict types; text domain nvoos-content-graph-pro.
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 * @since 2.3.0
 */

declare(strict_types=1);

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
					'name'          => _x( 'Workflow Rules', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name' => _x( 'Workflow Rule', 'post type singular name', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Rule', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Rule', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Rules', 'nvoos-content-graph-pro' ),
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
