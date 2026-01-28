<?php
/**
 * Appointment Custom Post Type for managing calendar bookings.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Appointment custom post type.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Appointment_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_appointment';

	/**
	 * Metabox instances.
	 *
	 * @var array
	 */
	protected static $metaboxes = array();

	/**
	 * Initialize the class.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if calendar booking toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_appointment_meta' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when calendar booking toolkit is disabled.
	 *
	 * @since 2.6.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type           = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_appointment_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_appointment_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Calendar Booking Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Calendar Booking Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Calendar Booking Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
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
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Calendar Booking Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Calendar Booking Toolkit is currently disabled. Enable it to create and manage appointments.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Calendar Booking Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Calendar Booking Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
	 *
	 * @since 2.6.0
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-appointment-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-appointment-metabox-details.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-appointment-metabox-client.php';

		// Initialize metabox instances.
		self::$metaboxes['details'] = new WP_MCP_AI_Appointment_Metabox_Details();
		self::$metaboxes['client']  = new WP_MCP_AI_Appointment_Metabox_Client();
	}

	/**
	 * Register meta boxes for appointment editing.
	 *
	 * @since 2.6.0
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on appointment edit screen.
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
	 * Save appointment meta data from metaboxes.
	 *
	 * @since 2.6.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_appointment_meta( $post_id, $post ) {
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
	 * Show informational notice on appointment edit screen.
	 *
	 * @since 2.6.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on appointment edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info appointment-info-notice">
			<p>
				<strong><?php esc_html_e( 'Appointment Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Appointments can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the metaboxes below to add client information, appointment details, and scheduling information.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and update appointments using the <code>create_appointment</code> tool, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Appointment custom post type.
	 *
	 * @since 2.6.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Appointments', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Appointment', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Appointments', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Appointment', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'appointment', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Appointment', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Appointment', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Appointment', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Appointment', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Appointments', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Appointments', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Appointments:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No appointments found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No appointments found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Calendar appointments and bookings.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-calendar-alt',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.6.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		// Remove date column, we'll add a custom one.
		unset( $columns['date'] );

		// Add custom columns.
		$new_columns = array(
			'client_name'      => __( 'Client Name', 'mcp-ai-wpoos-pro' ),
			'appointment_type' => __( 'Type', 'mcp-ai-wpoos-pro' ),
			'datetime'         => __( 'Date & Time', 'mcp-ai-wpoos-pro' ),
			'duration'         => __( 'Duration', 'mcp-ai-wpoos-pro' ),
			'status'           => __( 'Status', 'mcp-ai-wpoos-pro' ),
		);

		// Insert after title.
		$position = array_search( 'title', array_keys( $columns ), true ) + 1;
		$columns  = array_slice( $columns, 0, $position, true ) + $new_columns + array_slice( $columns, $position, null, true );

		return $columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.6.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'client_name':
				$client_name = get_post_meta( $post_id, '_client_name', true );
				echo esc_html( $client_name ? $client_name : '—' );
				break;

			case 'appointment_type':
				$type = get_post_meta( $post_id, '_appointment_type', true );
				if ( $type ) {
					echo '<span class="appointment-type">' . esc_html( ucfirst( $type ) ) . '</span>';
				} else {
					echo '—';
				}
				break;

			case 'datetime':
				$start_time = get_post_meta( $post_id, '_start_time', true );
				if ( $start_time ) {
					$datetime = strtotime( $start_time );
					if ( $datetime ) {
						echo '<strong>' . esc_html( date_i18n( 'M j, Y', $datetime ) ) . '</strong><br>';
						echo esc_html( date_i18n( 'g:i A', $datetime ) );
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'duration':
				$start_time = get_post_meta( $post_id, '_start_time', true );
				$end_time   = get_post_meta( $post_id, '_end_time', true );
				if ( $start_time && $end_time ) {
					$start    = strtotime( $start_time );
					$end      = strtotime( $end_time );
					$duration = ( $end - $start ) / 60; // Minutes.
					if ( $duration > 0 ) {
						if ( $duration >= 60 ) {
							$hours   = floor( $duration / 60 );
							$minutes = $duration % 60;
							if ( $minutes > 0 ) {
								/* translators: 1: hours, 2: minutes */
								echo esc_html( sprintf( __( '%1$dh %2$dm', 'mcp-ai-wpoos-pro' ), $hours, $minutes ) );
							} else {
								/* translators: %d: hours */
								echo esc_html( sprintf( __( '%dh', 'mcp-ai-wpoos-pro' ), $hours ) );
							}
						} else {
							/* translators: %d: minutes */
							echo esc_html( sprintf( __( '%d min', 'mcp-ai-wpoos-pro' ), $duration ) );
						}
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'status':
				$status = get_post_meta( $post_id, '_status', true );
				if ( $status ) {
					$status_classes = array(
						'scheduled'   => 'scheduled',
						'confirmed'   => 'confirmed',
						'completed'   => 'completed',
						'cancelled'   => 'cancelled',
						'no-show'     => 'no-show',
						'rescheduled' => 'rescheduled',
					);
					$status_class   = isset( $status_classes[ $status ] ) ? $status_classes[ $status ] : 'default';
					echo '<span class="appointment-status status-' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( str_replace( '-', ' ', $status ) ) ) . '</span>';
				} else {
					echo '<span class="appointment-status status-scheduled">' . esc_html__( 'Scheduled', 'mcp-ai-wpoos-pro' ) . '</span>';
				}
				break;
		}
	}

	/**
	 * Make custom columns sortable.
	 *
	 * @since 2.6.0
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['client_name']      = 'client_name';
		$columns['appointment_type'] = 'appointment_type';
		$columns['datetime']         = 'datetime';
		$columns['status']           = 'status';

		return $columns;
	}
}

WP_MCP_AI_Appointment_CPT::init();
