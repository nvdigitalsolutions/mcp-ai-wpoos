<?php
/**
 * Tool for getting calendar view.
 *
 * Provides a unified calendar view combining projects, tasks, and events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets a unified calendar view of projects, tasks, and events.
 */
class WP_MCP_AI_Tool_Get_Calendar_View implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_calendar_view';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Calendar View', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets a unified calendar view combining projects, tasks, and events within a specified date range. Perfect for displaying comprehensive schedules and timelines.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'start_date' => array(
					'type'        => 'string',
					'description' => __( 'Start date for calendar view (YYYY-MM-DD) (required)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => __( 'End date for calendar view (YYYY-MM-DD) (required)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'project_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Filter by specific project ID (optional)', 'wp-mcp-ai' ),
				),
				'user_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Filter by specific user ID (shows items assigned to or attended by user) (optional)', 'wp-mcp-ai' ),
				),
				'include_types' => array(
					'type'        => 'array',
					'description' => __( 'Types to include in calendar view (default: all)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'projects', 'tasks', 'events' ),
					),
					'default'     => array( 'projects', 'tasks', 'events' ),
				),
				'group_by_date' => array(
					'type'        => 'boolean',
					'description' => __( 'Group results by date for easier calendar rendering (default: true)', 'wp-mcp-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'start_date', 'end_date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view calendar.', 'wp-mcp-ai' ) );
		}

		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';

		if ( ! $start_date || ! $end_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_dates', __( 'Both start_date and end_date are required.', 'wp-mcp-ai' ) );
		}

		if ( ! $this->validate_date( $start_date ) || ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_dates', __( 'Invalid date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		$project_id    = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$user_id       = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;
		$include_types = isset( $arguments['include_types'] ) && is_array( $arguments['include_types'] ) 
			? $arguments['include_types'] 
			: array( 'projects', 'tasks', 'events' );
		$group_by_date = isset( $arguments['group_by_date'] ) ? (bool) $arguments['group_by_date'] : true;

		$calendar_items = array();

		// Get projects within date range.
		if ( in_array( 'projects', $include_types, true ) ) {
			$calendar_items = array_merge( $calendar_items, $this->get_projects_in_range( $start_date, $end_date, $project_id, $user_id ) );
		}

		// Get tasks within date range.
		if ( in_array( 'tasks', $include_types, true ) ) {
			$calendar_items = array_merge( $calendar_items, $this->get_tasks_in_range( $start_date, $end_date, $project_id, $user_id ) );
		}

		// Get events within date range.
		if ( in_array( 'events', $include_types, true ) ) {
			$calendar_items = array_merge( $calendar_items, $this->get_events_in_range( $start_date, $end_date, $project_id, $user_id ) );
		}

		// Sort by date.
		usort( $calendar_items, function( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );

		$result = array(
			'success'    => true,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'count'      => count( $calendar_items ),
		);

		if ( $group_by_date ) {
			// Group items by date.
			$grouped = array();
			foreach ( $calendar_items as $item ) {
				$date = $item['date'];
				if ( ! isset( $grouped[ $date ] ) ) {
					$grouped[ $date ] = array();
				}
				$grouped[ $date ][] = $item;
			}
			$result['calendar'] = $grouped;
		} else {
			$result['items'] = $calendar_items;
		}

		return $result;
	}

	/**
	 * Get projects within date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $project_id Project ID filter.
	 * @param int    $user_id    User ID filter.
	 * @return array
	 */
	private function get_projects_in_range( $start_date, $end_date, $project_id = 0, $user_id = 0 ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_project',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_project_start_date',
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_project_end_date',
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);

		if ( $project_id > 0 ) {
			$query_args['p'] = $project_id;
		}

		if ( $user_id > 0 ) {
			$query_args['meta_query'][] = array(
				'key'     => '_project_assigned_to',
				'value'   => sprintf( ':"%d";', $user_id ),
				'compare' => 'LIKE',
			);
		}

		$query = new WP_Query( $query_args );
		$items = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$project_start = get_post_meta( $id, '_project_start_date', true );
				
				$items[] = array(
					'type'       => 'project',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => $project_start ?: get_the_date( 'Y-m-d' ),
					'status'     => get_post_meta( $id, '_project_status', true ),
					'project_id' => $id,
				);
			}
			wp_reset_postdata();
		}

		return $items;
	}

	/**
	 * Get tasks within date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $project_id Project ID filter.
	 * @param int    $user_id    User ID filter.
	 * @return array
	 */
	private function get_tasks_in_range( $start_date, $end_date, $project_id = 0, $user_id = 0 ) {
		$meta_query = array(
			array(
				'key'     => '_task_due_date',
				'value'   => array( $start_date, $end_date ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			),
		);

		if ( $project_id > 0 ) {
			$meta_query[] = array(
				'key'     => '_task_project_id',
				'value'   => $project_id,
				'compare' => '=',
			);
		}

		if ( $user_id > 0 ) {
			$meta_query[] = array(
				'key'     => '_task_assigned_to',
				'value'   => $user_id,
				'compare' => '=',
			);
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'meta_query'     => $meta_query,
		);

		$query = new WP_Query( $query_args );
		$items = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				$items[] = array(
					'type'       => 'task',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => get_post_meta( $id, '_task_due_date', true ),
					'status'     => get_post_meta( $id, '_task_status', true ),
					'priority'   => get_post_meta( $id, '_task_priority', true ),
					'project_id' => absint( get_post_meta( $id, '_task_project_id', true ) ) ?: null,
				);
			}
			wp_reset_postdata();
		}

		return $items;
	}

	/**
	 * Get events within date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $project_id Project ID filter.
	 * @param int    $user_id    User ID filter.
	 * @return array
	 */
	private function get_events_in_range( $start_date, $end_date, $project_id = 0, $user_id = 0 ) {
		$meta_query = array(
			array(
				'key'     => '_event_start_date',
				'value'   => array( $start_date, $end_date ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			),
		);

		if ( $project_id > 0 ) {
			$meta_query[] = array(
				'key'     => '_event_project_id',
				'value'   => $project_id,
				'compare' => '=',
			);
		}

		if ( $user_id > 0 ) {
			$meta_query[] = array(
				'key'     => '_event_attendees',
				'value'   => sprintf( ':"%d";', $user_id ),
				'compare' => 'LIKE',
			);
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_event',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'meta_query'     => $meta_query,
		);

		$query = new WP_Query( $query_args );
		$items = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				$items[] = array(
					'type'       => 'event',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => get_post_meta( $id, '_event_start_date', true ),
					'time'       => get_post_meta( $id, '_event_start_time', true ) ?: null,
					'all_day'    => (bool) get_post_meta( $id, '_event_all_day', true ),
					'event_type' => get_post_meta( $id, '_event_type', true ),
					'location'   => get_post_meta( $id, '_event_location', true ) ?: null,
					'project_id' => absint( get_post_meta( $id, '_event_project_id', true ) ) ?: null,
				);
			}
			wp_reset_postdata();
		}

		return $items;
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
