<?php
/**
 * PM Admin Columns (ecosystem port — Wave F2, PM admin slice A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-project-management-admin-columns.php` (or `research-add/`) for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain\n * `nvoos-content-graph-pro`; no path constants — no path swaps.
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
 * Handles custom admin columns for project, task, and event post types.
 */
class WP_MCP_AI_Project_Management_Admin_Columns {

	/**
	 * Initialize admin columns.
	 */
	public static function init() {
		// Project columns.
		add_filter( 'manage_mcp_ai_project_posts_columns', array( __CLASS__, 'project_columns' ) );
		add_action( 'manage_mcp_ai_project_posts_custom_column', array( __CLASS__, 'project_column_content' ), 10, 2 );
		add_filter( 'manage_edit-mcp_ai_project_sortable_columns', array( __CLASS__, 'project_sortable_columns' ) );

		// Task columns.
		add_filter( 'manage_mcp_ai_task_posts_columns', array( __CLASS__, 'task_columns' ) );
		add_action( 'manage_mcp_ai_task_posts_custom_column', array( __CLASS__, 'task_column_content' ), 10, 2 );
		add_filter( 'manage_edit-mcp_ai_task_sortable_columns', array( __CLASS__, 'task_sortable_columns' ) );

		// Event columns.
		add_filter( 'manage_mcp_ai_event_posts_columns', array( __CLASS__, 'event_columns' ) );
		add_action( 'manage_mcp_ai_event_posts_custom_column', array( __CLASS__, 'event_column_content' ), 10, 2 );
		add_filter( 'manage_edit-mcp_ai_event_sortable_columns', array( __CLASS__, 'event_sortable_columns' ) );

		// Handle custom sorting.
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_custom_sorting' ) );
	}

