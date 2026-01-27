<?php
/**
 * Event Custom Post Type for managing events.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Project_Management_Toolkit
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Event custom post type.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Event_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_event';

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
		$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_event_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_event_page ) {
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
					<?php esc_html_e( 'The Project Management Toolkit is currently disabled. Enable it to create and manage events.', 'mcp-ai-wpoos-pro' ); ?>
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
	 * Show informational notice on event edit screen.
	 *
	 * @since 2.7.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on event edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info event-info-notice">
			<p>
				<strong><?php esc_html_e( 'Event Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Events can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the metaboxes below to add event details, dates, location, and attendees.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and update events using the <code>create_event</code> tool, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Event custom post type.
	 *
	 * @since 2.7.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Events', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Event', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Events', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Event', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'event', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Event', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Event', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Event', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Event', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Events', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Events', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Events:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No events found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No events found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Event management and scheduling.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-calendar',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author' ),
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
			'start_date' => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
			'end_date'   => __( 'End Date', 'mcp-ai-wpoos-pro' ),
			'location'   => __( 'Location', 'mcp-ai-wpoos-pro' ),
			'project'    => __( 'Project', 'mcp-ai-wpoos-pro' ),
			'attendees'  => __( 'Attendees', 'mcp-ai-wpoos-pro' ),
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
			case 'start_date':
				$start_date = get_post_meta( $post_id, '_event_start_date', true );
				$start_time = get_post_meta( $post_id, '_event_start_time', true );
				if ( $start_date ) {
					$datetime = strtotime( $start_date );
					if ( $datetime ) {
						echo '<strong>' . esc_html( date_i18n( 'M j, Y', $datetime ) ) . '</strong>';
						if ( $start_time ) {
							echo '<br>' . esc_html( $start_time );
						}
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'end_date':
				$end_date = get_post_meta( $post_id, '_event_end_date', true );
				$end_time = get_post_meta( $post_id, '_event_end_time', true );
				if ( $end_date ) {
					$datetime = strtotime( $end_date );
					if ( $datetime ) {
						echo '<strong>' . esc_html( date_i18n( 'M j, Y', $datetime ) ) . '</strong>';
						if ( $end_time ) {
							echo '<br>' . esc_html( $end_time );
						}
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'location':
				$location = get_post_meta( $post_id, '_event_location', true );
				echo $location ? esc_html( $location ) : '—';
				break;

			case 'project':
				$project_id = get_post_meta( $post_id, '_event_project_id', true );
				if ( $project_id ) {
					$project = get_post( $project_id );
					if ( $project && 'mcp_ai_project' === $project->post_type ) {
						$edit_link = get_edit_post_link( $project_id );
						echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $project->post_title ) . '</a>';
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'attendees':
				$attendees = get_post_meta( $post_id, '_event_attendees', true );
				if ( is_array( $attendees ) && ! empty( $attendees ) ) {
					$user_names = array();
					foreach ( $attendees as $user_id ) {
						$user = get_userdata( $user_id );
						if ( $user ) {
							$user_names[] = $user->display_name;
						}
					}
					if ( ! empty( $user_names ) ) {
						echo esc_html( implode( ', ', array_slice( $user_names, 0, 3 ) ) );
						if ( count( $user_names ) > 3 ) {
							echo ' <em>+' . esc_html( count( $user_names ) - 3 ) . '</em>';
						}
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
		$columns['start_date'] = 'start_date';
		$columns['end_date']   = 'end_date';

		return $columns;
	}
}

WP_MCP_AI_Event_CPT::init();
