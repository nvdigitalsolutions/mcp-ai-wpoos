<?php
/**
 * Project Custom Post Type for managing projects.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Project_Management_Toolkit
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Project custom post type.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Project_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_project';

	/**
	 * Initialize the class.
	 *
	 * @since 2.7.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if project management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Show admin notice when project management is disabled.
	 *
	 * @since 2.7.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type       = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_project_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_project_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Project Management Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Project Management Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Project Management Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
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
		if ( empty( $settings['enable_project_management'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Project Management Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Project Management Toolkit is currently disabled. Enable it to create and manage projects.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Project Management Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Project Management Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
	 * Show informational notice on project edit screen.
	 *
	 * @since 2.7.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on project edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info project-info-notice">
			<p>
				<strong><?php esc_html_e( 'Project Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Projects can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the metaboxes below to add project details, status, dates, and assigned team members.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and update projects using the <code>create_project</code> tool, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Project custom post type.
	 *
	 * @since 2.7.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Projects', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Project', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Projects', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Project', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'project', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Project', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Project', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Project', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Project', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Projects', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Projects', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Projects:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No projects found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No projects found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Project management and tracking.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-portfolio',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author', 'thumbnail' ),
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.7.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		// Remove date column, we'll add a custom one.
		unset( $columns['date'] );

		// Add custom columns.
		$new_columns = array(
			'status'      => __( 'Status', 'mcp-ai-wpoos-pro' ),
			'start_date'  => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
			'end_date'    => __( 'End Date', 'mcp-ai-wpoos-pro' ),
			'assigned_to' => __( 'Assigned To', 'mcp-ai-wpoos-pro' ),
		);

		// Insert after title.
		$position = array_search( 'title', array_keys( $columns ), true ) + 1;
		$columns  = array_slice( $columns, 0, $position, true ) + $new_columns + array_slice( $columns, $position, null, true );

		return $columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.7.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'status':
				$status = get_post_meta( $post_id, '_project_status', true );
				if ( $status ) {
					$status_classes = array(
						'planning'  => 'planning',
						'active'    => 'active',
						'on-hold'   => 'on-hold',
						'completed' => 'completed',
						'cancelled' => 'cancelled',
					);
					$status_class   = isset( $status_classes[ $status ] ) ? $status_classes[ $status ] : 'default';
					echo '<span class="project-status status-' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( str_replace( '-', ' ', $status ) ) ) . '</span>';
				} else {
					echo '<span class="project-status status-planning">' . esc_html__( 'Planning', 'mcp-ai-wpoos-pro' ) . '</span>';
				}
				break;

			case 'start_date':
				$start_date = get_post_meta( $post_id, '_project_start_date', true );
				if ( $start_date ) {
					$datetime = strtotime( $start_date );
					if ( $datetime ) {
						echo esc_html( date_i18n( 'M j, Y', $datetime ) );
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'end_date':
				$end_date = get_post_meta( $post_id, '_project_end_date', true );
				if ( $end_date ) {
					$datetime = strtotime( $end_date );
					if ( $datetime ) {
						echo esc_html( date_i18n( 'M j, Y', $datetime ) );
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'assigned_to':
				$assigned_to = get_post_meta( $post_id, '_project_assigned_to', true );
				if ( is_array( $assigned_to ) && ! empty( $assigned_to ) ) {
					$user_names = array();
					foreach ( $assigned_to as $user_id ) {
						$user = get_userdata( $user_id );
						if ( $user ) {
							$user_names[] = $user->display_name;
						}
					}
					if ( ! empty( $user_names ) ) {
						echo esc_html( implode( ', ', $user_names ) );
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Make custom columns sortable.
	 *
	 * @since 2.7.0
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['status']     = 'status';
		$columns['start_date'] = 'start_date';
		$columns['end_date']   = 'end_date';

		return $columns;
	}
}

WP_MCP_AI_Project_CPT::init();
