<?php
/**
 * SSE Session Store for legacy MCP HTTP+SSE transport.
 *
 * Tracks sessions created by the legacy SSE handshake (GET /mcp with an
 * SSE-only Accept header) and the queue of JSON-RPC responses waiting to be
 * delivered on each session's event stream.
 *
 * Mirrors the session-ownership model of the MCP Python SDK's
 * SseServerTransport: a session is owned by the credential that created it,
 * and message POSTs must present the same credential or they are rejected
 * exactly as if the session did not exist.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.1.55
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy SSE session registry and per-session response queue.
 */
class WP_MCP_AI_SSE_Session_Store {

	/**
	 * Transient prefix for per-session records.
	 *
	 * @since 1.1.55
	 * @var string
	 */
	const SESSION_PREFIX = 'wp_mcp_ai_sse_session_';

	/**
	 * Option key for the session registry (id => expiry) used for counting
	 * and garbage collection. Transients cannot be listed, so the registry
	 * is maintained separately and pruned lazily.
	 *
	 * @since 1.1.55
	 * @var string
	 */
	const REGISTRY_KEY = 'wp_mcp_ai_sse_session_registry';

	/**
	 * Create a new session bound to a credential hash.
	 *
	 * @since 1.1.55
	 *
	 * @param string $credential_hash Hash of the credential that owns the session.
	 * @return string|WP_Error Session ID on success, WP_Error when at capacity.
	 */
	public function create( $credential_hash ) {
		$registry = $this->get_registry();

		/**
		 * Filter the maximum number of legacy SSE sessions allowed per credential.
		 *
		 * @since 1.1.55
		 *
		 * @param int $max_per_credential Maximum sessions per credential. Default 5.
		 */
		$max_per_credential = (int) apply_filters( 'wp_mcp_ai_sse_max_per_credential', 5 );

		/**
		 * Filter the maximum number of legacy SSE sessions allowed globally.
		 *
		 * Each open session pins a PHP-FPM worker, so this cap protects the
		 * server pool. Default 20.
		 *
		 * @since 1.1.55
		 *
		 * @param int $max_total Maximum sessions across all credentials. Default 20.
		 */
		$max_total = (int) apply_filters( 'wp_mcp_ai_sse_max_total', 20 );

		if ( count( $registry ) >= $max_total ) {
			return new WP_Error(
				'wp_mcp_ai_sse_capacity',
				sprintf(
					/* translators: %d: maximum concurrent sessions */
					__( 'Maximum %d concurrent SSE sessions reached. Please try again shortly.', 'mcp-ai-wpoos' ),
					$max_total
				),
				array( 'status' => 503 )
			);
		}

		$credential_count = 0;
		foreach ( $registry as $record ) {
			if ( isset( $record['cred'] ) && hash_equals( $credential_hash, $record['cred'] ) ) {
				++$credential_count;
			}
		}

		if ( $credential_count >= $max_per_credential ) {
			return new WP_Error(
				'wp_mcp_ai_sse_per_credential_capacity',
				sprintf(
					/* translators: %d: maximum concurrent sessions per credential */
					__( 'Maximum %d concurrent SSE sessions per credential reached. Close an existing connection and try again.', 'mcp-ai-wpoos' ),
					$max_per_credential
				),
				array( 'status' => 429 )
			);
		}

		$session_id = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: bin2hex( random_bytes( 16 ) );

		$ttl = $this->get_ttl();

		$record = array(
			'cred'    => $credential_hash,
			'created' => time(),
			'expires' => time() + $ttl,
			'queue'   => array(),
		);

		set_transient( self::SESSION_PREFIX . $session_id, $record, $ttl );

		$registry[ $session_id ] = array(
			'cred'    => $credential_hash,
			'expires' => time() + $ttl,
		);
		update_option( self::REGISTRY_KEY, $registry, false );

		return $session_id;
	}

	/**
	 * Check whether a session exists and is owned by the given credential.
	 *
	 * Responds false for unknown sessions, expired sessions, and ownership
	 * mismatches alike so callers cannot probe for session existence.
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id       Session ID.
	 * @param string $credential_hash  Credential hash presented by the request.
	 * @return bool True when the session exists and is owned by the credential.
	 */
	public function is_owner( $session_id, $credential_hash ) {
		if ( ! $this->is_valid_id( $session_id ) ) {
			return false;
		}

		$record = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! is_array( $record ) || empty( $record['cred'] ) ) {
			return false;
		}

