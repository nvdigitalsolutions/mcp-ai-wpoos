<?php
/**
 * Correlation ID Tracker for distributed tracing.
 *
 * Implements correlation ID generation and tracking across REST API requests,
 * tool executions, and logging for distributed tracing support.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages correlation IDs for request tracing.
 */
class WP_MCP_AI_Correlation_Tracker {

	/**
	 * HTTP header name for correlation ID.
	 */
	const HEADER_NAME = 'X-Correlation-ID';

	/**
	 * Meta key prefix for storing correlation IDs.
	 */
	const META_PREFIX = '_wp_mcp_ai_correlation_';

	/**
	 * Current correlation ID for this request.
	 *
	 * @var string|null
	 */
	protected static $current_correlation_id = null;

	/**
	 * Initialize correlation tracking.
	 */
	public static function init() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_correlation_header' ), 10, 4 );
		add_action( 'wp_mcp_ai_log_entry', array( __CLASS__, 'add_correlation_to_log' ), 5, 4 );
	}

	/**
	 * Get or generate correlation ID for current request.
	 *
	 * @param bool $force_new Force generation of new correlation ID.
	 * @return string Correlation ID.
	 */
	public static function get_correlation_id( $force_new = false ) {
		if ( $force_new || null === self::$current_correlation_id ) {
			// Check if correlation ID provided in request header.
			if ( ! $force_new && isset( $_SERVER['HTTP_X_CORRELATION_ID'] ) ) {
				$incoming_id = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CORRELATION_ID'] ) );
				if ( self::is_valid_correlation_id( $incoming_id ) ) {
					self::$current_correlation_id = $incoming_id;
					return self::$current_correlation_id;
				}
			}

			// Generate new correlation ID.
			self::$current_correlation_id = self::generate_correlation_id();
		}

		return self::$current_correlation_id;
	}

	/**
	 * Generate a new correlation ID.
	 *
	 * Format: wpmcp-{timestamp}-{uniqid}-{random}
	 *
	 * @return string Correlation ID.
	 */
	public static function generate_correlation_id() {
		return sprintf(
			'wpmcp-%s-%s-%s',
			time(),
			uniqid( '', true ),
			wp_generate_password( 8, false, false )
		);
	}

	/**
	 * Validate correlation ID format.
	 *
	 * @param string $correlation_id Correlation ID to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_correlation_id( $correlation_id ) {
		// Allow alphanumeric, hyphens, dots, and underscores.
		// Max length 128 characters.
		return is_string( $correlation_id ) &&
			preg_match( '/^[a-zA-Z0-9._-]{1,128}$/', $correlation_id );
	}

	/**
	 * Add correlation ID to REST API response headers.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public static function add_correlation_header( $served, $result, $request, $server ) {
		if ( ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		// Only add to our endpoints.
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/mcp-ai/' ) ) {
			return $served;
		}

		$correlation_id = self::get_correlation_id();
		$result->header( self::HEADER_NAME, $correlation_id );

		// Also add to response body for easy access.
		$data = $result->get_data();
		if ( is_array( $data ) ) {
			$data['correlation_id'] = $correlation_id;
			$result->set_data( $data );
		}

		return $served;
	}

	/**
	 * Add correlation ID to log entries.
	 *
	 * @param array  $entry      Log entry.
	 * @param string $type       Event type.
	 * @param string $message    Log message.
	 * @param array  $raw_context Raw context.
	 * @return array Modified log entry.
	 */
	public static function add_correlation_to_log( $entry, $type, $message, $raw_context ) {
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		if ( ! isset( $entry['context'] ) ) {
			$entry['context'] = array();
		}

		// Add correlation ID if not already present.
		if ( ! isset( $entry['context']['correlation_id'] ) ) {
			$entry['context']['correlation_id'] = self::get_correlation_id();
		}

		return $entry;
	}

	/**
	 * Store correlation ID for an entity (post, user, etc.).
	 *
	 * @param string $entity_type Entity type (post, user, comment, etc.).
	 * @param int    $entity_id   Entity ID.
	 * @param string $correlation_id Optional correlation ID. Uses current if not provided.
	 * @return bool True on success, false on failure.
	 */
	public static function store_correlation_id( $entity_type, $entity_id, $correlation_id = null ) {
		if ( null === $correlation_id ) {
			$correlation_id = self::get_correlation_id();
		}

		$meta_key = self::META_PREFIX . sanitize_key( $entity_type );

		switch ( $entity_type ) {
			case 'post':
			case 'assistant':
			case 'ai_peer':
				return update_post_meta( $entity_id, $meta_key, $correlation_id );

			case 'user':
				return update_user_meta( $entity_id, $meta_key, $correlation_id );

			case 'comment':
				return update_comment_meta( $entity_id, $meta_key, $correlation_id );

			default:
				/**
				 * Allow custom entity types to store correlation IDs.
				 *
				 * @since 1.0.0
				 *
				 * @param bool   $stored         Whether correlation ID was stored.
				 * @param string $entity_type    Entity type.
				 * @param int    $entity_id      Entity ID.
				 * @param string $correlation_id Correlation ID.
				 */
				return apply_filters( 'wp_mcp_ai_store_correlation_id', false, $entity_type, $entity_id, $correlation_id );
		}
	}

	/**
	 * Retrieve correlation ID for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @return string|false Correlation ID or false if not found.
	 */
	public static function get_entity_correlation_id( $entity_type, $entity_id ) {
		$meta_key = self::META_PREFIX . sanitize_key( $entity_type );

		switch ( $entity_type ) {
			case 'post':
			case 'assistant':
			case 'ai_peer':
				return get_post_meta( $entity_id, $meta_key, true );

			case 'user':
				return get_user_meta( $entity_id, $meta_key, true );

			case 'comment':
				return get_comment_meta( $entity_id, $meta_key, true );

			default:
				/**
				 * Allow custom entity types to retrieve correlation IDs.
				 *
				 * @since 1.0.0
				 *
				 * @param string|false $correlation_id Correlation ID or false.
				 * @param string       $entity_type    Entity type.
				 * @param int          $entity_id      Entity ID.
				 */
				return apply_filters( 'wp_mcp_ai_get_entity_correlation_id', false, $entity_type, $entity_id );
		}
	}

	/**
	 * Create a child correlation ID for nested operations.
	 *
	 * @param string $parent_id Optional parent correlation ID. Uses current if not provided.
	 * @param string $suffix    Optional suffix to append.
	 * @return string Child correlation ID.
	 */
	public static function create_child_correlation_id( $parent_id = null, $suffix = '' ) {
		if ( null === $parent_id ) {
			$parent_id = self::get_correlation_id();
		}

		if ( empty( $suffix ) ) {
			$suffix = wp_generate_password( 6, false, false );
		}

		return $parent_id . '-' . $suffix;
	}

	/**
	 * Reset the current correlation ID (mainly for testing).
	 */
	public static function reset() {
		self::$current_correlation_id = null;
	}
}
