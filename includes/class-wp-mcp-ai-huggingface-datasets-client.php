<?php
/**
 * HuggingFace Dataset Viewer API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Huggingface_Datasets_Client' ) ) {
	/**
	 * Provides a wrapper around HuggingFace's Dataset Viewer API.
	 * Allows querying datasets without downloading them.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Huggingface_Datasets_Client {

		/**
		 * Base URL for the Dataset Viewer API.
		 *
		 * @var string
		 */
		const BASE_URL = 'https://datasets-server.huggingface.co';

		/**
		 * Cache group for transients.
		 *
		 * @var string
		 */
		const CACHE_GROUP = 'wp_mcp_ai_hf_datasets';

		/**
		 * Retrieve the configured API token (optional, for private datasets).
		 *
		 * @return string
		 */
		public function get_api_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['huggingface_datasets_api_token'] ) ? $settings['huggingface_datasets_api_token'] : '';
		}

		/**
		 * Check if Dataset Viewer is enabled.
		 *
		 * @return bool
		 */
		public function is_enabled() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['enable_huggingface_datasets'] );
		}

		/**
		 * Get cache TTL in seconds.
		 *
		 * @return int
		 */
		public function get_cache_ttl() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$ttl      = isset( $settings['huggingface_datasets_cache_ttl'] ) ? (int) $settings['huggingface_datasets_cache_ttl'] : 3600;
			return max( 60, min( 86400, $ttl ) ); // Between 1 minute and 24 hours.
		}

		/**
		 * Get default row limit.
		 *
		 * @return int
		 */
		public function get_default_limit() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$limit    = isset( $settings['huggingface_datasets_default_limit'] ) ? (int) $settings['huggingface_datasets_default_limit'] : 10;
			return max( 1, min( 100, $limit ) ); // Between 1 and 100.
		}

		/**
		 * Check if a dataset is valid and accessible.
		 *
		 * @param string $dataset Dataset name (e.g., 'squad', 'imdb').
		 * @return array|WP_Error Response with validity status or error.
		 */
		public function is_valid( $dataset ) {
			$dataset = sanitize_text_field( $dataset );

			$cache_key = 'is_valid_' . md5( $dataset );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/is-valid', array( 'dataset' => $dataset ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Get available splits and configurations for a dataset.
		 *
		 * @param string $dataset Dataset name.
		 * @return array|WP_Error Array of splits or error.
		 */
		public function get_splits( $dataset ) {
			$dataset = sanitize_text_field( $dataset );

			$cache_key = 'splits_' . md5( $dataset );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/splits', array( 'dataset' => $dataset ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Get dataset information and metadata.
		 *
		 * @param string $dataset Dataset name.
		 * @return array|WP_Error Dataset info or error.
		 */
		public function get_info( $dataset ) {
			$dataset = sanitize_text_field( $dataset );

			$cache_key = 'info_' . md5( $dataset );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/info', array( 'dataset' => $dataset ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Get dataset size information.
		 *
		 * @param string $dataset Dataset name.
		 * @return array|WP_Error Size information or error.
		 */
		public function get_size( $dataset ) {
			$dataset = sanitize_text_field( $dataset );

			$cache_key = 'size_' . md5( $dataset );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/size', array( 'dataset' => $dataset ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Preview first rows of a dataset split.
		 *
		 * @param string $dataset Dataset name.
		 * @param string $config  Configuration name (default: 'default').
		 * @param string $split   Split name (e.g., 'train', 'test').
		 * @param int    $limit   Number of rows to return (max 100).
		 * @return array|WP_Error Rows data or error.
		 */
		public function preview_rows( $dataset, $config = 'default', $split = 'train', $limit = null ) {
			$dataset = sanitize_text_field( $dataset );
			$config  = sanitize_text_field( $config );
			$split   = sanitize_text_field( $split );
			$limit   = null === $limit ? $this->get_default_limit() : min( 100, absint( $limit ) );

			$params = array(
				'dataset' => $dataset,
				'config'  => $config,
				'split'   => $split,
			);

			// Note: The API endpoint is /first-rows, but limit is controlled by response processing.
			$cache_key = 'preview_' . md5( wp_json_encode( $params ) . '_' . $limit );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/first-rows', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Limit rows if needed.
			if ( isset( $response['rows'] ) && is_array( $response['rows'] ) ) {
				$response['rows'] = array_slice( $response['rows'], 0, $limit );
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Get paginated rows from a dataset split.
		 *
		 * @param string $dataset Dataset name.
		 * @param string $config  Configuration name.
		 * @param string $split   Split name.
		 * @param int    $offset  Starting row (0-based).
		 * @param int    $length  Number of rows (max 100).
		 * @return array|WP_Error Rows data or error.
		 */
		public function get_rows( $dataset, $config = 'default', $split = 'train', $offset = 0, $length = null ) {
			$dataset = sanitize_text_field( $dataset );
			$config  = sanitize_text_field( $config );
			$split   = sanitize_text_field( $split );
			$offset  = absint( $offset );
			$length  = null === $length ? $this->get_default_limit() : min( 100, absint( $length ) );

			$params = array(
				'dataset' => $dataset,
				'config'  => $config,
				'split'   => $split,
				'offset'  => $offset,
				'length'  => $length,
			);

			$cache_key = 'rows_' . md5( wp_json_encode( $params ) );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/rows', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Search for text within a dataset split.
		 *
		 * @param string $dataset Dataset name.
		 * @param string $config  Configuration name.
		 * @param string $split   Split name.
		 * @param string $query   Search query text.
		 * @param int    $offset  Starting row (0-based).
		 * @param int    $length  Number of rows (max 100).
		 * @return array|WP_Error Search results or error.
		 */
		public function search( $dataset, $config = 'default', $split = 'train', $query = '', $offset = 0, $length = null ) {
			$dataset = sanitize_text_field( $dataset );
			$config  = sanitize_text_field( $config );
			$split   = sanitize_text_field( $split );
			$query   = sanitize_textarea_field( $query );
			$offset  = absint( $offset );
			$length  = null === $length ? $this->get_default_limit() : min( 100, absint( $length ) );

			if ( empty( $query ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_empty_query',
					__( 'Search query cannot be empty.', 'wp-mcp-ai' )
				);
			}

			$params = array(
				'dataset' => $dataset,
				'config'  => $config,
				'split'   => $split,
				'query'   => $query,
				'offset'  => $offset,
				'length'  => $length,
			);

			// Don't cache search results as aggressively.
			$cache_key = 'search_' . md5( wp_json_encode( $params ) );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/search', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response, 1800 ); // 30 minutes for search.

			return $response;
		}

		/**
		 * Filter dataset rows using SQL-like expressions.
		 *
		 * @param string      $dataset Dataset name.
		 * @param string      $config  Configuration name.
		 * @param string      $split   Split name.
		 * @param string      $where   Filter expression (e.g., "label = 1").
		 * @param string|null $orderby Order by clause (e.g., "score DESC").
		 * @param int         $offset  Starting row (0-based).
		 * @param int         $length  Number of rows (max 100).
		 * @return array|WP_Error Filtered rows or error.
		 */
		public function filter( $dataset, $config = 'default', $split = 'train', $where = '', $orderby = null, $offset = 0, $length = null ) {
			$dataset = sanitize_text_field( $dataset );
			$config  = sanitize_text_field( $config );
			$split   = sanitize_text_field( $split );
			$where   = sanitize_textarea_field( $where );
			$orderby = $orderby ? sanitize_text_field( $orderby ) : null;
			$offset  = absint( $offset );
			$length  = null === $length ? $this->get_default_limit() : min( 100, absint( $length ) );

			if ( empty( $where ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_empty_where',
					__( 'Filter expression (where clause) cannot be empty.', 'wp-mcp-ai' )
				);
			}

			$params = array(
				'dataset' => $dataset,
				'config'  => $config,
				'split'   => $split,
				'where'   => $where,
				'offset'  => $offset,
				'length'  => $length,
			);

			if ( ! empty( $orderby ) ) {
				$params['orderby'] = $orderby;
			}

			// Don't cache filter results as aggressively.
			$cache_key = 'filter_' . md5( wp_json_encode( $params ) );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/filter', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response, 1800 ); // 30 minutes for filters.

			return $response;
		}

		/**
		 * Get dataset statistics.
		 *
		 * @param string $dataset Dataset name.
		 * @param string $config  Configuration name.
		 * @param string $split   Split name.
		 * @return array|WP_Error Statistics or error.
		 */
		public function get_statistics( $dataset, $config = 'default', $split = 'train' ) {
			$dataset = sanitize_text_field( $dataset );
			$config  = sanitize_text_field( $config );
			$split   = sanitize_text_field( $split );

			$params = array(
				'dataset' => $dataset,
				'config'  => $config,
				'split'   => $split,
			);

			$cache_key = 'statistics_' . md5( wp_json_encode( $params ) );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/statistics', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Get Parquet file URLs for a dataset.
		 *
		 * @param string $dataset Dataset name.
		 * @return array|WP_Error Parquet file information or error.
		 */
		public function get_parquet( $dataset ) {
			$dataset = sanitize_text_field( $dataset );

			$cache_key = 'parquet_' . md5( $dataset );
			$cached    = $this->get_cached_result( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$response = $this->make_request( '/parquet', array( 'dataset' => $dataset ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_result( $cache_key, $response );

			return $response;
		}

		/**
		 * Make an API request to the Dataset Viewer.
		 *
		 * @param string $endpoint API endpoint (e.g., '/is-valid').
		 * @param array  $params   Query parameters.
		 * @return array|WP_Error Response data or error.
		 */
		protected function make_request( $endpoint, $params = array() ) {
			// Check rate limit.
			$rate_check = $this->check_rate_limit();
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}

			$url = self::BASE_URL . $endpoint . '?' . http_build_query( $params );

			$headers = array(
				'Accept' => 'application/json',
			);

			// Add authorization header if token is configured.
			$api_token = $this->get_api_token();
			if ( ! empty( $api_token ) ) {
				$headers['Authorization'] = 'Bearer ' . $api_token;
			}

			$args = array(
				'timeout' => 30,
				'headers' => $headers,
			);

			WP_MCP_AI_Logger::log_event(
				'huggingface_datasets_request',
				'Making request to Dataset Viewer API',
				array(
					'endpoint' => $endpoint,
					'dataset'  => isset( $params['dataset'] ) ? $params['dataset'] : 'unknown',
				)
			);

			$response = wp_remote_get( $url, $args );

			return $this->handle_response( $response, $endpoint, $params );
		}

		/**
		 * Handle API response.
		 *
		 * @param array|WP_Error $response HTTP response.
		 * @param string         $endpoint Endpoint for context.
		 * @param array          $params   Request parameters for context.
		 * @return array|WP_Error Processed response or error.
		 */
		protected function handle_response( $response, $endpoint = '', $params = array() ) {
			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'huggingface_datasets_connection_failed',
					'Failed to connect to HuggingFace Dataset Viewer API',
					array( 'error' => $response->get_error_message() )
				);

				return new WP_Error(
					'wp_mcp_ai_hf_datasets_connection_failed',
					__( 'Failed to connect to HuggingFace Dataset Viewer API.', 'wp-mcp-ai' ),
					array( 'error' => $response->get_error_message() )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== $status_code ) {
				WP_MCP_AI_Logger::log_error(
					'huggingface_datasets_api_error',
					'HuggingFace Dataset Viewer API returned error',
					array(
						'status'   => $status_code,
						'endpoint' => $endpoint,
						'body'     => substr( $body, 0, 500 ),
						'dataset'  => isset( $params['dataset'] ) ? $params['dataset'] : 'unknown',
					)
				);

				// Provide specific error message for 404 errors with dataset suggestions.
				if ( 404 === $status_code && isset( $params['dataset'] ) ) {
					$dataset       = $params['dataset'];
					$error_message = sprintf(
						/* translators: %s: Dataset name */
						__( 'Dataset "%s" not found on HuggingFace Hub.', 'wp-mcp-ai' ),
						$dataset
					);

					// Add helpful suggestions for common renamed datasets.
					$dataset_suggestions = $this->get_dataset_name_suggestions( $dataset );
					if ( ! empty( $dataset_suggestions ) ) {
						$error_message .= ' ' . sprintf(
							/* translators: %s: Suggested dataset name */
							__( 'Did you mean: %s?', 'wp-mcp-ai' ),
							implode( ', ', array_map( function ( $suggestion ) {
								return '"' . $suggestion . '"';
							}, $dataset_suggestions ) )
						);
					} else {
						$error_message .= ' ' . __( 'Please verify the dataset name at https://huggingface.co/datasets', 'wp-mcp-ai' );
					}

					return new WP_Error(
						'wp_mcp_ai_hf_datasets_not_found',
						$error_message,
						array(
							'status'      => $status_code,
							'dataset'     => $dataset,
							'suggestions' => $dataset_suggestions,
						)
					);
				}

				// Generic error for other status codes.
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'HuggingFace Dataset Viewer API returned error: %d', 'wp-mcp-ai' ),
						$status_code
					),
					array(
						'status' => $status_code,
						'body'   => $body,
					)
				);
			}

			$data = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'huggingface_datasets_invalid_json',
					'Invalid JSON response from HuggingFace Dataset Viewer API',
					array(
						'endpoint' => $endpoint,
						'body'     => substr( $body, 0, 500 ),
					)
				);

				return new WP_Error(
					'wp_mcp_ai_hf_datasets_invalid_json',
					__( 'Invalid JSON response from HuggingFace Dataset Viewer API.', 'wp-mcp-ai' ),
					array( 'body' => $body )
				);
			}

			return $data;
		}

		/**
		 * Check rate limit for current user.
		 *
		 * @return true|WP_Error True if allowed, error if rate limited.
		 */
		protected function check_rate_limit() {
			$user_id = get_current_user_id();
			$key     = 'wp_mcp_ai_hf_datasets_rate_limit_' . $user_id;
			$count   = get_transient( $key );

			if ( false === $count ) {
				set_transient( $key, 1, MINUTE_IN_SECONDS );
				return true;
			}

			$max_requests = apply_filters( 'wp_mcp_ai_hf_datasets_rate_limit', 60 );

			if ( $count >= $max_requests ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_rate_limited',
					__( 'Rate limit exceeded. Please try again later.', 'wp-mcp-ai' ),
					array( 'retry_after' => 60 )
				);
			}

			set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
			return true;
		}

		/**
		 * Cache a result.
		 *
		 * @param string   $key  Cache key.
		 * @param mixed    $data Data to cache.
		 * @param int|null $ttl  Time to live in seconds (null = use default).
		 * @return bool True on success.
		 */
		protected function cache_result( $key, $data, $ttl = null ) {
			if ( null === $ttl ) {
				$ttl = $this->get_cache_ttl();
			}

			$cache_key = self::CACHE_GROUP . '_' . $key;
			return set_transient( $cache_key, $data, $ttl );
		}

		/**
		 * Get cached result.
		 *
		 * @param string $key Cache key.
		 * @return mixed|false Cached data or false if not found.
		 */
		protected function get_cached_result( $key ) {
			$cache_key = self::CACHE_GROUP . '_' . $key;
			return get_transient( $cache_key );
		}

		/**
		 * Clear all cached results.
		 *
		 * @return int Number of transients deleted.
		 */
		public function clear_cache() {
			global $wpdb;

			$pattern = $wpdb->esc_like( '_transient_' . self::CACHE_GROUP . '_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$pattern,
					$wpdb->esc_like( '_transient_timeout_' . self::CACHE_GROUP . '_' ) . '%'
				)
			);

			WP_MCP_AI_Logger::log_event(
				'huggingface_datasets_cache_cleared',
				'Cleared HuggingFace Datasets cache',
				array( 'transients_deleted' => $count )
			);

			return $count;
		}

		/**
		 * Test the connection to the Dataset Viewer API.
		 *
		 * @return array|WP_Error Test result or error.
		 */
		public function test_connection() {
			// Test with a well-known public dataset.
			$result = $this->is_valid( 'squad' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'status'  => 'success',
				'message' => __( 'Successfully connected to HuggingFace Dataset Viewer API.', 'wp-mcp-ai' ),
			);
		}

		/**
		 * Get dataset name suggestions for common renamed or moved datasets.
		 *
		 * @param string $dataset The dataset name that was not found.
		 * @return array Array of suggested dataset names.
		 */
		protected function get_dataset_name_suggestions( $dataset ) {
			// Map of old/common names to their current canonical names.
			// All names verified against HuggingFace Hub as of December 2024.
			$dataset_map = array(
				// Text Classification & Sentiment Analysis.
				'imdb'                => 'stanfordnlp/imdb',

				// Question Answering.
				'squad'               => 'rajpurkar/squad',
				'squad_v2'            => 'rajpurkar/squad_v2',

				// NLU Benchmarks.
				'glue'                => 'nyu-mll/glue',
				'super_glue'          => 'super_glue',
				'superglue'           => 'super_glue',

				// Machine Translation.
				'wmt'                 => 'wmt/wmt14',
				'wmt14'               => 'wmt/wmt14',
				'wmt16'               => 'wmt/wmt16',
				'wmt17'               => 'wmt/wmt17',
				'wmt19'               => 'wmt/wmt19',

				// Summarization.
				'cnn_dailymail'       => 'abisee/cnn_dailymail',
				'multi_news'          => 'alexfabbri/multi_news',
				'xsum'                => 'EdinburghNLP/xsum',

				// Speech & Audio.
				'common_voice'        => 'mozilla-foundation/common_voice_17_0',
				'librispeech'         => 'openslr/librispeech_asr',
				'librispeech_asr'     => 'openslr/librispeech_asr',

				// General Text Corpora.
				'wikipedia'           => 'wikimedia/wikipedia',
				'bookcorpus'          => 'bookcorpus',
				'c4'                  => 'allenai/c4',

				// Code.
				'code_search_net'     => 'code_search_net',
				'the_pile'            => 'EleutherAI/pile',
			);

			// Normalize dataset name for comparison.
			$normalized_dataset = strtolower( trim( $dataset ) );

			// Check if we have a direct mapping.
			if ( isset( $dataset_map[ $normalized_dataset ] ) ) {
				return array( $dataset_map[ $normalized_dataset ] );
			}

			// Check if the dataset might be missing an organization prefix.
			$suggestions = array();
			foreach ( $dataset_map as $old_name => $new_name ) {
				// If the provided name matches the end part of a known dataset.
				if ( strpos( $new_name, '/' ) !== false ) {
					$parts = explode( '/', $new_name );
					if ( strtolower( end( $parts ) ) === $normalized_dataset ) {
						$suggestions[] = $new_name;
					}
				}
			}

			return $suggestions;
		}
	}
}
