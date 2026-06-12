<?php
/**
 * Comic Character Custom Post Type for managing character reference sheets.
 *
 * Characters store a reference image, description, and style notes
 * used to maintain visual consistency across AI-generated comic panels.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Comic_Creation_Toolkit
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Comic Character custom post type.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Comic_Character_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_comic_char';

	/**
	 * Initialize the class.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		// Check if feature is available and enabled.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_comic_creation_toolkit'] ) ) {
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_character_meta' ), 5, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since 2.0.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Characters', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'      => _x( 'Character', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'          => _x( 'Characters', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'add_new'            => __( 'Add Character', 'mcp-ai-wpoos-pro' ),
			'add_new_item'       => __( 'Add New Character', 'mcp-ai-wpoos-pro' ),
			'edit_item'          => __( 'Edit Character', 'mcp-ai-wpoos-pro' ),
			'view_item'          => __( 'View Character', 'mcp-ai-wpoos-pro' ),
			'all_items'          => __( 'All Characters', 'mcp-ai-wpoos-pro' ),
			'search_items'       => __( 'Search Characters', 'mcp-ai-wpoos-pro' ),
			'not_found'          => __( 'No characters found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash' => __( 'No characters found in Trash.', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=mcp_ai_comic',
			'query_var'          => true,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ),
			'show_in_rest'       => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register meta boxes.
	 *
	 * @since 2.0.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'comic_character_config',
			__( 'Character Configuration', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_config_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render configuration metabox.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_config_metabox( $post ) {
		wp_nonce_field( 'comic_character_meta', 'comic_character_meta_nonce' );

		$style_notes     = get_post_meta( $post->ID, '_style_notes', true );
		$reference_image = get_post_meta( $post->ID, '_reference_image_id', true );
		$character_role  = get_post_meta( $post->ID, '_character_role', true );
		$age             = get_post_meta( $post->ID, '_character_age', true );
		$gender          = get_post_meta( $post->ID, '_character_gender', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="character_role"><?php esc_html_e( 'Role', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="character_role" id="character_role" class="regular-text">
						<option value="protagonist" <?php selected( $character_role, 'protagonist' ); ?>><?php esc_html_e( 'Protagonist', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="antagonist" <?php selected( $character_role, 'antagonist' ); ?>><?php esc_html_e( 'Antagonist', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="supporting" <?php selected( $character_role, 'supporting' ); ?>><?php esc_html_e( 'Supporting', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="sidekick" <?php selected( $character_role, 'sidekick' ); ?>><?php esc_html_e( 'Sidekick', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="mentor" <?php selected( $character_role, 'mentor' ); ?>><?php esc_html_e( 'Mentor', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="background" <?php selected( $character_role, 'background' ); ?>><?php esc_html_e( 'Background', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="character_age"><?php esc_html_e( 'Age', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="character_age" id="character_age" value="<?php echo esc_attr( $age ); ?>" class="regular-text" placeholder="e.g., 25, teenager, elderly" />
				</td>
			</tr>
			<tr>
				<th><label for="character_gender"><?php esc_html_e( 'Gender', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="character_gender" id="character_gender" value="<?php echo esc_attr( $gender ); ?>" class="regular-text" placeholder="e.g., male, female, non-binary" />
				</td>
			</tr>
			<tr>
				<th><label for="style_notes"><?php esc_html_e( 'Style Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="style_notes" id="style_notes" rows="4" class="large-text"><?php echo esc_textarea( $style_notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Detailed visual description for AI generation (hair, eyes, clothing, build, distinctive features)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="reference_image_id"><?php esc_html_e( 'Reference Image', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<?php if ( $reference_image ) : ?>
						<div style="max-width:300px; margin-bottom:8px;">
							<?php echo wp_get_attachment_image( $reference_image, 'medium' ); ?>
						</div>
					<?php endif; ?>
					<input type="number" name="reference_image_id" id="reference_image_id" value="<?php echo esc_attr( $reference_image ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Attachment ID of the character reference image used for consistent generation', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save character metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_character_meta( $post_id, $post ) {
		if ( ! isset( $_POST['comic_character_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['comic_character_meta_nonce'] ) ), 'comic_character_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'style_notes'        => 'sanitize_textarea_field',
			'reference_image_id' => 'absint',
			'character_role'     => 'sanitize_text_field',
			'character_age'      => 'sanitize_text_field',
			'character_gender'   => 'sanitize_text_field',
		);

		foreach ( $fields as $field => $sanitize_callback ) {
			if ( isset( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via call_user_func().
				update_post_meta( $post_id, '_' . $field, call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['ref_image'] = __( 'Ref. Image', 'mcp-ai-wpoos-pro' );
				$new_columns['role']      = __( 'Role', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.0.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'ref_image':
				$image_id = get_post_meta( $post_id, '_reference_image_id', true );
				if ( $image_id ) {
					echo wp_get_attachment_image( $image_id, array( 40, 40 ) );
				} else {
					echo '<span class="dashicons dashicons-admin-users" style="color: #ccc;"></span>';
				}
				break;
			case 'role':
				$role = get_post_meta( $post_id, '_character_role', true );
				echo $role ? esc_html( ucfirst( $role ) ) : '—';
				break;
		}
	}
}
