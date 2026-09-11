<?php
/**
 * Task CPT (ecosystem port — Wave F2, PM toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/class-wp-mcp-ai-task-cpt.php` (or
 * `addons/pro/includes/`) for the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Task custom post type.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Task_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_task';

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
		$post_type    = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_task_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_task_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Project Management Toolkit Not Available', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Project Management Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'nvoos-content-graph-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Project Management Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'nvoos-content-graph-pro' ),
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
					<strong><?php esc_html_e( 'Project Management Toolkit Disabled', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Project Management Toolkit is currently disabled. Enable it to create and manage tasks.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Project Management Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Project Management Toolkit"</strong>, and save your changes.', 'nvoos-content-graph-pro' ),
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
	 * Show informational notice on task edit screen.
	 *
	 * @since 2.7.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on task edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info task-info-notice">
			<p>
				<strong><?php esc_html_e( 'Task Management', 'nvoos-content-graph-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Tasks can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'nvoos-content-graph-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the metaboxes below to add task details, status, priority, due dates, and assignments.', 'nvoos-content-graph-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and update tasks using the <code>create_task</code> tool, and you can edit them here afterwards.', 'nvoos-content-graph-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Task custom post type.
	 *
	 * @since 2.7.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Tasks', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Task', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Tasks', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Task', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'task', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Task', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Task', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Task', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Task', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'All Tasks', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Tasks', 'nvoos-content-graph-pro' ),
					'parent_item_colon'  => __( 'Parent Tasks:', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No tasks found.', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No tasks found in Trash.', 'nvoos-content-graph-pro' ),
				),
				'description'        => __( 'Task management and tracking.', 'nvoos-content-graph-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-editor-ol-rtl',
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
			'status'      => __( 'Status', 'nvoos-content-graph-pro' ),
			'priority'    => __( 'Priority', 'nvoos-content-graph-pro' ),
			'due_date'    => __( 'Due Date', 'nvoos-content-graph-pro' ),
			'project'     => __( 'Project', 'nvoos-content-graph-pro' ),
			'assigned_to' => __( 'Assigned To', 'nvoos-content-graph-pro' ),
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
				$status = get_post_meta( $post_id, '_task_status', true );
				if ( $status ) {
					$status_classes = array(
						'todo'        => 'todo',
						'in-progress' => 'in-progress',
						'review'      => 'review',
						'completed'   => 'completed',
						'cancelled'   => 'cancelled',
					);
					$status_class   = isset( $status_classes[ $status ] ) ? $status_classes[ $status ] : 'default';
					echo '<span class="task-status status-' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( str_replace( '-', ' ', $status ) ) ) . '</span>';
				} else {
					echo '<span class="task-status status-todo">' . esc_html__( 'To Do', 'nvoos-content-graph-pro' ) . '</span>';
				}
				break;

			case 'priority':
				$priority = get_post_meta( $post_id, '_task_priority', true );
				if ( $priority ) {
					$priority_classes = array(
						'low'    => 'low',
						'medium' => 'medium',
						'high'   => 'high',
						'urgent' => 'urgent',
					);
					$priority_class   = isset( $priority_classes[ $priority ] ) ? $priority_classes[ $priority ] : 'default';
					echo '<span class="task-priority priority-' . esc_attr( $priority_class ) . '">' . esc_html( ucfirst( $priority ) ) . '</span>';
				} else {
					echo '<span class="task-priority priority-medium">' . esc_html__( 'Medium', 'nvoos-content-graph-pro' ) . '</span>';
				}
				break;

			case 'due_date':
				$due_date = get_post_meta( $post_id, '_task_due_date', true );
				if ( $due_date ) {
					$datetime = strtotime( $due_date );
					if ( $datetime ) {
						echo esc_html( date_i18n( 'M j, Y', $datetime ) );
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'project':
				$project_id = get_post_meta( $post_id, '_task_project_id', true );
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

			case 'assigned_to':
				$assigned_to = get_post_meta( $post_id, '_task_assigned_to', true );
				if ( $assigned_to ) {
					$user = get_userdata( $assigned_to );
					if ( $user ) {
						echo esc_html( $user->display_name );
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
		$columns['status']   = 'status';
		$columns['priority'] = 'priority';
		$columns['due_date'] = 'due_date';

		return $columns;
	}
}

WP_MCP_AI_Task_CPT::init();
