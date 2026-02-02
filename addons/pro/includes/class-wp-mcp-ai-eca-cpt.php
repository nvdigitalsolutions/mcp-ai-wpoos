<?php
/**
 * ECA Custom Post Type for managing Extra-Curricular Activities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the ECA custom post type.
 */
class WP_MCP_AI_ECA_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_eca';

	/**
	 * Student post type slug.
	 *
	 * @var string
	 */
	const STUDENT_POST_TYPE = 'mcp_ai_student';

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
			// Still show notice if accessing ECA pages.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			// Show notice if trying to access ECA pages when disabled.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_eca_meta' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when ECA management is disabled but user tries to access ECA pages.
	 */
	public static function show_disabled_notice() {
		// Only show on ECA-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on an ECA or student post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_eca_page = ( self::POST_TYPE === $post_type || self::STUDENT_POST_TYPE === $post_type );
		if ( ! $is_eca_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'ECA Management Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The ECA Management System is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the ECA Management System, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
							'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'ECA Management Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The ECA Management System is currently disabled. Enable it to create and manage Extra-Curricular Activities.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the ECA Management System, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable ECA Management"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
	 * Load metabox classes.
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-eca-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-eca-metabox-details.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-eca-metabox-schedule.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-eca-metabox-enrollment.php';

		// Initialize metabox instances.
		self::$metaboxes['details']    = new WP_MCP_AI_ECA_Metabox_Details();
		self::$metaboxes['schedule']   = new WP_MCP_AI_ECA_Metabox_Schedule();
		self::$metaboxes['enrollment'] = new WP_MCP_AI_ECA_Metabox_Enrollment();
	}

	/**
	 * Register meta boxes for ECA editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on ECA edit screen.
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Register each metabox.
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
	 * Save ECA meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_eca_meta( $post_id, $post ) {
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check post type.
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call save method on each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			$metabox->save( $post_id, $post );
		}
	}

	/**
	 * Show informational notice on ECA edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on ECA edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info eca-info-notice">
			<p>
				<strong><?php esc_html_e( 'ECA Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Extra-Curricular Activities (ECAs) can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the editor below to add a description, and the metaboxes to configure schedule, enrollment, and other details.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create ECAs using the <code>create_eca</code> tool, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles for ECA metaboxes.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_scripts( $hook ) {
		// Only load on ECA edit screen.
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Enqueue inline script for paid activity toggle.
		$inline_script = "
		jQuery(document).ready(function($) {
			$('#wp_mcp_ai_eca_is_paid').on('change', function() {
				if ($(this).is(':checked')) {
					$('#wp_mcp_ai_eca_cost_fields').show();
				} else {
					$('#wp_mcp_ai_eca_cost_fields').hide();
				}
			});
		});
		";

		wp_add_inline_script( 'jquery', $inline_script );
	}

	/**
	 * Register ECA and Student custom post types.
	 */
	public static function register_post_types() {
		// Register ECA (Extra-Curricular Activity) post type.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'ECAs', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'ECA', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'ECAs', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'ECA', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'eca', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New ECA', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New ECA', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit ECA', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View ECA', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All ECAs', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search ECAs', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent ECAs:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No ECAs found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No ECAs found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Extra-Curricular Activities for students.', 'mcp-ai-wpoos-pro' ),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-calendar-alt',
				'query_var'          => true,
				'rewrite'            => array( 'slug' => 'eca' ),
				'capability_type'    => 'post',
				'has_archive'        => true,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'custom-fields' ),
				'show_in_rest'       => true,
			)
		);

		// Register Student post type.
		register_post_type(
			self::STUDENT_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Students', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Student', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Students', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Student', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'student', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Student', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Student', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Student', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Student', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Students', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Students', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Students:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No students found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No students found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Students enrolled in Extra-Curricular Activities.', 'mcp-ai-wpoos-pro' ),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-groups',
				'query_var'          => true,
				'rewrite'            => array( 'slug' => 'student' ),
				'capability_type'    => 'post',
				'has_archive'        => true,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'custom-fields' ),
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * Register taxonomies for ECA categorization.
	 */
	public static function register_taxonomies() {
		// Register ECA Category taxonomy.
		register_taxonomy(
			'mcp_ai_eca_category',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'ECA Categories', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'ECA Category', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search ECA Categories', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All ECA Categories', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit ECA Category', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update ECA Category', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New ECA Category', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New ECA Category Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Categories', 'mcp-ai-wpoos-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'eca-category' ),
			)
		);

		// Register default ECA categories.
		$default_categories = array(
			'sports'          => __( 'Sports', 'mcp-ai-wpoos-pro' ),
			'arts-music'      => __( 'Arts & Music', 'mcp-ai-wpoos-pro' ),
			'academic'        => __( 'Academic', 'mcp-ai-wpoos-pro' ),
			'health-wellness' => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
			'technology'      => __( 'Technology', 'mcp-ai-wpoos-pro' ),
			'community'       => __( 'Community Service', 'mcp-ai-wpoos-pro' ),
			'leadership'      => __( 'Leadership', 'mcp-ai-wpoos-pro' ),
			'other'           => __( 'Other', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $default_categories as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_eca_category' ) ) {
				wp_insert_term( $name, 'mcp_ai_eca_category', array( 'slug' => $slug ) );
			}
		}
	}
}

WP_MCP_AI_ECA_CPT::init();
