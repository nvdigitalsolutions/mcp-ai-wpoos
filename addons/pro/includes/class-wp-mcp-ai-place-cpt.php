<?php
/**
 * Place Custom Post Type management with admin enhancements.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Place custom post type with AI tool integration.
 */
class WP_MCP_AI_Place_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_place';

	/**
	 * Metabox instances.
	 *
	 * @var array
	 */
	protected static $metaboxes = array();

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		// When Pro addon is active (WP_MCP_AI_PRO_VERSION defined), features should work even in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if places management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_places_management'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_place_meta' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Add custom columns to places list.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_custom_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_custom_columns' ), 10, 2 );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when places management is disabled.
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type    = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_place_page = ( $post_type === self::POST_TYPE );

		if ( ! $is_place_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'Places Management Not Available', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<p><?php echo wp_kses_post( __( 'The Places Management System is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' ) ); ?></p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_places_management'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools&subtab=features' );
			?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'Places Management Disabled', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<p><?php esc_html_e( 'The Places Management System is currently disabled. Enable it to create and manage places.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable Places Management, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features &rarr; Features</a>, check <strong>"Enable Places Management"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Show informational notice on place edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_places_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info place-info-notice">
			<p><strong><?php esc_html_e( 'Places Management with AI Integration', 'mcp-ai-wpoos-pro' ); ?></strong></p>
			<p><?php esc_html_e( 'Places can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the editor and metaboxes below to add location details, contact info, business hours, and amenities.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create places using <code>create_place</code>, search with <code>search_and_save_places</code>, and manage them with <code>update_place</code>, <code>delete_place</code>, and <code>list_places</code> tools.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Google Maps Integration:</strong> Places automatically geocode addresses and can be enriched with data from Google Places API.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Load metabox classes.
	 */
	protected static function load_metabox_classes() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/places/class-wp-mcp-ai-place-metabox-base.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/places/class-wp-mcp-ai-place-metabox-location.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/places/class-wp-mcp-ai-place-metabox-contact.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/places/class-wp-mcp-ai-place-metabox-details.php';

		self::$metaboxes['location'] = new WP_MCP_AI_Place_Metabox_Location();
		self::$metaboxes['contact']  = new WP_MCP_AI_Place_Metabox_Contact();
		self::$metaboxes['details']  = new WP_MCP_AI_Place_Metabox_Details();
	}

	/**
	 * Register meta boxes for place editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		foreach ( self::$metaboxes as $metabox ) {
			add_meta_box(
				$metabox->get_id(),
				$metabox->get_title(),
				array( $metabox, 'render' ),
				self::POST_TYPE,
				$metabox->get_context(),
				$metabox->get_priority()
			);
		}
	}

	/**
	 * Save place meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_place_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::$metaboxes as $metabox ) {
			$metabox->save( $post_id, $post );
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-place-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/place-admin.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}

	/**
	 * Add custom columns to places list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_custom_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'title' === $key ) {
				$new_columns['place_type']  = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new_columns['location']    = __( 'Location', 'mcp-ai-wpoos-pro' );
				$new_columns['rating']      = __( 'Rating', 'mcp-ai-wpoos-pro' );
				$new_columns['price_level'] = __( 'Price', 'mcp-ai-wpoos-pro' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'place_type':
				$types = wp_get_object_terms( $post_id, 'mcp_ai_place_type', array( 'fields' => 'names' ) );
				echo esc_html( ! empty( $types ) ? implode( ', ', $types ) : '—' );
				break;

			case 'location':
				$address = get_post_meta( $post_id, '_place_address', true );
				$city    = '';
				$components = get_post_meta( $post_id, '_place_address_components', true );
				if ( is_array( $components ) && ! empty( $components['city'] ) ) {
					$city = $components['city'];
				}
				echo esc_html( $city ? $city : ( $address ? $address : '—' ) );
				break;

			case 'rating':
				$rating = get_post_meta( $post_id, '_place_rating', true );
				if ( $rating ) {
					echo esc_html( number_format( floatval( $rating ), 1 ) . ' ★' );
				} else {
					echo '—';
				}
				break;

			case 'price_level':
				$price_level = get_post_meta( $post_id, '_place_price_level', true );
				if ( $price_level ) {
					echo esc_html( str_repeat( '$', absint( $price_level ) ) );
				} else {
					echo '—';
				}
				break;
		}
	}
}

WP_MCP_AI_Place_CPT::init();
