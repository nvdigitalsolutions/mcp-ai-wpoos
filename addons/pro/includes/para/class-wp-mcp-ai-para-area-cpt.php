<?php
/**
 * PARA Area Custom Post Type.
 *
 * Areas represent ongoing responsibilities in the PARA framework — distinct
 * from Projects which are deadline-bound. Each Area has a "standard to
 * maintain", an owner, and a review cadence.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the `mcp_ai_area` CPT.
 */
class WP_MCP_AI_PARA_Area_CPT {

	const POST_TYPE = 'mcp_ai_area';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	/**
	 * Register the CPT.
	 */
	public static function register() {
		if ( ! class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) || ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => __( 'Areas', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => __( 'Area', 'mcp-ai-wpoos-pro' ),
					'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Area', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Area', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Area', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Area', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Areas', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No areas found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No areas found in trash', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => __( 'Areas', 'mcp-ai-wpoos-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=mcp_ai_project',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author', 'revisions' ),
				'menu_icon'          => 'dashicons-portfolio',
			)
		);
	}

	/**
	 * Save Area meta on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_meta( $post_id, $post ) {
		unset( $post );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wp_mcp_ai_area_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_area_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_ai_area_save' ) ) {
			return;
		}

		$standard = isset( $_POST['_para_standard'] ) ? wp_kses_post( wp_unslash( $_POST['_para_standard'] ) ) : '';
		$owner    = isset( $_POST['_para_owner'] ) ? absint( $_POST['_para_owner'] ) : 0;
		$cadence  = isset( $_POST['_para_review_cadence'] ) ? sanitize_key( wp_unslash( $_POST['_para_review_cadence'] ) ) : 'monthly';

		$valid_cadences = array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' );
		if ( ! in_array( $cadence, $valid_cadences, true ) ) {
			$cadence = 'monthly';
		}

		update_post_meta( $post_id, '_para_standard', $standard );
		update_post_meta( $post_id, '_para_owner', $owner );
		update_post_meta( $post_id, '_para_review_cadence', $cadence );
	}

	/**
	 * Get Area data as array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_area( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'description'    => $post->post_content,
			'standard'       => (string) get_post_meta( $post->ID, '_para_standard', true ),
			'owner_id'       => (int) get_post_meta( $post->ID, '_para_owner', true ),
			'review_cadence' => (string) get_post_meta( $post->ID, '_para_review_cadence', true ),
			'last_reviewed'  => (string) get_post_meta( $post->ID, '_para_last_reviewed', true ),
			'status'         => $post->post_status,
		);
	}
}
