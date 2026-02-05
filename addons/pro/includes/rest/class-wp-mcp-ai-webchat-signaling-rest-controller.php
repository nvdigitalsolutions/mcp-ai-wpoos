<?php
/**
 * REST API controller for WebChat self-hosted signaling.
 *
 * Provides REST endpoints for WebRTC signaling, eliminating the need
 * for an external WebSocket server. Supports peer discovery, offer/answer
 * exchange, and ICE candidate relay.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebChat Signaling REST Controller.
 *
 * Implements a self-hosted WebRTC signaling server using WordPress REST API
 * and Server-Sent Events for real-time peer-to-peer communication setup.
 */
class WP_MCP_AI_WebChat_Signaling_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Peer presence timeout in seconds.
	 */
	const PEER_TIMEOUT = 60;

	/**
	 * Maximum peers per room.
	 */
	const MAX_PEERS_PER_ROOM = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
		$this->rest_base = 'webchat';
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Check if self-hosted signaling is enabled.
		$settings = get_option( 'wp_mcp_ai_webchat_settings', array() );
		if ( empty( $settings['enable_self_hosted_signaling'] ) ) {
			return;
		}

		// Peer registration and presence.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/peers/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_peer' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => $this->get_register_peer_args(),
				),
			)
		);

		// Peer heartbeat.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/peers/(?P<peer_id>[a-zA-Z0-9_-]+)/heartbeat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'peer_heartbeat' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => array(
						'peer_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_peer_id' ),
						),
					),
				),
			)
		);

		// List active peers in a room.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rooms/(?P<room_id>[a-zA-Z0-9_-]+)/peers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_room_peers' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => array(
						'room_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// WebRTC offer/answer exchange.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/signal',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'signal' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => $this->get_signal_args(),
				),
			)
		);

		// ICE candidate exchange.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ice',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'exchange_ice_candidate' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => $this->get_ice_args(),
				),
			)
		);

		// Server-Sent Events stream for real-time updates.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stream',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'stream_events' ),
					'permission_callback' => array( $this, 'check_signaling_permission' ),
					'args'                => array(
						'room_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'peer_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_peer_id' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Check permission for signaling operations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_signaling_permission( $request ) {
		// Allow authenticated users.
		if ( is_user_logged_in() ) {
			return true;
		}

		// Check if anonymous chat is enabled.
		$settings = get_option( 'wp_mcp_ai_webchat_settings', array() );
		if ( ! empty( $settings['enable_anonymous_chat'] ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access WebChat signaling.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Validate peer ID format.
	 *
	 * @param string          $value   Peer ID.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool
	 */
	public function validate_peer_id( $value, $request, $param ) {
		return preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) === 1;
	}

	/**
	 * Get register peer arguments.
	 *
	 * @return array
	 */
	protected function get_register_peer_args() {
		return array(
			'peer_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_peer_id' ),
			),
			'room_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'user_name' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Anonymous',
			),
		);
	}

	/**
	 * Get signal arguments.
	 *
	 * @return array
	 */
	protected function get_signal_args() {
		return array(
			'from_peer' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_peer_id' ),
			),
			'to_peer' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_peer_id' ),
			),
			'room_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type' => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => array( 'offer', 'answer' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sdp' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Get ICE candidate arguments.
	 *
	 * @return array
	 */
	protected function get_ice_args() {
		return array(
			'from_peer' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_peer_id' ),
			),
			'to_peer' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_peer_id' ),
			),
			'room_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'candidate' => array(
				'required'          => true,
				'type'              => 'object',
			),
		);
	}

	/**
	 * Register a peer in a room.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_peer( $request ) {
		$peer_id   = $request->get_param( 'peer_id' );
		$room_id   = $request->get_param( 'room_id' );
		$user_name = $request->get_param( 'user_name' );

		// Check room capacity.
		$peers = $this->get_active_peers( $room_id );
		if ( count( $peers ) >= self::MAX_PEERS_PER_ROOM ) {
			return new WP_Error(
				'room_full',
				__( 'Room has reached maximum capacity.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Register peer.
		$peer_data = array(
			'peer_id'    => $peer_id,
			'room_id'    => $room_id,
			'user_name'  => $user_name,
			'user_id'    => get_current_user_id(),
			'registered' => time(),
			'last_seen'  => time(),
		);

		$this->store_peer( $room_id, $peer_id, $peer_data );

		// Notify other peers.
		$this->queue_event( $room_id, 'peer_joined', array(
			'peer_id'   => $peer_id,
			'user_name' => $user_name,
		), $peer_id );

		return rest_ensure_response( array(
			'success'  => true,
			'peer_id'  => $peer_id,
			'room_id'  => $room_id,
			'peers'    => $this->get_peer_list( $room_id, $peer_id ),
		) );
	}

	/**
	 * Update peer heartbeat.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function peer_heartbeat( $request ) {
		$peer_id = $request->get_param( 'peer_id' );
		$room_id = $request->get_param( 'room_id' );

		$peer_data = $this->get_peer( $room_id, $peer_id );
		if ( ! $peer_data ) {
			return new WP_Error(
				'peer_not_found',
				__( 'Peer not found in room.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$peer_data['last_seen'] = time();
		$this->store_peer( $room_id, $peer_id, $peer_data );

		return rest_ensure_response( array(
			'success' => true,
			'peer_id' => $peer_id,
		) );
	}

	/**
	 * List active peers in a room.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_room_peers( $request ) {
		$room_id = $request->get_param( 'room_id' );
		$peer_id = $request->get_param( 'peer_id' );

		$peers = $this->get_peer_list( $room_id, $peer_id );

		return rest_ensure_response( array(
			'success' => true,
			'room_id' => $room_id,
			'peers'   => $peers,
		) );
	}

	/**
	 * Exchange WebRTC signaling data (offer/answer).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function signal( $request ) {
		$from_peer = $request->get_param( 'from_peer' );
		$to_peer   = $request->get_param( 'to_peer' );
		$room_id   = $request->get_param( 'room_id' );
		$type      = $request->get_param( 'type' );
		$sdp       = $request->get_param( 'sdp' );

		// Verify both peers exist.
		if ( ! $this->get_peer( $room_id, $from_peer ) ) {
			return new WP_Error(
				'invalid_peer',
				__( 'Source peer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->get_peer( $room_id, $to_peer ) ) {
			return new WP_Error(
				'invalid_peer',
				__( 'Target peer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Queue signal event for target peer.
		$this->queue_event( $room_id, 'signal', array(
			'from_peer' => $from_peer,
			'type'      => $type,
			'sdp'       => $sdp,
		), null, $to_peer );

		return rest_ensure_response( array(
			'success' => true,
			'queued'  => true,
		) );
	}

	/**
	 * Exchange ICE candidate.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function exchange_ice_candidate( $request ) {
		$from_peer = $request->get_param( 'from_peer' );
		$to_peer   = $request->get_param( 'to_peer' );
		$room_id   = $request->get_param( 'room_id' );
		$candidate = $request->get_param( 'candidate' );

		// Verify both peers exist.
		if ( ! $this->get_peer( $room_id, $from_peer ) ) {
			return new WP_Error(
				'invalid_peer',
				__( 'Source peer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->get_peer( $room_id, $to_peer ) ) {
			return new WP_Error(
				'invalid_peer',
				__( 'Target peer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Queue ICE candidate event.
		$this->queue_event( $room_id, 'ice_candidate', array(
			'from_peer' => $from_peer,
			'candidate' => $candidate,
		), null, $to_peer );

		return rest_ensure_response( array(
			'success' => true,
			'queued'  => true,
		) );
	}

	/**
	 * Stream events via Server-Sent Events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function stream_events( $request ) {
		$room_id = $request->get_param( 'room_id' );
		$peer_id = $request->get_param( 'peer_id' );

		// Verify peer exists.
		if ( ! $this->get_peer( $room_id, $peer_id ) ) {
			return new WP_Error(
				'invalid_peer',
				__( 'Peer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Build SSE stream.
		$stream = $this->build_sse_stream( $room_id, $peer_id );

		// Prepare SSE headers.
		$response = new WP_REST_Response( $stream, 200 );
		$response->header( 'Content-Type', 'text/event-stream; charset=UTF-8' );
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'X-Accel-Buffering', 'no' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Build SSE event stream.
	 *
	 * @param string $room_id Room ID.
	 * @param string $peer_id Peer ID.
	 * @return string
	 */
	protected function build_sse_stream( $room_id, $peer_id ) {
		$stream         = '';
		$max_duration   = 300; // 5 minutes.
		$poll_interval  = 2;   // 2 seconds.
		$start_time     = time();
		$last_heartbeat = $start_time;

		// Send initial connection message.
		$stream .= $this->format_sse_event( 'connected', array(
			'peer_id' => $peer_id,
			'room_id' => $room_id,
		) );

		// Poll for events.
		while ( ( time() - $start_time ) < $max_duration ) {
			// Check connection status.
			if ( connection_aborted() ) {
				break;
			}

			// Send heartbeat.
			if ( ( time() - $last_heartbeat ) >= 15 ) {
				$stream .= $this->format_sse_event( 'heartbeat', array( 'timestamp' => time() ) );
				$last_heartbeat = time();
			}

			// Get pending events for this peer.
			$events = $this->get_pending_events( $room_id, $peer_id );
			foreach ( $events as $event ) {
				$stream .= $this->format_sse_event( $event['type'], $event['data'] );
			}

			// Update peer heartbeat.
			$peer_data = $this->get_peer( $room_id, $peer_id );
			if ( $peer_data ) {
				$peer_data['last_seen'] = time();
				$this->store_peer( $room_id, $peer_id, $peer_data );
			}

			// Sleep before next poll.
			sleep( $poll_interval );
		}

		return $stream;
	}

	/**
	 * Format SSE event.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Event data.
	 * @return string
	 */
	protected function format_sse_event( $event, $data ) {
		$json = wp_json_encode( $data );
		return sprintf( "event: %s\ndata: %s\n\n", $event, $json );
	}

	/**
	 * Store peer data.
	 *
	 * @param string $room_id   Room ID.
	 * @param string $peer_id   Peer ID.
	 * @param array  $peer_data Peer data.
	 * @return void
	 */
	protected function store_peer( $room_id, $peer_id, $peer_data ) {
		$key = 'wp_mcp_ai_webchat_peer_' . md5( $room_id . '_' . $peer_id );
		set_transient( $key, $peer_data, self::PEER_TIMEOUT );
	}

	/**
	 * Get peer data.
	 *
	 * @param string $room_id Room ID.
	 * @param string $peer_id Peer ID.
	 * @return array|false
	 */
	protected function get_peer( $room_id, $peer_id ) {
		$key = 'wp_mcp_ai_webchat_peer_' . md5( $room_id . '_' . $peer_id );
		return get_transient( $key );
	}

	/**
	 * Get active peers in a room.
	 *
	 * @param string $room_id Room ID.
	 * @return array
	 */
	protected function get_active_peers( $room_id ) {
		global $wpdb;

		// Query transients table for active peers.
		$pattern = '_transient_wp_mcp_ai_webchat_peer_' . md5( $room_id . '_' ) . '%';
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		$peers = array();
		foreach ( $results as $option_name ) {
			$peer_data = get_option( $option_name );
			if ( $peer_data && ( time() - $peer_data['last_seen'] ) < self::PEER_TIMEOUT ) {
				$peers[] = $peer_data;
			}
		}

		return $peers;
	}

	/**
	 * Get peer list for a room.
	 *
	 * @param string $room_id       Room ID.
	 * @param string $exclude_peer  Peer to exclude.
	 * @return array
	 */
	protected function get_peer_list( $room_id, $exclude_peer = null ) {
		$peers = $this->get_active_peers( $room_id );
		$list  = array();

		foreach ( $peers as $peer ) {
			if ( $exclude_peer && $peer['peer_id'] === $exclude_peer ) {
				continue;
			}

			$list[] = array(
				'peer_id'   => $peer['peer_id'],
				'user_name' => $peer['user_name'],
			);
		}

		return $list;
	}

	/**
	 * Queue an event for delivery.
	 *
	 * @param string      $room_id       Room ID.
	 * @param string      $event_type    Event type.
	 * @param array       $data          Event data.
	 * @param string|null $exclude_peer  Peer to exclude.
	 * @param string|null $target_peer   Specific target peer.
	 * @return void
	 */
	protected function queue_event( $room_id, $event_type, $data, $exclude_peer = null, $target_peer = null ) {
		$event = array(
			'type'      => $event_type,
			'data'      => $data,
			'timestamp' => time(),
		);

		if ( $target_peer ) {
			// Queue for specific peer.
			$key    = 'wp_mcp_ai_webchat_events_' . md5( $room_id . '_' . $target_peer );
			$events = get_transient( $key );
			if ( ! is_array( $events ) ) {
				$events = array();
			}
			$events[] = $event;
			set_transient( $key, $events, 60 );
		} else {
			// Queue for all peers except excluded.
			$peers = $this->get_active_peers( $room_id );
			foreach ( $peers as $peer ) {
				if ( $exclude_peer && $peer['peer_id'] === $exclude_peer ) {
					continue;
				}

				$key    = 'wp_mcp_ai_webchat_events_' . md5( $room_id . '_' . $peer['peer_id'] );
				$events = get_transient( $key );
				if ( ! is_array( $events ) ) {
					$events = array();
				}
				$events[] = $event;
				set_transient( $key, $events, 60 );
			}
		}
	}

	/**
	 * Get pending events for a peer.
	 *
	 * @param string $room_id Room ID.
	 * @param string $peer_id Peer ID.
	 * @return array
	 */
	protected function get_pending_events( $room_id, $peer_id ) {
		$key    = 'wp_mcp_ai_webchat_events_' . md5( $room_id . '_' . $peer_id );
		$events = get_transient( $key );

		if ( ! is_array( $events ) ) {
			return array();
		}

		// Clear events after retrieval.
		delete_transient( $key );

		return $events;
	}
}