	/**
	 * Define columns for projects.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function project_columns( $columns ) {
		$new_columns = array(
			'cb'          => $columns['cb'],
			'title'       => $columns['title'],
			'status'      => __( 'Status', 'nvoos-content-graph-pro' ),
			'start_date'  => __( 'Start Date', 'nvoos-content-graph-pro' ),
			'end_date'    => __( 'End Date', 'nvoos-content-graph-pro' ),
			'assigned_to' => __( 'Team Members', 'nvoos-content-graph-pro' ),
			'author'      => $columns['author'],
			'date'        => $columns['date'],
		);
		return $new_columns;
	}

	/**
	 * Display content for project columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function project_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'status':
				$status = get_post_meta( $post_id, '_project_status', true );
				if ( ! empty( $status ) ) {
					$status_labels = array(
						'planning'  => __( 'Planning', 'nvoos-content-graph-pro' ),
						'active'    => __( 'Active', 'nvoos-content-graph-pro' ),
						'on-hold'   => __( 'On Hold', 'nvoos-content-graph-pro' ),
						'completed' => __( 'Completed', 'nvoos-content-graph-pro' ),
						'cancelled' => __( 'Cancelled', 'nvoos-content-graph-pro' ),
					);
					$label         = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
					printf( '<span class="wp-mcp-ai-status wp-mcp-ai-status-%s">%s</span>', esc_attr( $status ), esc_html( $label ) );
				} else {
					echo '—';
				}
				break;

			case 'start_date':
				$date = get_post_meta( $post_id, '_project_start_date', true );
				echo $date ? esc_html( $date ) : '—';
				break;

			case 'end_date':
				$date = get_post_meta( $post_id, '_project_end_date', true );
				echo $date ? esc_html( $date ) : '—';
				break;

			case 'assigned_to':
				$user_ids = get_post_meta( $post_id, '_project_assigned_to', true );
				if ( is_array( $user_ids ) && ! empty( $user_ids ) ) {
					$users = array();
					foreach ( $user_ids as $user_id ) {
						$user = get_user_by( 'id', $user_id );
						if ( $user ) {
							$users[] = esc_html( $user->display_name );
						}
					}
					echo implode( ', ', $users ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each element is pre-escaped with esc_html() above.
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Define sortable columns for projects.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function project_sortable_columns( $columns ) {
		$columns['status']     = 'status';
		$columns['start_date'] = 'start_date';
		$columns['end_date']   = 'end_date';
		return $columns;
	}

	/**
	 * Define columns for tasks.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function task_columns( $columns ) {
		$new_columns = array(
			'cb'          => $columns['cb'],
			'title'       => $columns['title'],
			'status'      => __( 'Status', 'nvoos-content-graph-pro' ),
			'priority'    => __( 'Priority', 'nvoos-content-graph-pro' ),
			'project'     => __( 'Project', 'nvoos-content-graph-pro' ),
			'due_date'    => __( 'Due Date', 'nvoos-content-graph-pro' ),
			'assigned_to' => __( 'Assigned To', 'nvoos-content-graph-pro' ),
			'author'      => $columns['author'],
			'date'        => $columns['date'],
		);
		return $new_columns;
	}

	/**
	 * Display content for task columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function task_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'status':
				$status = get_post_meta( $post_id, '_task_status', true );
				if ( ! empty( $status ) ) {
					$status_labels = array(
						'todo'        => __( 'To Do', 'nvoos-content-graph-pro' ),
						'in-progress' => __( 'In Progress', 'nvoos-content-graph-pro' ),
						'review'      => __( 'Review', 'nvoos-content-graph-pro' ),
						'completed'   => __( 'Completed', 'nvoos-content-graph-pro' ),
						'cancelled'   => __( 'Cancelled', 'nvoos-content-graph-pro' ),
					);
					$label         = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
					printf( '<span class="wp-mcp-ai-status wp-mcp-ai-status-%s">%s</span>', esc_attr( $status ), esc_html( $label ) );
				} else {
					echo '—';
				}
				break;

			case 'priority':
				$priority = get_post_meta( $post_id, '_task_priority', true );
				if ( ! empty( $priority ) ) {
					$priority_labels = array(
						'low'    => __( 'Low', 'nvoos-content-graph-pro' ),
						'medium' => __( 'Medium', 'nvoos-content-graph-pro' ),
						'high'   => __( 'High', 'nvoos-content-graph-pro' ),
						'urgent' => __( 'Urgent', 'nvoos-content-graph-pro' ),
					);
					$label           = isset( $priority_labels[ $priority ] ) ? $priority_labels[ $priority ] : ucfirst( $priority );
					printf( '<span class="wp-mcp-ai-priority wp-mcp-ai-priority-%s">%s</span>', esc_attr( $priority ), esc_html( $label ) );
				} else {
					echo '—';
				}
				break;

			case 'project':
				$project_id = get_post_meta( $post_id, '_task_project_id', true );
				if ( $project_id ) {
					$project = get_post( $project_id );
					if ( $project ) {
						printf(
							'<a href="%s">%s</a>',
							esc_url( get_edit_post_link( $project_id ) ),
							esc_html( $project->post_title )
						);
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;

			case 'due_date':
				$date = get_post_meta( $post_id, '_task_due_date', true );
				if ( $date ) {
					$is_overdue = strtotime( $date ) < strtotime( 'today' );
					printf(
						'<span class="%s">%s</span>',
						$is_overdue ? 'wp-mcp-ai-overdue' : '',
						esc_html( $date )
					);
				} else {
					echo '—';
				}
				break;

			case 'assigned_to':
				$user_id = get_post_meta( $post_id, '_task_assigned_to', true );
				if ( $user_id ) {
					$user = get_user_by( 'id', $user_id );
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
	 * Define sortable columns for tasks.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function task_sortable_columns( $columns ) {
		$columns['status']   = 'status';
		$columns['priority'] = 'priority';
		$columns['due_date'] = 'due_date';
		return $columns;
	}

	/**
	 * Define columns for events.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function event_columns( $columns ) {
		$new_columns = array(
			'cb'         => $columns['cb'],
			'title'      => $columns['title'],
			'type'       => __( 'Type', 'nvoos-content-graph-pro' ),
			'start_date' => __( 'Start Date', 'nvoos-content-graph-pro' ),
			'end_date'   => __( 'End Date', 'nvoos-content-graph-pro' ),
			'location'   => __( 'Location', 'nvoos-content-graph-pro' ),
			'project'    => __( 'Project', 'nvoos-content-graph-pro' ),
			'author'     => $columns['author'],
			'date'       => $columns['date'],
		);
		return $new_columns;
	}

	/**
	 * Display content for event columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function event_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'type':
				$type = get_post_meta( $post_id, '_event_type', true );
				if ( ! empty( $type ) ) {
					$type_labels = array(
						'meeting'   => __( 'Meeting', 'nvoos-content-graph-pro' ),
						'deadline'  => __( 'Deadline', 'nvoos-content-graph-pro' ),
						'milestone' => __( 'Milestone', 'nvoos-content-graph-pro' ),
						'reminder'  => __( 'Reminder', 'nvoos-content-graph-pro' ),
						'other'     => __( 'Other', 'nvoos-content-graph-pro' ),
					);
					$label       = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ucfirst( $type );
					printf( '<span class="wp-mcp-ai-event-type wp-mcp-ai-event-type-%s">%s</span>', esc_attr( $type ), esc_html( $label ) );
				} else {
					echo '—';
				}
				break;

			case 'start_date':
				$date    = get_post_meta( $post_id, '_event_start_date', true );
				$time    = get_post_meta( $post_id, '_event_start_time', true );
				$all_day = get_post_meta( $post_id, '_event_all_day', true );

				if ( $date ) {
					echo esc_html( $date );
					if ( $time && '1' !== $all_day ) {
						echo ' ' . esc_html( $time );
					}
					if ( '1' === $all_day ) {
						echo ' <em>(' . esc_html__( 'All day', 'nvoos-content-graph-pro' ) . ')</em>';
					}
				} else {
					echo '—';
				}
				break;

			case 'end_date':
				$date    = get_post_meta( $post_id, '_event_end_date', true );
				$time    = get_post_meta( $post_id, '_event_end_time', true );
				$all_day = get_post_meta( $post_id, '_event_all_day', true );

				if ( $date ) {
					echo esc_html( $date );
					if ( $time && '1' !== $all_day ) {
						echo ' ' . esc_html( $time );
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
					if ( $project ) {
						printf(
							'<a href="%s">%s</a>',
							esc_url( get_edit_post_link( $project_id ) ),
							esc_html( $project->post_title )
						);
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
	 * Define sortable columns for events.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function event_sortable_columns( $columns ) {
		$columns['type']       = 'type';
		$columns['start_date'] = 'start_date';
		$columns['end_date']   = 'end_date';
		return $columns;
	}

	/**
	 * Handle custom column sorting.
	 *
	 * @param WP_Query $query The query object.
	 */
	public static function handle_custom_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		// Project sorting.
		if ( 'mcp_ai_project' === $query->get( 'post_type' ) ) {
			if ( 'status' === $orderby ) {
				$query->set( 'meta_key', '_project_status' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'start_date' === $orderby ) {
				$query->set( 'meta_key', '_project_start_date' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'end_date' === $orderby ) {
				$query->set( 'meta_key', '_project_end_date' );
				$query->set( 'orderby', 'meta_value' );
			}
		}

		// Task sorting.
		if ( 'mcp_ai_task' === $query->get( 'post_type' ) ) {
			if ( 'status' === $orderby ) {
				$query->set( 'meta_key', '_task_status' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'priority' === $orderby ) {
				$query->set( 'meta_key', '_task_priority' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'due_date' === $orderby ) {
				$query->set( 'meta_key', '_task_due_date' );
				$query->set( 'orderby', 'meta_value' );
			}
		}

		// Event sorting.
		if ( 'mcp_ai_event' === $query->get( 'post_type' ) ) {
			if ( 'type' === $orderby ) {
				$query->set( 'meta_key', '_event_type' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'start_date' === $orderby ) {
				$query->set( 'meta_key', '_event_start_date' );
				$query->set( 'orderby', 'meta_value' );
			} elseif ( 'end_date' === $orderby ) {
				$query->set( 'meta_key', '_event_end_date' );
				$query->set( 'orderby', 'meta_value' );
			}
		}
	}
}
