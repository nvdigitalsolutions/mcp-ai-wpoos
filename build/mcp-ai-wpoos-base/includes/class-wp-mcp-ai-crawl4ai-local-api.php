<?php
/**
 * Provides a local Crawl4AI-compatible REST API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Crawl4AI_Local_API' ) ) {
	/**
	 * Implements Crawl4AI endpoints backed by the local crawler integration.
	 */
	class WP_MCP_AI_Crawl4AI_Local_API {
		const TASK_STORAGE_PREFIX = 'wp_mcp_ai_crawl4ai_task_';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register the Crawl4AI-compatible REST routes.
		 */
		public function register_routes() {
			$namespace = class_exists( 'WP_MCP_AI_REST' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

			register_rest_route(
				$namespace,
				'/crawl4ai/crawl',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_crawl_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'urls'                 => array(
							'description' => __( 'Array of URLs to crawl.', 'wp-mcp-ai' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => array(
								'type'   => 'string',
								'format' => 'uri',
							),
						),
						'word_count_threshold' => array(
							'description' => __( 'Minimum word count for content extraction.', 'wp-mcp-ai' ),
							'type'        => 'integer',
							'required'    => false,
							'default'     => 50,
						),
						'extraction_strategy'  => array(
							'description' => __( 'Strategy for content extraction.', 'wp-mcp-ai' ),
							'type'        => 'string',
							'required'    => false,
							'enum'        => array( 'NoExtractionStrategy', 'JsonCssExtractionStrategy', 'LLMExtractionStrategy' ),
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/crawl4ai/task/(?P<task_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_task_lookup' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'task_id' => array(
							'description'       => __( 'Unique identifier for the crawl task.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		/**
		 * Permission callback shared by both endpoints.
		 *
		 * @param WP_REST_Request $request Request instance.
		 * @return true|WP_Error
		 */
		public function check_permissions( $request ) {
			unset( $request );

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return new WP_Error(
					'wp_mcp_ai_crawl4ai_unauthenticated',
					__( 'Authentication is required to use the Crawl4AI API.', 'wp-mcp-ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error(
					'wp_mcp_ai_crawl4ai_forbidden',
					__( 'You do not have permission to run Crawl4AI jobs.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_crawl4ai_wrong_site',
					__( 'You do not have access to this site.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * Handle Crawl4AI crawl submissions.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_crawl_request( WP_REST_Request $request ) {
			$params    = $this->extract_request_params( $request );
			$arguments = $this->prepare_arguments( $params );

			$tool    = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
			$context = array(
				'user_id' => get_current_user_id(),
			);

			$result = $tool->execute( $arguments, $context );

			if ( is_wp_error( $result ) ) {
				$status = $this->get_error_status( $result );
				$data   = $result->get_error_data();

				if ( ! is_array( $data ) ) {
					$data = array();
				}

				$data['status'] = $status;

				return new WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
			}

			$task_id = isset( $result['task_id'] ) ? sanitize_text_field( (string) $result['task_id'] ) : '';
			if ( '' === $task_id ) {
				$task_id = $this->generate_task_id();
			}

			$result['task_id'] = $task_id;

			self::store_task_result( $task_id, $result );

			return rest_ensure_response( $result );
		}

		/**
		 * Handle retrieval of previously cached task results.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_task_lookup( WP_REST_Request $request ) {
			$task_id = sanitize_text_field( (string) $request['task_id'] );

			if ( '' === $task_id ) {
				return new WP_Error(
					'wp_mcp_ai_crawl4ai_task_invalid',
					__( 'A valid Crawl4AI task identifier is required.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$result = self::get_task_result( $task_id );

			if ( empty( $result ) ) {
				return new WP_Error(
					'wp_mcp_ai_crawl4ai_task_not_found',
					__( 'The requested Crawl4AI task could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			$result['task_id'] = $task_id;

			return rest_ensure_response( $result );
		}

		/**
		 * Extract parameters from the REST request.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return array
		 */
		protected function extract_request_params( WP_REST_Request $request ) {
			$params = $request->get_json_params();

			if ( empty( $params ) ) {
				$params = $request->get_body_params();
			}

			if ( empty( $params ) ) {
				$params = $request->get_params();
			}

			return is_array( $params ) ? $params : array();
		}

		/**
		 * Prepare arguments for the Crawl4AI tool based on the request payload.
		 *
		 * @param array $params Raw parameters from the request.
		 * @return array
		 */
		protected function prepare_arguments( array $params ) {
			$allowed_keys = array(
				'urls',
				'url',
				'priority',
				'options',
				'wait_for_completion',
				'poll_interval',
				'timeout',
			);

			$arguments = array();

			foreach ( $allowed_keys as $key ) {
				if ( array_key_exists( $key, $params ) ) {
					$arguments[ $key ] = $params[ $key ];
				}
			}

			return $arguments;
		}

		/**
		 * Determine the HTTP status code to expose for a WP_Error.
		 *
		 * @param WP_Error $error Error instance.
		 * @return int
		 */
		protected function get_error_status( WP_Error $error ) {
			$data = $error->get_error_data();

			if ( is_array( $data ) && isset( $data['status'] ) ) {
				return (int) $data['status'];
			}

			return 400;
		}

		/**
		 * Generate a unique identifier for cached Crawl4AI tasks.
		 *
		 * @return string
		 */
		protected function generate_task_id() {
			$unique = wp_generate_password( 12, false, false );

			return 'local-' . strtolower( $unique );
		}

		/**
		 * Persist a Crawl4AI task result for future retrieval.
		 *
		 * @param string $task_id Task identifier.
		 * @param array  $result  Result array.
		 */
		protected static function store_task_result( $task_id, array $result ) {
			$result = self::filter_result_for_storage( $result );

			$ttl = (int) apply_filters( 'wp_mcp_ai_crawl4ai_task_ttl', DAY_IN_SECONDS );
			if ( $ttl <= 0 ) {
				$ttl = DAY_IN_SECONDS;
			}

			$key = self::get_task_storage_key( $task_id );

			if ( is_multisite() ) {
				set_site_transient( $key, $result, $ttl );
			} else {
				set_transient( $key, $result, $ttl );
			}
		}

		/**
		 * Retrieve a cached Crawl4AI task result.
		 *
		 * @param string $task_id Task identifier.
		 * @return array|null
		 */
		protected static function get_task_result( $task_id ) {
			$key = self::get_task_storage_key( $task_id );

			if ( is_multisite() ) {
				$result = get_site_transient( $key );
			} else {
				$result = get_transient( $key );
			}

			if ( ! is_array( $result ) ) {
				return null;
			}

			return $result;
		}

		/**
		 * Build the storage key used for cached task results.
		 *
		 * @param string $task_id Task identifier.
		 * @return string
		 */
		protected static function get_task_storage_key( $task_id ) {
			$hash = md5( $task_id );

			if ( is_multisite() ) {
				$blog_id = absint( get_current_blog_id() );

				return sprintf( '%s%s_%s', self::TASK_STORAGE_PREFIX, $blog_id, $hash );
			}

			return self::TASK_STORAGE_PREFIX . $hash;
		}

		/**
		 * Ensure only relevant fields are cached for later retrieval.
		 *
		 * @param array $result Result payload.
		 * @return array
		 */
		protected static function filter_result_for_storage( array $result ) {
			$allowed = array( 'status', 'task_id', 'results', 'metadata', 'raw' );
			$clean   = array();

			foreach ( $allowed as $key ) {
				if ( array_key_exists( $key, $result ) ) {
					$clean[ $key ] = $result[ $key ];
				}
			}

			$clean['stored_at'] = current_time( 'mysql', true );

			return $clean;
		}

		/**
		 * Allow external callers to cache Crawl4AI task results.
		 *
		 * @param string $task_id Task identifier.
		 * @param array  $result  Result payload.
		 */
		public static function cache_task_result( $task_id, array $result ) {
			self::store_task_result( $task_id, $result );
		}

		/**
		 * Retrieve a cached task result without instantiating the controller.
		 *
		 * @param string $task_id Task identifier.
		 * @return array|null
		 */
		public static function retrieve_task_result( $task_id ) {
			return self::get_task_result( $task_id );
		}

	/**
	 * Get all cached Crawl4AI tasks with their data.
	 *
	 * @param int $limit Maximum number of tasks to retrieve.
	 * @return array Array of task data.
	 */
	public static function get_all_tasks( $limit = 100 ) {
		global $wpdb;

		$tasks  = array();
		$prefix = self::TASK_STORAGE_PREFIX;
		$limit  = absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 100;
		}

		if ( is_multisite() ) {
			$blog_id = absint( get_current_blog_id() );
			$pattern = $wpdb->esc_like( sprintf( '%s%s_', $prefix, $blog_id ) ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s ORDER BY meta_id DESC LIMIT %d",
					$pattern,
					$limit
				)
			);

			if ( ! empty( $results ) ) {
				foreach ( $results as $row ) {
					$data = maybe_unserialize( $row->meta_value );

					if ( ! is_array( $data ) ) {
						continue;
					}

					$tasks[] = $data;
				}
			}
		} else {
			$transient_pattern = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$timeout_pattern   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_id DESC LIMIT %d",
					$transient_pattern,
					$timeout_pattern,
					$limit
				)
			);

			if ( ! empty( $results ) ) {
				foreach ( $results as $row ) {
					$data = maybe_unserialize( $row->option_value );

					if ( ! is_array( $data ) ) {
						continue;
					}

					$tasks[] = $data;
				}
			}
		}

		return $tasks;
	}

	/**
	 * Calculate statistics from all cached Crawl4AI tasks.
	 *
	 * @return array Statistics array.
	 */
	public static function get_statistics() {
		$tasks = self::get_all_tasks( 1000 );

		$stats = array(
			'total_jobs'     => 0,
			'running_jobs'   => 0,
			'completed_jobs' => 0,
			'failed_jobs'    => 0,
			'browser_pools'  => 0,
		);

		if ( empty( $tasks ) ) {
			return $stats;
		}

		$browser_pools = array();

		foreach ( $tasks as $task ) {
			$stats['total_jobs']++;

			$status = isset( $task['status'] ) ? $task['status'] : 'unknown';

			switch ( $status ) {
				case 'completed':
					$stats['completed_jobs']++;
					break;
				case 'running':
				case 'pending':
					$stats['running_jobs']++;
					break;
				case 'failed':
				case 'error':
					$stats['failed_jobs']++;
					break;
			}

			// Track browser pools if metadata exists.
			if ( isset( $task['metadata']['browser_pool'] ) ) {
				$pool                       = $task['metadata']['browser_pool'];
				$browser_pools[ $pool ] = true;
			}
		}

		$stats['browser_pools'] = count( $browser_pools );

		return $stats;
	}

	/**
	 * Get recent Crawl4AI jobs with optional filtering and pagination.
	 *
	 * @param array $args {
	 *     Optional. Arguments for filtering and pagination.
	 *
	 *     @type int    $limit  Maximum number of jobs to return. Default 20.
	 *     @type int    $offset Offset for pagination. Default 0.
	 *     @type string $status Filter by status. Default empty (all).
	 * }
	 * @return array Array of job data.
	 */
	public static function get_recent_jobs( $args = array() ) {
		$defaults = array(
			'limit'  => 20,
			'offset' => 0,
			'status' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );
		$status = sanitize_text_field( $args['status'] );

		// Get more tasks than needed to account for filtering.
		$fetch_limit = $limit + $offset + 100;
		$tasks       = self::get_all_tasks( $fetch_limit );

		if ( empty( $tasks ) ) {
			return array();
		}

		// Filter by status if specified.
		if ( '' !== $status ) {
			$tasks = array_filter(
				$tasks,
				function ( $task ) use ( $status ) {
					$task_status = isset( $task['status'] ) ? $task['status'] : '';
					return $task_status === $status;
				}
			);
		}

		// Sort by stored_at timestamp (newest first).
		usort(
			$tasks,
			function ( $a, $b ) {
				$time_a = isset( $a['stored_at'] ) ? strtotime( $a['stored_at'] ) : 0;
				$time_b = isset( $b['stored_at'] ) ? strtotime( $b['stored_at'] ) : 0;
				return $time_b - $time_a;
			}
		);

		// Apply pagination.
		$tasks = array_slice( $tasks, $offset, $limit );

		// Format jobs for display.
		$jobs = array();

		foreach ( $tasks as $task ) {
			$job = array(
				'id'           => isset( $task['task_id'] ) ? $task['task_id'] : 'unknown',
				'status'       => isset( $task['status'] ) ? $task['status'] : 'unknown',
				'url'          => '',
				'started'      => '',
				'duration'     => 'N/A',
				'browser_pool' => 'N/A',
			);

			// Extract URL from results.
			if ( isset( $task['results'][0]['url'] ) ) {
				$job['url'] = $task['results'][0]['url'];
			} elseif ( isset( $task['metadata']['urls'][0] ) ) {
				$job['url'] = $task['metadata']['urls'][0];
			}

			// Format timestamps.
			if ( isset( $task['stored_at'] ) ) {
				$job['started'] = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $task['stored_at'], true );
			} elseif ( isset( $task['metadata']['fetched_at'] ) ) {
				$job['started'] = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $task['metadata']['fetched_at'], true );
			}

			// Calculate duration if available.
			if ( isset( $task['metadata']['duration'] ) ) {
				$duration        = floatval( $task['metadata']['duration'] );
				$job['duration'] = sprintf( '%.2fs', $duration );
			}

			// Get browser pool info.
			if ( isset( $task['metadata']['browser_pool'] ) ) {
				$job['browser_pool'] = $task['metadata']['browser_pool'];
			} elseif ( isset( $task['metadata']['mode'] ) ) {
				$job['browser_pool'] = ucfirst( $task['metadata']['mode'] );
			}

			$jobs[] = $job;
		}

		return $jobs;
	}
	}
}
