<?php
/**
 * Comic Panel Custom Post Type for managing individual comic panels.
 *
 * Each panel belongs to a comic and stores: description, dialogue,
 * camera angle, generated image reference, speech bubble positions,
 * and grid layout coordinates.
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
 * Registers and manages the Comic Panel custom post type.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Comic_Panel_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_comic_panel';

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
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_panel_meta' ), 5, 2 );
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
			'name'               => _x( 'Panels', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'      => _x( 'Panel', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'          => _x( 'Panels', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'add_new'            => __( 'Add Panel', 'mcp-ai-wpoos-pro' ),
			'add_new_item'       => __( 'Add New Panel', 'mcp-ai-wpoos-pro' ),
			'edit_item'          => __( 'Edit Panel', 'mcp-ai-wpoos-pro' ),
			'view_item'          => __( 'View Panel', 'mcp-ai-wpoos-pro' ),
			'all_items'          => __( 'All Panels', 'mcp-ai-wpoos-pro' ),
			'search_items'       => __( 'Search Panels', 'mcp-ai-wpoos-pro' ),
			'not_found'          => __( 'No panels found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash' => __( 'No panels found in Trash.', 'mcp-ai-wpoos-pro' ),
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
			'comic_panel_config',
			__( 'Panel Configuration', 'mcp-ai-wpoos-pro' ),
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
		wp_nonce_field( 'comic_panel_meta', 'comic_panel_meta_nonce' );

		$comic_id       = get_post_meta( $post->ID, '_comic_id', true );
		$page_number    = get_post_meta( $post->ID, '_page_number', true );
		$panel_number   = get_post_meta( $post->ID, '_panel_number', true );
		$description    = get_post_meta( $post->ID, '_panel_description', true );
		$dialogue       = get_post_meta( $post->ID, '_dialogue', true );
		$camera_angle   = get_post_meta( $post->ID, '_camera_angle', true );
		$generated_image = get_post_meta( $post->ID, '_generated_image_id', true );
		$layout_grid    = get_post_meta( $post->ID, '_layout_grid', true );
		$speech_bubbles = get_post_meta( $post->ID, '_speech_bubbles', true );

		// Get available comics for dropdown.
		$comics = get_posts(
			array(
				'post_type'      => 'mcp_ai_comic',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="comic_id"><?php esc_html_e( 'Parent Comic', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="comic_id" id="comic_id" class="regular-text">
						<option value=""><?php esc_html_e( '-- Select Comic --', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $comics as $comic ) : ?>
							<option value="<?php echo esc_attr( $comic->ID ); ?>" <?php selected( $comic_id, $comic->ID ); ?>>
								<?php echo esc_html( $comic->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The comic this panel belongs to', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="page_number"><?php esc_html_e( 'Page Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" name="page_number" id="page_number" value="<?php echo esc_attr( $page_number ); ?>" min="1" step="1" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="panel_number"><?php esc_html_e( 'Panel Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" name="panel_number" id="panel_number" value="<?php echo esc_attr( $panel_number ); ?>" min="1" step="1" class="small-text" />
					<p class="description"><?php esc_html_e( 'Order within the page', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="panel_description"><?php esc_html_e( 'Panel Description', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="panel_description" id="panel_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
					<p class="description"><?php esc_html_e( 'AI prompt describing what this panel should depict', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dialogue"><?php esc_html_e( 'Dialogue / Caption', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="dialogue" id="dialogue" rows="2" class="large-text"><?php echo esc_textarea( $dialogue ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Text that appears in speech bubbles or captions', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="camera_angle"><?php esc_html_e( 'Camera Angle', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="camera_angle" id="camera_angle" class="regular-text">
						<option value="" <?php selected( $camera_angle, '' ); ?>><?php esc_html_e( '-- Default --', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="wide" <?php selected( $camera_angle, 'wide' ); ?>><?php esc_html_e( 'Wide Shot', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="medium" <?php selected( $camera_angle, 'medium' ); ?>><?php esc_html_e( 'Medium Shot', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="close-up" <?php selected( $camera_angle, 'close-up' ); ?>><?php esc_html_e( 'Close-Up', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="extreme-close-up" <?php selected( $camera_angle, 'extreme-close-up' ); ?>><?php esc_html_e( 'Extreme Close-Up', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="low-angle" <?php selected( $camera_angle, 'low-angle' ); ?>><?php esc_html_e( 'Low Angle', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="high-angle" <?php selected( $camera_angle, 'high-angle' ); ?>><?php esc_html_e( 'High Angle', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="dutch" <?php selected( $camera_angle, 'dutch' ); ?>><?php esc_html_e( 'Dutch Angle', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="pov" <?php selected( $camera_angle, 'pov' ); ?>><?php esc_html_e( 'POV Shot', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="over-shoulder" <?php selected( $camera_angle, 'over-shoulder' ); ?>><?php esc_html_e( 'Over-the-Shoulder', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="aerial" <?php selected( $camera_angle, 'aerial' ); ?>><?php esc_html_e( 'Aerial / Bird\'s Eye', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="generated_image_id"><?php esc_html_e( 'Generated Image', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<?php if ( $generated_image_id ) : ?>
						<div style="max-width:300px; margin-bottom:8px;">
							<?php echo wp_get_attachment_image( $generated_image_id, 'medium' ); ?>
						</div>
					<?php endif; ?>
					<input type="number" name="generated_image_id" id="generated_image_id" value="<?php echo esc_attr( $generated_image_id ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Attachment ID of the AI-generated panel image', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="layout_grid"><?php esc_html_e( 'Layout Grid', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="layout_grid" id="layout_grid" value="<?php echo esc_attr( $layout_grid ); ?>" class="regular-text" placeholder="row,col,span (e.g. 1,1,2)" />
					<p class="description"><?php esc_html_e( 'Grid position: row, column, colspan (comma-separated)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="speech_bubbles"><?php esc_html_e( 'Speech Bubbles (JSON)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="speech_bubbles" id="speech_bubbles" rows="4" class="large-text code"><?php echo esc_textarea( $speech_bubbles ); ?></textarea>
					<p class="description"><?php esc_html_e( 'JSON array of bubble objects: [{text, x, y, w, h, speaker, style}]', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save panel metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_panel_meta( $post_id, $post ) {
		if ( ! isset( $_POST['comic_panel_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['comic_panel_meta_nonce'] ) ), 'comic_panel_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'comic_id'           => 'absint',
			'page_number'        => 'absint',
			'panel_number'       => 'absint',
			'panel_description'  => 'sanitize_textarea_field',
			'dialogue'           => 'sanitize_textarea_field',
			'camera_angle'       => 'sanitize_text_field',
			'generated_image_id' => 'absint',
			'layout_grid'        => 'sanitize_text_field',
			'speech_bubbles'     => 'sanitize_textarea_field',
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
				$new_columns['comic']       = __( 'Comic', 'mcp-ai-wpoos-pro' );
				$new_columns['page_panel']  = __( 'Page:Panel', 'mcp-ai-wpoos-pro' );
				$new_columns['has_image']   = __( 'Image', 'mcp-ai-wpoos-pro' );
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
			case 'comic':
				$comic_id = get_post_meta( $post_id, '_comic_id', true );
				if ( $comic_id ) {
					$comic = get_post( $comic_id );
					echo $comic ? esc_html( $comic->post_title ) : '—';
				} else {
					echo '—';
				}
				break;
			case 'page_panel':
				$page  = get_post_meta( $post_id, '_page_number', true );
				$panel = get_post_meta( $post_id, '_panel_number', true );
				echo esc_html( $page ?: '?' ) . ':' . esc_html( $panel ?: '?' );
				break;
			case 'has_image':
				$image_id = get_post_meta( $post_id, '_generated_image_id', true );
				if ( $image_id ) {
					echo '<span class="dashicons dashicons-format-image" style="color: green;" title="' . esc_attr__( 'Image generated', 'mcp-ai-wpoos-pro' ) . '"></span>';
				} else {
					echo '<span class="dashicons dashicons-format-image" style="color: #ccc;" title="' . esc_attr__( 'No image yet', 'mcp-ai-wpoos-pro' ) . '"></span>';
				}
				break;
		}
	}
}