		return hash_equals( $credential_hash, $record['cred'] );
	}

	/**
	 * Append a JSON-RPC response to a session's outbound queue.
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id         Session ID.
	 * @param array  $jsonrpc_response   JSON-RPC response array.
	 * @return bool True on success, false when the session is gone or the queue is full.
	 */
	public function enqueue( $session_id, $jsonrpc_response ) {
		if ( ! $this->is_valid_id( $session_id ) ) {
			return false;
		}

		$record = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! is_array( $record ) ) {
			return false;
		}

		/**
		 * Filter the maximum number of pending responses queued per session.
		 *
		 * @since 1.1.55
		 *
		 * @param int $queue_max Maximum queued responses. Default 50.
		 */
		$queue_max = (int) apply_filters( 'wp_mcp_ai_sse_queue_max', 50 );

		if ( count( $record['queue'] ) >= $queue_max ) {
			return false;
		}

		$record['queue'][] = $jsonrpc_response;

		// Refresh the transient TTL so active sessions do not expire mid-use.
		set_transient( self::SESSION_PREFIX . $session_id, $record, $this->get_ttl() );

		return true;
	}

	/**
	 * Pop all pending responses from a session's queue.
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id Session ID.
	 * @return array List of JSON-RPC response arrays (may be empty).
	 */
	public function drain( $session_id ) {
		if ( ! $this->is_valid_id( $session_id ) ) {
			return array();
		}

		$record = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! is_array( $record ) || empty( $record['queue'] ) ) {
			return array();
		}

		$queue           = $record['queue'];
		$record['queue'] = array();

		set_transient( self::SESSION_PREFIX . $session_id, $record, $this->get_ttl() );

		return $queue;
	}

	/**
	 * Refresh a session's expiry (keepalive heartbeat).
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id Session ID.
	 * @return void
	 */
	public function touch( $session_id ) {
		if ( ! $this->is_valid_id( $session_id ) ) {
			return;
		}

		$record = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! is_array( $record ) ) {
			return;
		}

		set_transient( self::SESSION_PREFIX . $session_id, $record, $this->get_ttl() );
	}

	/**
	 * Delete a session and its registry entry.
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id Session ID.
	 * @return void
	 */
	public function delete( $session_id ) {
		if ( ! $this->is_valid_id( $session_id ) ) {
			return;
		}

		delete_transient( self::SESSION_PREFIX . $session_id );

		$registry = $this->get_registry();
		unset( $registry[ $session_id ] );
		update_option( self::REGISTRY_KEY, $registry, false );
	}

	/**
	 * Get the configured session lifetime in seconds.
	 *
	 * @since 1.1.55
	 *
	 * @return int Session lifetime in seconds.
	 */
	protected function get_ttl() {
		/**
		 * Filter the maximum lifetime of a legacy SSE session in seconds.
		 *
		 * Each session pins a PHP-FPM worker, so this bounds resource usage.
		 * Clients reconnect with a fresh handshake when a stream closes.
		 *
		 * @since 1.1.55
		 *
		 * @param int $ttl Session lifetime in seconds. Default 1800 (30 min).
		 */
		return (int) apply_filters( 'wp_mcp_ai_sse_session_ttl', 1800 );
	}

	/**
	 * Get the session registry, pruning expired entries.
	 *
	 * @since 1.1.55
	 *
	 * @return array Registry map (id => record).
	 */
	protected function get_registry() {
		$registry = get_option( self::REGISTRY_KEY, array() );

		if ( ! is_array( $registry ) ) {
			return array();
		}

		$now = time();

		foreach ( $registry as $id => $record ) {
			if ( ! is_array( $record ) || ( isset( $record['expires'] ) && $record['expires'] < $now ) ) {
				unset( $registry[ $id ] );
			}
		}

		return $registry;
	}

	/**
	 * Validate a session ID format.
	 *
	 * @since 1.1.55
	 *
	 * @param string $session_id Session ID.
	 * @return bool True when the ID matches the expected UUID/hex format.
	 */
	protected function is_valid_id( $session_id ) {
		return is_string( $session_id )
			&& 1 === preg_match( '/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$|^[a-f0-9]{32}$/', $session_id );
	}
}
