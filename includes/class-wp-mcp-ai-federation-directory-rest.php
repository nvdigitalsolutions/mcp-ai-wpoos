<?php
/**
 * Federation directory REST API endpoints.
 *
 * Provides endpoints for peer registration, discovery, and search.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles REST API endpoints for the AI peer directory.
 */
class WP_MCP_AI_Federation_Directory_REST {

	const REST_NAMESPACE = 'ai-dir/v1';

	/**
	 * Register REST API routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all directory REST routes.
	 */
	public function register_routes() {
		// POST /ai-dir/v1/peers/register - Register a new peer.
		register_rest_route(
			self::REST_NAMESPACE,
			'/peers/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_peer' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'wellknown_url' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// GET /ai-dir/v1/peers - List all peers.
		register_rest_route(
			self::REST_NAMESPACE,
			'/peers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_peers' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'default'           => 20,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default'           => 1,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'default'           => 'healthy',
						'type'              => 'string',
						'enum'              => array( 'all', 'healthy', 'degraded', 'down' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /ai-dir/v1/peers/{id} - Get peer details.
		register_rest_route(
			self::REST_NAMESPACE,
			'/peers/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_peer' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// GET /ai-dir/v1/search - Search peers by capability/region/policy.
		register_rest_route(
			self::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_peers' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'capability'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'region'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'data_tag'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'max_latency_ms' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'max_price'      => array(
						'type'              => 'number',
						'sanitize_callback' => 'floatval',
					),
					'limit'          => array(
						'default'           => 10,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /ai-dir/v1/reverify/{id} - Trigger peer reverification.
		register_rest_route(
			self::REST_NAMESPACE,
			'/reverify/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reverify_peer' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// POST /ai-dir/v1/report/{id} - Report peer issues.
		register_rest_route(
			self::REST_NAMESPACE,
			'/report/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'report_peer' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'reason'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'details' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Check if the current user has admin permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if user can manage options, WP_Error otherwise.
	 */
	public function check_admin_permission( $request = null ) {
		// Verify nonce for logged-in users.
		if ( is_user_logged_in() && $request instanceof WP_REST_Request ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nonce',
					__( 'Authentication nonce is required. Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ).', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
						),
					)
				);
			}
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Register a new peer from its well-known URL.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function register_peer( $request ) {
		$wellknown_url = $request->get_param( 'wellknown_url' );

		// Fetch the well-known document.
		$response = wp_remote_get(
			$wellknown_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-MCP-AI-Federation/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch well-known document: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'invalid_response',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Well-known endpoint returned status code %d', 'wp-mcp-ai' ),
					$status_code
				),
				array( 'status' => 400 )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_json',
				__( 'Well-known document is not valid JSON', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Validate required fields.
		$required_fields = array( 'mcp', 'jwks_uri', 'capabilities' );
		foreach ( $required_fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error(
					'missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Required field missing: %s', 'wp-mcp-ai' ),
						$field
					),
					array( 'status' => 400 )
				);
			}
		}

		// Verify JWKS is reachable.
		$jwks_response = wp_remote_get(
			$data['jwks_uri'],
			array(
				'timeout'    => 5,
				'user-agent' => 'WP-MCP-AI-Federation/1.0',
			)
		);

		$jwks_reachable = ! is_wp_error( $jwks_response ) && 200 === wp_remote_retrieve_response_code( $jwks_response );

		// Create or update the peer post.
		$site_url  = isset( $data['site_url'] ) ? $data['site_url'] : '';
		$site_name = isset( $data['site_name'] ) ? $data['site_name'] : parse_url( $wellknown_url, PHP_URL_HOST );

		// Check if peer already exists.
		$existing_peer = $this->find_peer_by_wellknown_url( $wellknown_url );

		$post_data = array(
			'post_title'   => $site_name,
			'post_type'    => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
			'post_status'  => 'publish',
			'post_content' => '',
		);

		if ( $existing_peer ) {
			$post_data['ID'] = $existing_peer;
			$peer_id         = wp_update_post( $post_data );
		} else {
			$peer_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $peer_id ) ) {
			return $peer_id;
		}

		// Store peer metadata.
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_SITE_NAME, sanitize_text_field( $site_name ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_SITE_URL, esc_url_raw( $site_url ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_WELLKNOWN_URL, esc_url_raw( $wellknown_url ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_MCP_URL, esc_url_raw( $data['mcp']['url'] ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_JWKS_URI, esc_url_raw( $data['jwks_uri'] ) );

		if ( isset( $data['openapi']['url'] ) ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_OPENAPI_URL, esc_url_raw( $data['openapi']['url'] ) );
		}

		// Store arrays as JSON.
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, wp_json_encode( $data['capabilities'] ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_REGIONS, wp_json_encode( isset( $data['regions'] ) ? $data['regions'] : array( 'global' ) ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_DATA_TAGS, wp_json_encode( isset( $data['data_tags'] ) ? $data['data_tags'] : array() ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_QUOTAS, wp_json_encode( isset( $data['quotas'] ) ? $data['quotas'] : array() ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_PRICE_HINTS, wp_json_encode( isset( $data['price_hints'] ) ? $data['price_hints'] : array() ) );

		// Set health status.
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, $jwks_reachable ? 'healthy' : 'degraded' );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, current_time( 'mysql', true ) );

		if ( ! $jwks_reachable ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR, 'JWKS endpoint not reachable' );
		}

		// Store verification data.
		$verification_data = array(
			'wellknown_fetched_at' => current_time( 'mysql', true ),
			'jwks_reachable'       => $jwks_reachable,
			'raw_document'         => $data,
		);
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_VERIFICATION_DATA, wp_json_encode( $verification_data ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'peer_id' => $peer_id,
				'message' => __( 'Peer registered successfully', 'wp-mcp-ai' ),
			),
			201
		);
	}

	/**
	 * Find a peer by its well-known URL.
	 *
	 * @param string $wellknown_url Well-known URL.
	 * @return int|null Peer ID or null if not found.
	 */
	protected function find_peer_by_wellknown_url( $wellknown_url ) {
		$query = new WP_Query(
			array(
				'post_type'              => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page'         => 1,
				'meta_query'             => array(
					array(
						'key'   => WP_MCP_AI_AI_Peer_CPT::META_WELLKNOWN_URL,
						'value' => $wellknown_url,
					),
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,  // Performance: Skip counting.
				'update_post_term_cache' => false, // Performance: Skip term cache.
			)
		);

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return null;
	}

	/**
	 * List all peers with optional filtering.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_peers( $request ) {
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$status   = $request->get_param( 'status' );

		$query_args = array(
			'post_type'              => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_term_cache' => false, // Performance: Skip term cache for peers.
			'update_post_meta_cache' => true,  // Keep meta cache as we use meta data.
		);

		if ( 'all' !== $status ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS,
					'value' => $status,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$peers = array();
		foreach ( $query->posts as $post ) {
			$peers[] = $this->format_peer_response( $post->ID );
		}

		return new WP_REST_Response(
			array(
				'peers'       => $peers,
				'total'       => $query->found_posts,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Get a single peer by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_peer( $request ) {
		$peer_id = $request->get_param( 'id' );

		$post = get_post( $peer_id );
		if ( ! $post || WP_MCP_AI_AI_Peer_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'peer_not_found',
				__( 'Peer not found', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			$this->format_peer_response( $peer_id ),
			200
		);
	}

	/**
	 * Search peers by capability, region, and other criteria.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function search_peers( $request ) {
		$capability     = $request->get_param( 'capability' );
		$region         = $request->get_param( 'region' );
		$data_tag       = $request->get_param( 'data_tag' );
		$max_latency_ms = $request->get_param( 'max_latency_ms' );
		$max_price      = $request->get_param( 'max_price' );
		$limit          = $request->get_param( 'limit' );

		// Get all healthy peers.
		$query = new WP_Query(
			array(
				'post_type'              => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page'         => -1,
				'meta_query'             => array(
					array(
						'key'   => WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS,
						'value' => 'healthy',
					),
				),
				'no_found_rows'          => true,  // Performance: Skip counting.
				'update_post_term_cache' => false, // Performance: Skip term cache.
				'update_post_meta_cache' => true,  // Keep meta cache for peer data.
			)
		);

		$candidates = array();

		foreach ( $query->posts as $post ) {
			$peer_data = $this->get_peer_data( $post->ID );

			// Filter by capability.
			if ( $capability && ! in_array( $capability, $peer_data['capabilities'], true ) ) {
				continue;
			}

			// Filter by region.
			if ( $region && ! in_array( $region, $peer_data['regions'], true ) && ! in_array( 'global', $peer_data['regions'], true ) ) {
				continue;
			}

			// Filter by data tag.
			if ( $data_tag && ! in_array( $data_tag, $peer_data['data_tags'], true ) ) {
				continue;
			}

			// Filter by latency.
			if ( $max_latency_ms && $peer_data['latency_p50'] > $max_latency_ms ) {
				continue;
			}

			// Calculate score for ranking.
			$score = $this->calculate_peer_score( $peer_data, $region, $data_tag, $max_price );

			$candidates[] = array(
				'peer_id' => $post->ID,
				'score'   => $score,
				'data'    => $peer_data,
			);
		}

		// Sort by score (higher is better).
		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Limit results.
		$candidates = array_slice( $candidates, 0, $limit );

		// Format results.
		$results = array();
		foreach ( $candidates as $candidate ) {
			$results[] = array(
				'peer'           => $candidate['data']['site_name'],
				'peer_id'        => $candidate['peer_id'],
				'capability'     => $capability,
				'endpoint'       => array(
					'mcp_url' => $candidate['data']['mcp_url'],
				),
				'jwks_uri'       => $candidate['data']['jwks_uri'],
				'region'         => $candidate['data']['regions'],
				'latency_ms_p50' => $candidate['data']['latency_p50'],
				'price_hint'     => $candidate['data']['price_hints'],
				'data_tags'      => $candidate['data']['data_tags'],
				'score'          => round( $candidate['score'], 2 ),
			);
		}

		return new WP_REST_Response(
			array(
				'results' => $results,
				'query'   => array(
					'capability'     => $capability,
					'region'         => $region,
					'data_tag'       => $data_tag,
					'max_latency_ms' => $max_latency_ms,
					'max_price'      => $max_price,
				),
			),
			200
		);
	}

	/**
	 * Calculate peer score for ranking.
	 *
	 * Ranking algorithm:
	 * 1. Prefer matching region/data tags (filter already applied)
	 * 2. Prefer lowest latency
	 *
	 * Note: Price-based scoring is not currently implemented.
	 *
	 * @param array  $peer_data Peer data.
	 * @param string $region Requested region.
	 * @param string $data_tag Requested data tag.
	 * @param float  $max_price Max price threshold (reserved for future use).
	 * @return float Score (0-100).
	 */
	protected function calculate_peer_score( $peer_data, $region, $data_tag, $max_price ) {
		$score = 50.0; // Base score.

		// Region match bonus.
		if ( $region && in_array( $region, $peer_data['regions'], true ) ) {
			$score += 20.0;
		}

		// Data tag match bonus.
		if ( $data_tag && in_array( $data_tag, $peer_data['data_tags'], true ) ) {
			$score += 15.0;
		}

		// Latency score (lower is better).
		$latency = $peer_data['latency_p50'];
		if ( $latency > 0 ) {
			$latency_score = max( 0, 20 - ( $latency / 50 ) ); // 1000ms = 0 points, 0ms = 20 points.
			$score        += $latency_score;
		}

		return min( 100.0, $score );
	}

	/**
	 * Get peer data for filtering and ranking.
	 *
	 * @param int $peer_id Peer ID.
	 * @return array Peer data.
	 */
	protected function get_peer_data( $peer_id ) {
		$capabilities = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, true );
		$regions      = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_REGIONS, true );
		$data_tags    = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_DATA_TAGS, true );
		$price_hints  = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_PRICE_HINTS, true );

		return array(
			'site_name'    => get_the_title( $peer_id ),
			'site_url'     => get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_SITE_URL, true ),
			'mcp_url'      => get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_MCP_URL, true ),
			'jwks_uri'     => get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_JWKS_URI, true ),
			'capabilities' => is_string( $capabilities ) ? json_decode( $capabilities, true ) : array(),
			'regions'      => is_string( $regions ) ? json_decode( $regions, true ) : array( 'global' ),
			'data_tags'    => is_string( $data_tags ) ? json_decode( $data_tags, true ) : array(),
			'price_hints'  => is_string( $price_hints ) ? json_decode( $price_hints, true ) : array(),
			'latency_p50'  => absint( get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, true ) ),
		);
	}

	/**
	 * Format peer response for API output.
	 *
	 * @param int $peer_id Peer ID.
	 * @return array Formatted peer data.
	 */
	protected function format_peer_response( $peer_id ) {
		$peer_data = $this->get_peer_data( $peer_id );

		return array(
			'id'            => $peer_id,
			'site_name'     => $peer_data['site_name'],
			'site_url'      => $peer_data['site_url'],
			'mcp_url'       => $peer_data['mcp_url'],
			'jwks_uri'      => $peer_data['jwks_uri'],
			'capabilities'  => $peer_data['capabilities'],
			'regions'       => $peer_data['regions'],
			'data_tags'     => $peer_data['data_tags'],
			'health_status' => get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true ),
			'latency_p50'   => $peer_data['latency_p50'],
			'last_verified' => get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, true ),
		);
	}

	/**
	 * Trigger peer reverification.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function reverify_peer( $request ) {
		$peer_id = $request->get_param( 'id' );

		$post = get_post( $peer_id );
		if ( ! $post || WP_MCP_AI_AI_Peer_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'peer_not_found',
				__( 'Peer not found', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Get well-known URL and re-fetch.
		$wellknown_url = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_WELLKNOWN_URL, true );

		if ( ! $wellknown_url ) {
			return new WP_Error(
				'no_wellknown_url',
				__( 'Peer has no well-known URL', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Trigger verification via the verification helper.
		$result = WP_MCP_AI_Federation_Peer_Verifier::verify_peer( $peer_id, $wellknown_url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Peer reverified successfully', 'wp-mcp-ai' ),
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Report a peer issue.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function report_peer( $request ) {
		$peer_id = $request->get_param( 'id' );
		$reason  = $request->get_param( 'reason' );
		$details = $request->get_param( 'details' );

		$post = get_post( $peer_id );
		if ( ! $post || WP_MCP_AI_AI_Peer_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'peer_not_found',
				__( 'Peer not found', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Log the report.
		WP_MCP_AI_Logger::log_event(
			'peer_reported',
			'Peer issue reported',
			array(
				'peer_id' => $peer_id,
				'reason'  => $reason,
				'details' => $details,
				'user_id' => get_current_user_id(),
			)
		);

		// Optionally update peer status or add note.
		$reports = get_post_meta( $peer_id, '_wp_mcp_ai_peer_reports', true );
		if ( ! is_array( $reports ) ) {
			$reports = array();
		}

		$reports[] = array(
			'reason'      => $reason,
			'details'     => $details,
			'reported_at' => current_time( 'mysql', true ),
			'reported_by' => get_current_user_id(),
		);

		update_post_meta( $peer_id, '_wp_mcp_ai_peer_reports', $reports );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Report submitted successfully', 'wp-mcp-ai' ),
			),
			200
		);
	}
}
