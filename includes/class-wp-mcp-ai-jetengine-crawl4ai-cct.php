<?php
/**
 * JetEngine Custom Content Type registration for Crawl4AI job history.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the Crawl4AI job history CCT exists and expose helper accessors.
 */
class WP_MCP_AI_JetEngine_Crawl4AI_CCT {
	const SLUG = 'crawl4ai_jobs';

	/**
	 * Hook into JetEngine to provision the Crawl4AI job content type.
	 */
	public static function bootstrap() {
		// Ensure data stores module is enabled first.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), -1 );
		
		// Then register the CCT.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );

		// Hook into crawl job lifecycle events.
		add_action( 'wp_mcp_ai_crawl4ai_job_completed', array( __CLASS__, 'log_completed_job' ), 10, 3 );
		add_action( 'wp_mcp_ai_crawl4ai_job_failed', array( __CLASS__, 'log_failed_job' ), 10, 3 );
	}

	/**
	 * Retrieve the Crawl4AI job CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the Crawl4AI job content type.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return;
		}

		$module = $engine->modules->get_module( 'data-stores' );

		if ( ! $module ) {
			return;
		}

		if ( method_exists( $engine->modules, 'activate_module' ) ) {
			$engine->modules->activate_module( 'data-stores' );
		}
	}

	/**
	 * Register the Crawl4AI job CCT if JetEngine is available and the content type doesn't exist.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		if ( ! $module->manager ) {
			return;
		}

		$existing = $module->manager->get_content_types( self::SLUG );

		if ( $existing ) {
			return;
		}

		$definition = self::get_cct_definition();

		$module->data->register_content_type( $definition );

		/**
		 * Fires after the Crawl4AI job CCT is registered.
		 *
		 * @param array $definition CCT definition array.
		 */
		do_action( 'wp_mcp_ai_crawl4ai_cct_registered', $definition );
	}

	/**
	 * Get the JetEngine CCT module instance.
	 *
	 * @return object|null
	 */
	private static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		if ( empty( $engine->cct ) ) {
			return null;
		}

		return $engine->cct;
	}

	/**
	 * Build the CCT definition for Crawl4AI job history.
	 *
	 * @return array
	 */
	private static function get_cct_definition() {
		return array(
			'slug'              => self::SLUG,
			'status'            => 'publish',
			'name'              => __( 'Crawl4AI Jobs', 'wp-mcp-ai' ),
			'singular_name'     => __( 'Crawl4AI Job', 'wp-mcp-ai' ),
			'labels'            => array(
				'add_new'      => __( 'Add New Job', 'wp-mcp-ai' ),
				'edit_item'    => __( 'Edit Job', 'wp-mcp-ai' ),
				'delete_item'  => __( 'Delete Job', 'wp-mcp-ai' ),
				'search_items' => __( 'Search Jobs', 'wp-mcp-ai' ),
			),
			'has_single'        => true,
			'hide_field_names'  => false,
			'admin_columns'     => array(
				'task_id' => array(
					'type'    => 'custom_callback',
					'title'   => __( 'Task ID', 'wp-mcp-ai' ),
					'position' => 1,
				),
				'status'  => array(
					'type'     => 'meta_value',
					'title'    => __( 'Status', 'wp-mcp-ai' ),
					'meta_key' => 'status',
					'position' => 2,
				),
				'url_count'  => array(
					'type'     => 'custom_callback',
					'title'    => __( 'URLs', 'wp-mcp-ai' ),
					'position' => 3,
				),
				'created_at' => array(
					'type'     => 'meta_value',
					'title'    => __( 'Created', 'wp-mcp-ai' ),
					'meta_key' => 'created_at',
					'position' => 4,
				),
			),
			'admin_filters'     => array(
				'status' => array(
					'type'     => 'select',
					'label'    => __( 'Status', 'wp-mcp-ai' ),
					'meta_key' => 'status',
					'options'  => array(
						''          => __( 'All Statuses', 'wp-mcp-ai' ),
						'pending'   => __( 'Pending', 'wp-mcp-ai' ),
						'processing' => __( 'Processing', 'wp-mcp-ai' ),
						'completed' => __( 'Completed', 'wp-mcp-ai' ),
						'failed'    => __( 'Failed', 'wp-mcp-ai' ),
						'timeout'   => __( 'Timeout', 'wp-mcp-ai' ),
					),
				),
			),
			'meta_fields'       => self::get_meta_fields(),
		);
	}

	/**
	 * Define meta fields for the Crawl4AI job CCT.
	 *
	 * @return array
	 */
	private static function get_meta_fields() {
		return array(
			array(
				'name'         => 'task_id',
				'title'        => __( 'Task ID', 'wp-mcp-ai' ),
				'type'         => 'text',
				'is_required'  => true,
				'width'        => '100%',
			),
			array(
				'name'         => 'status',
				'title'        => __( 'Status', 'wp-mcp-ai' ),
				'type'         => 'select',
				'options'      => array(
					array( 'key' => 'pending', 'value' => __( 'Pending', 'wp-mcp-ai' ) ),
					array( 'key' => 'processing', 'value' => __( 'Processing', 'wp-mcp-ai' ) ),
					array( 'key' => 'completed', 'value' => __( 'Completed', 'wp-mcp-ai' ) ),
					array( 'key' => 'failed', 'value' => __( 'Failed', 'wp-mcp-ai' ) ),
					array( 'key' => 'timeout', 'value' => __( 'Timeout', 'wp-mcp-ai' ) ),
				),
				'is_required'  => true,
				'width'        => '50%',
			),
			array(
				'name'         => 'base_url',
				'title'        => __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
				'type'         => 'text',
				'description'  => __( 'Remote Crawl4AI endpoint (empty for local mode)', 'wp-mcp-ai' ),
				'width'        => '50%',
			),
			array(
				'name'         => 'urls',
				'title'        => __( 'Target URLs', 'wp-mcp-ai' ),
				'type'         => 'textarea',
				'description'  => __( 'One URL per line', 'wp-mcp-ai' ),
				'width'        => '100%',
			),
			array(
				'name'         => 'result_count',
				'title'        => __( 'Result Count', 'wp-mcp-ai' ),
				'type'         => 'number',
				'default_val'  => '0',
				'width'        => '33%',
			),
			array(
				'name'         => 'error_message',
				'title'        => __( 'Error Message', 'wp-mcp-ai' ),
				'type'         => 'textarea',
				'width'        => '100%',
			),
			array(
				'name'         => 'created_at',
				'title'        => __( 'Created At', 'wp-mcp-ai' ),
				'type'         => 'datetime-local',
				'is_required'  => true,
				'width'        => '50%',
			),
			array(
				'name'         => 'completed_at',
				'title'        => __( 'Completed At', 'wp-mcp-ai' ),
				'type'         => 'datetime-local',
				'width'        => '50%',
			),
			array(
				'name'         => 'user_id',
				'title'        => __( 'User ID', 'wp-mcp-ai' ),
				'type'         => 'number',
				'description'  => __( 'User who initiated the crawl', 'wp-mcp-ai' ),
				'width'        => '50%',
			),
			array(
				'name'         => 'poll_interval',
				'title'        => __( 'Poll Interval (seconds)', 'wp-mcp-ai' ),
				'type'         => 'number',
				'default_val'  => '30',
				'width'        => '50%',
			),
			array(
				'name'         => 'result_data',
				'title'        => __( 'Result Data (JSON)', 'wp-mcp-ai' ),
				'type'         => 'textarea',
				'description'  => __( 'Serialized crawl results', 'wp-mcp-ai' ),
				'width'        => '100%',
			),
			array(
				'name'         => 'metadata',
				'title'        => __( 'Metadata (JSON)', 'wp-mcp-ai' ),
				'type'         => 'textarea',
				'description'  => __( 'Additional job metadata', 'wp-mcp-ai' ),
				'width'        => '100%',
			),
		);
	}

	/**
	 * Log a completed crawl job to the CCT.
	 *
	 * @param string $task_id Task identifier.
	 * @param array  $result  Result payload.
	 * @param array  $job     Job metadata.
	 */
	public static function log_completed_job( $task_id, $result, $job ) {
		self::log_job( $task_id, 'completed', $result, $job );
	}

	/**
	 * Log a failed crawl job to the CCT.
	 *
	 * @param string   $task_id Task identifier.
	 * @param WP_Error $error   Error instance.
	 * @param array    $job     Job metadata.
	 */
	public static function log_failed_job( $task_id, $error, $job ) {
		$result = array(
			'status'  => 'failed',
			'task_id' => $task_id,
			'error'   => $error->get_error_message(),
		);

		self::log_job( $task_id, 'failed', $result, $job, $error->get_error_message() );
	}

	/**
	 * Log a crawl job to the CCT.
	 *
	 * @param string $task_id       Task identifier.
	 * @param string $status        Job status.
	 * @param array  $result        Result payload.
	 * @param array  $job           Job metadata.
	 * @param string $error_message Optional error message.
	 */
	private static function log_job( $task_id, $status, $result, $job, $error_message = '' ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return;
		}

		// Extract URLs from job arguments or result.
		$urls = array();
		if ( isset( $job['arguments']['urls'] ) && is_array( $job['arguments']['urls'] ) ) {
			$urls = $job['arguments']['urls'];
		} elseif ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			foreach ( $result['results'] as $item ) {
				if ( isset( $item['url'] ) ) {
					$urls[] = $item['url'];
				}
			}
		}

		$data = array(
			'task_id'       => sanitize_text_field( $task_id ),
			'status'        => sanitize_key( $status ),
			'base_url'      => isset( $job['base_url'] ) ? esc_url_raw( $job['base_url'] ) : '',
			'urls'          => implode( "\n", array_map( 'esc_url_raw', $urls ) ),
			'result_count'  => isset( $result['results'] ) && is_array( $result['results'] ) ? count( $result['results'] ) : 0,
			'error_message' => sanitize_textarea_field( $error_message ),
			'created_at'    => isset( $job['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $job['created_at'] ) : current_time( 'mysql', true ),
			'completed_at'  => current_time( 'mysql', true ),
			'user_id'       => isset( $job['context']['user_id'] ) ? absint( $job['context']['user_id'] ) : 0,
			'poll_interval' => isset( $job['poll_interval'] ) ? absint( $job['poll_interval'] ) : 0,
			'result_data'   => wp_json_encode( $result ),
			'metadata'      => wp_json_encode( $job ),
		);

		// Check if job already exists.
		$existing = $handler->get_items(
			array(
				'meta_query' => array(
					array(
						'key'     => 'task_id',
						'value'   => $task_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $existing ) ) {
			// Update existing record.
			// Safely extract item ID from the result.
			$first_item = is_array( $existing ) && count( $existing ) > 0 ? reset( $existing ) : null;
			
			if ( $first_item && isset( $first_item->_ID ) ) {
				$item_id = $first_item->_ID;
				$handler->update_item( $item_id, $data );
			}
		} else {
			// Create new record.
			$handler->insert_item( $data );
		}

		/**
		 * Fires after a crawl job is logged to the CCT.
		 *
		 * @param string $task_id Task identifier.
		 * @param string $status  Job status.
		 * @param array  $result  Result payload.
		 * @param array  $job     Job metadata.
		 */
		do_action( 'wp_mcp_ai_crawl4ai_job_logged', $task_id, $status, $result, $job );
	}

	/**
	 * Get crawl job statistics from the CCT.
	 *
	 * @return array Statistics array.
	 */
	public static function get_statistics() {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return array();
		}

		$all_jobs = $handler->get_items();

		$stats = array(
			'total'      => count( $all_jobs ),
			'completed'  => 0,
			'failed'     => 0,
			'pending'    => 0,
			'processing' => 0,
			'timeout'    => 0,
		);

		foreach ( $all_jobs as $job ) {
			$status = isset( $job->status ) ? $job->status : 'unknown';
			if ( isset( $stats[ $status ] ) ) {
				++$stats[ $status ];
			}
		}

		return $stats;
	}

	/**
	 * Clean up old completed jobs from the CCT.
	 *
	 * @param int $days Number of days to keep completed jobs.
	 * @return int Number of jobs deleted.
	 */
	public static function cleanup_old_jobs( $days = 30 ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$old_jobs = $handler->get_items(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'status',
						'value'   => 'completed',
						'compare' => '=',
					),
					array(
						'key'     => 'completed_at',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$deleted = 0;

		if ( ! empty( $old_jobs ) ) {
			foreach ( $old_jobs as $job ) {
				$item_id = isset( $job->_ID ) ? $job->_ID : 0;
				if ( $item_id ) {
					$handler->delete_item( $item_id );
					++$deleted;
				}
			}
		}

		return $deleted;
	}
}

// Bootstrap the CCT when JetEngine is available.
WP_MCP_AI_JetEngine_Crawl4AI_CCT::bootstrap();
