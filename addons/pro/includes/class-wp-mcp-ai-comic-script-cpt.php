<?php
/**
 * Comic Script Custom Post Type for managing comic scripts and scene breakdowns.
 *
 * Stores the full script text along with a structured scene/POST breakdown
 * that maps to individual panels. Used by the AI panel generation pipeline.
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
 * Registers and manages the Comic Script custom post type.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Comic_Script_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_comic_script';

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
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_script_meta' ), 5, 2 );
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
			'name'               => _x( 'Scripts', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'      => _x( 'Script', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'          => _x( 'Scripts', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'add_new'            => __( 'Add Script', 'mcp-ai-wpoos-pro' ),
			'add_new_item'       => __( 'Add New Script', 'mcp-ai-wpoos-pro' ),
			'edit_item'          => __( 'Edit Script', 'mcp-ai-wpoos-pro' ),
			'view_item'          => __( 'View Script', 'mcp-ai-wpoos-pro' ),
			'all_items'          => __( 'All Scripts', 'mcp-ai-wpoos-pro' ),
			'search_items'       => __( 'Search Scripts', 'mcp-ai-wpoos-pro' ),
			'not_found'          => __( 'No scripts found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash' => __( 'No scripts found in Trash.', 'mcp-ai-wpoos-pro' ),
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
			'supports'           => array( 'title', 'editor', 'author', 'custom-fields' ),
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
			'comic_script_config',
			__( 'Script Configuration', 'mcp-ai-wpoos-pro' ),
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
		wp_nonce_field( 'comic_script_meta', 'comic_script_meta_nonce' );

		$comic_id   = get_post_meta( $post->ID, '_comic_id', true );
		$scenes_json = get_post_meta( $post->ID, '_scenes_json', true );
		$genre      = get_post_meta( $post->ID, '_genre', true );
		$premise    = get_post_meta( $post->ID, '_premise', true );
		$panel_count = get_post_meta( $post->ID, '_panel_count', true );

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
				<th><label for="comic_id"><?php esc_html_e( 'Linked Comic', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="comic_id" id="comic_id" class="regular-text">
						<option value=""><?php esc_html_e( '-- None --', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $comics as $comic ) : ?>
							<option value="<?php echo esc_attr( $comic->ID ); ?>" <?php selected( $comic_id, $comic->ID ); ?>>
								<?php echo esc_html( $comic->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="genre"><?php esc_html_e( 'Genre', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="genre" id="genre" value="<?php echo esc_attr( $genre ); ?>" class="regular-text" placeholder="e.g., sci-fi, fantasy, noir, superhero" />
				</td>
			</tr>
			<tr>
				<th><label for="premise"><?php esc_html_e( 'Premise / Summary', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="premise" id="premise" rows="3" class="large-text"><?php echo esc_textarea( $premise ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Brief story summary used by AI to maintain narrative consistency', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="panel_count"><?php esc_html_e( 'Panel Count', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" name="panel_count" id="panel_count" value="<?php echo esc_attr( $panel_count ); ?>" min="1" step="1" class="small-text" />
					<p class="description"><?php esc_html_e( 'Total number of panels in this script', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="scenes_json"><?php esc_html_e( 'Scene Breakdown (JSON)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="scenes_json" id="scenes_json" rows="10" class="large-text code"><?php echo esc_textarea( $scenes_json ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'JSON array of scenes, each with panels. Each panel: { "number": 1, "page": 1, "description": "...", "dialogue": "...", "camera": "wide" }', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save script metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_script_meta( $post_id, $post ) {
		if ( ! isset( $_POST['comic_script_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['comic_script_meta_nonce'] ) ), 'comic_script_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'comic_id'    => 'absint',
			'scenes_json' => 'sanitize_textarea_field',
			'genre'       => 'sanitize_text_field',
			'premise'     => 'sanitize_textarea_field',
			'panel_count' => 'absint',
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
				$new_columns['comic']  = __( 'Comic', 'mcp-ai-wpoos-pro' );
				$new_columns['genre']  = __( 'Genre', 'mcp-ai-wpoos-pro' );
				$new_columns['panels'] = __( 'Panels', 'mcp-ai-wpoos-pro' );
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
			case 'genre':
				$genre = get_post_meta( $post_id, '_genre', true );
				echo $genre ? esc_html( $genre ) : '—';
				break;
			case 'panels':
				$count = get_post_meta( $post_id, '_panel_count', true );
				echo $count ? esc_html( $count ) : '—';
				break;
		}
	}
}
