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

			// Crawl4AI v0.7.7 monitoring endpoints.
			register_rest_route(
				$namespace,
				'/crawl4ai/monitor',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_monitor_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);

			register_rest_route(
				$namespace,
				'/crawl4ai/health',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_health_check' ),
					'permission_callback' => '__return_true',
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
		 * Handle Crawl4AI v0.7.7 monitor API endpoint.
		 *
		 * Returns real-time statistics about crawl jobs, browser pools,
		 * and system resource usage.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_monitor_request( WP_REST_Request $request ) {
			$stats = $this->get_monitor_statistics();

			return rest_ensure_response( $stats );
		}

		/**
		 * Handle Crawl4AI v0.7.7 health check endpoint.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response
		 */
		public function handle_health_check( WP_REST_Request $request ) {
			unset( $request );

			$health = array(
				'status'    => 'healthy',
				'version'   => '0.7.7-compatible',
				'mode'      => 'local',
				'timestamp' => current_time( 'mysql', true ),
			);

			return rest_ensure_response( $health );
		}

		/**
		 * Get monitor statistics compatible with Crawl4AI v0.7.7 format.
		 *
		 * @return array Monitor statistics.
		 */
		private function get_monitor_statistics() {
			global $wpdb;

			// Get active jobs.
			$active_jobs = $this->count_active_crawler_jobs();

			// Get cached tasks.
			$cached_tasks = $this->count_cached_tasks();

			// Get task status breakdown.
			$status_breakdown = $this->get_task_status_breakdown();

			// Calculate cache metrics.
			$cache_metrics = $this->get_cache_metrics();

			return array(
				'crawl_jobs'       => array(
					'active'    => $active_jobs,
					'queued'    => isset( $status_breakdown['pending'] ) ? $status_breakdown['pending'] : 0,
					'running'   => isset( $status_breakdown['processing'] ) ? $status_breakdown['processing'] : 0,
					'completed' => isset( $status_breakdown['completed'] ) ? $status_breakdown['completed'] : 0,
					'failed'    => isset( $status_breakdown['failed'] ) ? $status_breakdown['failed'] : 0,
				),
				'cache'            => array(
					'total_tasks' => $cached_tasks,
					'size_mb'     => $cache_metrics['size_mb'],
					'urls_cached' => $cache_metrics['urls'],
				),
				'browser_pool'     => array(
					'mode'        => 'local',
					'description' => __( 'WordPress HTTP API (local mode)', 'wp-mcp-ai' ),
				),
				'system'           => array(
					'mode'       => 'local',
					'php_memory' => size_format( memory_get_usage( true ) ),
				),
				'version'          => '0.7.7-compatible',
				'timestamp'        => current_time( 'mysql', true ),
			);
		}

		/**
		 * Count active crawler jobs.
		 *
		 * @return int Number of active jobs.
		 */
		private function count_active_crawler_jobs() {
			global $wpdb;

			$prefix = 'wp_mcp_ai_crawl4ai_job_';

			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
						$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
					)
				);
			} else {
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%'
					)
				);
			}

			return absint( $count );
		}

		/**
		 * Count cached tasks.
		 *
		 * @return int Number of cached tasks.
		 */
		private function count_cached_tasks() {
			global $wpdb;

			$prefix = self::TASK_STORAGE_PREFIX;

			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
						$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
					)
				);
			} else {
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%'
					)
				);
			}

			return absint( $count );
		}

		/**
		 * Get breakdown of tasks by status.
		 *
		 * @return array Status counts.
		 */
		private function get_task_status_breakdown() {
			global $wpdb;

			$prefix  = self::TASK_STORAGE_PREFIX;
			$results = array();

			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();
				$rows    = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
						$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_value as meta_value FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%'
					),
					ARRAY_A
				);
			}

			$breakdown = array();

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$value = maybe_unserialize( $row['meta_value'] );
					if ( is_array( $value ) && isset( $value['status'] ) ) {
						$status = sanitize_key( $value['status'] );
						if ( ! isset( $breakdown[ $status ] ) ) {
							$breakdown[ $status ] = 0;
						}
						++$breakdown[ $status ];
					}
				}
			}

			return $breakdown;
		}

		/**
		 * Get cache metrics (size and URL count).
		 *
		 * @return array Cache metrics.
		 */
		private function get_cache_metrics() {
			global $wpdb;

			$prefix = self::TASK_STORAGE_PREFIX;
			$size   = 0;
			$urls   = 0;

			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();
				$rows    = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
						$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_value as meta_value FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%'
					),
					ARRAY_A
				);
			}

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$value = maybe_unserialize( $row['meta_value'] );
					if ( is_array( $value ) ) {
						$size += strlen( maybe_serialize( $value ) );

						if ( isset( $value['results'] ) && is_array( $value['results'] ) ) {
							$urls += count( $value['results'] );
						}
					}
				}
			}

			return array(
				'size_mb' => round( $size / 1024 / 1024, 2 ),
				'urls'    => $urls,
			);
		}
	}
}
