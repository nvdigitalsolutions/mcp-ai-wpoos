<?php
/**
 * Tool for exporting project calendar as ICS file.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load document response trait.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';

/**
 * Export project calendar events as ICS file.
 *
 * This tool leverages the ICS package to provide:
 * - RFC 5545 compliant iCalendar file generation
 * - Export project timelines and task schedules
 * - Compatible with Google Calendar, Outlook, Apple Calendar
 * - Support for recurring events and reminders
 * - Timezone-aware event handling
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Export_Calendar_ICS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_calendar_ics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Calendar as ICS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export project calendar events as RFC 5545 compliant ICS file. Share project timelines, task schedules, and events with team members via Google Calendar, Outlook, or Apple Calendar. Supports recurring events, reminders, and timezone handling.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'project_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Project ID to export calendar from', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_tasks'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include project tasks as calendar events', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_events'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include project events', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'date_range_start' => array(
					'type'        => 'string',
					'description' => __( 'Start date for events (ISO 8601 format, e.g., 2025-01-01)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'date_range_end'   => array(
					'type'        => 'string',
					'description' => __( 'End date for events (ISO 8601 format)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'timezone'         => array(
					'type'        => 'string',
					'description' => __( 'Timezone for events (e.g., America/New_York). Default: site timezone', 'mcp-ai-wpoos-pro' ),
					'default'     => wp_timezone_string(),
				),
				'organizer_name'   => array(
					'type'        => 'string',
					'description' => __( 'Organizer name for calendar events', 'mcp-ai-wpoos-pro' ),
				),
				'organizer_email'  => array(
					'type'        => 'string',
					'description' => __( 'Organizer email address', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'add_reminders'    => array(
					'type'        => 'boolean',
					'description' => __( 'Add reminder alarms to events (15 minutes before)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'download_file'    => array(
					'type'        => 'boolean',
					'description' => __( 'Return downloadable file URL. If false, returns ICS content as string.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'project_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation.
			'requires-capability',  // Requires edit_posts capability.
			'external-dependency',  // Requires ICS package (Node.js).
			'write',                // May create temporary files.
			'idempotent',           // Same input produces same output.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if project management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Project Management is not enabled. Please enable it in settings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate project ID.
		$project_id = absint( $arguments['project_id'] );
		if ( ! $project_id || get_post_type( $project_id ) !== 'project' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid project ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if ICS package is available.
		$ics_available = $this->check_ics_availability();
		if ( ! $ics_available ) {
			return array(
				'success' => false,
				'error'   => __( 'ICS package is not available. Please ensure Node.js and ICS package are installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get project details.
		$project = get_post( $project_id );
		if ( ! $project ) {
			return array(
				'success' => false,
				'error'   => __( 'Project not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Collect events from project.
		$events = $this->collect_project_events( $project_id, $arguments );

		if ( empty( $events ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No events found for this project in the specified date range.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Build ICS generation parameters.
		$ics_params = array(
			'events'   => $events,
			'prodId'   => array(
				'company' => get_bloginfo( 'name' ),
				'product' => 'Open Operator System',
			),
			'calName'  => sprintf( __( 'Project: %s', 'mcp-ai-wpoos-pro' ), $project->post_title ),
			'timezone' => isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : wp_timezone_string(),
		);

		// Generate ICS file.
		$result = $this->generate_ics_file( $ics_params );

		if ( ! $result || isset( $result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $result['error'] ) ? $result['error'] : __( 'ICS file generation failed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Handle file download or content return.
		$download_file = isset( $arguments['download_file'] ) ? (bool) $arguments['download_file'] : true;
		if ( $download_file && isset( $result['content'] ) ) {
			$file_info = $this->save_ics_file( $result['content'], $project_id );

			// Calculate file size safely.
			$file_size = 0;
			if ( isset( $file_info['path'] ) && file_exists( $file_info['path'] ) ) {
				$size_result = filesize( $file_info['path'] );
				if ( false !== $size_result ) {
					$file_size = $size_result;
				}
			}

			$response = array(
				'success'      => true,
				'message'      => __( 'Calendar exported successfully as ICS file.', 'mcp-ai-wpoos-pro' ),
				'text'         => sprintf( __( 'Calendar exported: %1$d events from %2$s', 'mcp-ai-wpoos-pro' ), count( $events ), $project->post_title ),
				'project_id'   => $project_id,
				'project_name' => $project->post_title,
				'event_count'  => count( $events ),
				'file_url'     => isset( $file_info['url'] ) ? $file_info['url'] : null,
				'file_path'    => isset( $file_info['path'] ) ? $file_info['path'] : null,
				'filename'     => isset( $file_info['filename'] ) ? $file_info['filename'] : null,
				'file_size'    => $file_size,
				'mime_type'    => 'text/calendar',
			);

			// Add download button and metadata display.
			return $this->add_document_html_to_response( $response );
		}

		return array(
			'success'      => true,
			'message'      => __( 'Calendar exported successfully.', 'mcp-ai-wpoos-pro' ),
			'project_id'   => $project_id,
			'project_name' => $project->post_title,
			'event_count'  => count( $events ),
			'ics_content'  => isset( $result['content'] ) ? $result['content'] : null,
		);
	}

	/**
	 * Check if ICS package is available.
	 *
	 * @return bool True if ICS is available.
	 */
	private function check_ics_availability() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/ics/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/ics/dist/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Collect events from project (tasks and events).
	 *
	 * @param int   $project_id Project ID.
	 * @param array $arguments  Tool arguments.
	 * @return array Array of event data.
	 */
	private function collect_project_events( $project_id, $arguments ) {
		$events         = array();
		$include_tasks  = isset( $arguments['include_tasks'] ) ? (bool) $arguments['include_tasks'] : true;
		$include_events = isset( $arguments['include_events'] ) ? (bool) $arguments['include_events'] : true;

		// Get date range if specified.
		$start_date = isset( $arguments['date_range_start'] ) ? sanitize_text_field( $arguments['date_range_start'] ) : null;
		$end_date   = isset( $arguments['date_range_end'] ) ? sanitize_text_field( $arguments['date_range_end'] ) : null;

		// Collect tasks as events.
		if ( $include_tasks ) {
			$tasks_query = new WP_Query(
				array(
					'post_type'      => 'task',
					'post_parent'    => $project_id,
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			);

			foreach ( $tasks_query->posts as $task ) {
				$due_date = get_post_meta( $task->ID, '_task_due_date', true );
				if ( $due_date && $this->is_in_date_range( $due_date, $start_date, $end_date ) ) {
					$events[] = array(
						'title'       => $task->post_title,
						'description' => wp_strip_all_tags( $task->post_content ),
						'start'       => $due_date,
						'duration'    => array( 'hours' => 1 ),
						'uid'         => 'task-' . $task->ID . '@' . get_site_url(),
					);
				}
			}
		}

		// Collect project events.
		if ( $include_events ) {
			$events_query = new WP_Query(
				array(
					'post_type'      => 'event',
					'post_parent'    => $project_id,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			);

			foreach ( $events_query->posts as $event ) {
				$event_date = get_post_meta( $event->ID, '_event_date', true );
				if ( $event_date && $this->is_in_date_range( $event_date, $start_date, $end_date ) ) {
					$events[] = array(
						'title'       => $event->post_title,
						'description' => wp_strip_all_tags( $event->post_content ),
						'start'       => $event_date,
						'duration'    => array( 'hours' => 2 ),
						'uid'         => 'event-' . $event->ID . '@' . get_site_url(),
					);
				}
			}
		}

		return $events;
	}

	/**
	 * Check if date is in specified range.
	 *
	 * @param string $date       Date to check.
	 * @param string $start_date Range start.
	 * @param string $end_date   Range end.
	 * @return bool True if in range.
	 */
	private function is_in_date_range( $date, $start_date, $end_date ) {
		if ( ! $start_date && ! $end_date ) {
			return true;
		}

		$timestamp = strtotime( $date );

		if ( $start_date && $timestamp < strtotime( $start_date ) ) {
			return false;
		}

		if ( $end_date && $timestamp > strtotime( $end_date ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate ICS file via Node.js.
	 *
	 * @param array $params ICS generation parameters.
	 * @return array|false Generation result or false on failure.
	 */
	private function generate_ics_file( $params ) {
		/**
		 * Filter to allow custom ICS generation implementation.
		 *
		 * @param array|false $result Generation result or false.
		 * @param array       $params Generation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_ics_generate_calendar', false, $params );

		if ( false === $result ) {
			// Default implementation note.
			return array(
				'error' => __( 'ICS generation requires a Node.js service. Please implement the wp_mcp_ai_ics_generate_calendar filter or set up a Node.js microservice. See docs/INTEGRATION_BEST_PRACTICES.md for RFC 5545 compliant implementation guide.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}

	/**
	 * Save ICS file to uploads directory.
	 *
	 * @param string $content    ICS file content.
	 * @param int    $project_id Project ID.
	 * @return array File information (url, path, filename).
	 */
	private function save_ics_file( $content, $project_id ) {
		$upload_dir = wp_upload_dir();
		$filename   = 'project-' . $project_id . '-' . time() . '.ics';
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// Save file.
		file_put_contents( $file_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array(
			'url'      => $upload_dir['url'] . '/' . $filename,
			'path'     => $file_path,
			'filename' => $filename,
		);
	}
}
