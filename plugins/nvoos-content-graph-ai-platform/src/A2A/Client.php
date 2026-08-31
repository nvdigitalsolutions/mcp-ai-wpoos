<?php
/**
 * A2A Client — outbound agent communication.
 *
 * Enables assistants to discover, authenticate with,
 * and delegate tasks to external A2A-compliant agents.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-client.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for communicating with remote A2A agents.
 */
class Client {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Agent Card cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CARD_CACHE_TTL = 3600;

	/**
	 * Transient prefix for caching agent cards.
	 *
	 * @var string
	 */
	const CARD_CACHE_PREFIX = 'wp_mcp_ai_a2a_card_';

	/**
	 * Discover a remote agent by fetching its Agent Card.
	 *
	 * @param string $agent_url The base URL of the remote agent.
	 * @return array|\WP_Error The Agent Card or error.
	 */
	public static function discover_agent( $agent_url ) {
		$agent_url = trailingslashit( esc_url_raw( $agent_url ) );

		// Validate the host before making an outbound request.
		// Block cloud metadata endpoints and private IPs unless the
		// host is an approved federation peer.
		$host = wp_parse_url( $agent_url, PHP_URL_HOST );
		if ( ! $host ) {
			return new \WP_Error(
				'a2a_invalid_url',
				__( 'Invalid agent URL provided.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$blocked = array( '169.254.169.254', 'metadata.google.internal', '100.100.100.200' );
		foreach ( $blocked as $b ) {
			if ( false !== strpos( $host, $b ) ) {
				return new \WP_Error(
					'a2a_blocked_host',
					__( 'Connections to cloud metadata services are not allowed.', 'nvoos-content-graph-ai-platform' )
				);
			}
		}

		$ip = gethostbyname( $host );
		if (
			false === filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			)
		) {
			// Private IP detected. Only allow if host is an approved federation peer.
			$approved = self::get_approved_peer_hosts();
			if ( ! in_array( $host, $approved, true ) ) {
				return new \WP_Error(
					'a2a_blocked_host',
					sprintf(
						/* translators: %s: hostname */
						__( 'A2A agent discovery to %s is not allowed. Add the host to your federation peer list.', 'nvoos-content-graph-ai-platform' ),
						esc_html( $host )
					)
				);
			}
		}

		// Check cache first.
		$cache_key = self::CARD_CACHE_PREFIX . md5( $agent_url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Fetch from /.well-known/agent.json.
		$card_url = $agent_url . '.well-known/agent.json';
		$response = wp_remote_get(
			$card_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'a2a_discovery_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to discover A2A agent: %s', 'nvoos-content-graph-ai-platform' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'a2a_discovery_failed',
				sprintf(
					/* translators: 1: status code 2: URL */
					__( 'A2A agent discovery returned status %1$d from %2$s', 'nvoos-content-graph-ai-platform' ),
					$status_code,
					$card_url
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$card = json_decode( $body, true );

		if ( ! is_array( $card ) || empty( $card['name'] ) ) {
			return new \WP_Error(
				'a2a_invalid_card',
				__( 'Invalid Agent Card format received.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Cache the agent card.
		set_transient( $cache_key, $card, self::CARD_CACHE_TTL );

		return $card;
	}

	/**
	 * Send a message to a remote A2A agent.
	 *
	 * @param string $agent_url The A2A endpoint URL (from Agent Card).
	 * @param string $text      The message text.
	 * @param array  $options   {
	 *     Optional. Request options.
	 *
	 *     @type string $context_id Context ID for multi-turn.
	 *     @type string $task_id    Task ID if continuing.
	 *     @type array  $auth       Authentication config.
	 *     @type int    $timeout    Request timeout.
	 *     @type array  $metadata   Additional metadata.
	 * }
	 * @return array|\WP_Error The response (Task or Message) or error.
	 */
	public static function send_message( $agent_url, $text, $options = array() ) {
		$defaults = array(
			'context_id' => '',
			'task_id'    => '',
			'auth'       => array(),
			'timeout'    => self::DEFAULT_TIMEOUT,
			'metadata'   => array(),
		);

		$options = wp_parse_args( $options, $defaults );

		// Build A2A message.
		$message = array(
			'kind'      => 'message',
			'messageId' => wp_generate_uuid4(),
			'role'      => 'user',
			'parts'     => array(
				array(
					'kind' => 'text',
					'text' => $text,
				),
			),
		);

		if ( ! empty( $options['context_id'] ) ) {
			$message['contextId'] = $options['context_id'];
		}

		if ( ! empty( $options['task_id'] ) ) {
			$message['taskId'] = $options['task_id'];
		}

		// Build JSON-RPC request.
		$rpc_request = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => 'message/send',
			'params'  => array(
				'message' => $message,
			),
		);

		if ( ! empty( $options['metadata'] ) ) {
			$rpc_request['params']['metadata'] = $options['metadata'];
		}

		return self::send_jsonrpc_request( $agent_url, $rpc_request, $options );
	}

	/**
	 * Get the status of a remote task.
	 *
	 * @param string $agent_url The A2A endpoint URL.
	 * @param string $task_id   The task ID to check.
	 * @param array  $options   Optional request options.
	 * @return array|\WP_Error The task data or error.
	 */
	public static function get_task( $agent_url, $task_id, $options = array() ) {
		$rpc_request = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => 'tasks/get',
			'params'  => array(
				'id' => $task_id,
			),
		);

		return self::send_jsonrpc_request( $agent_url, $rpc_request, $options );
	}

	/**
	 * Cancel a remote task.
	 *
	 * @param string $agent_url The A2A endpoint URL.
	 * @param string $task_id   The task ID to cancel.
	 * @param array  $options   Optional request options.
	 * @return array|\WP_Error The result or error.
	 */
	public static function cancel_task( $agent_url, $task_id, $options = array() ) {
		$rpc_request = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => 'tasks/cancel',
			'params'  => array(
				'id' => $task_id,
			),
		);

		return self::send_jsonrpc_request( $agent_url, $rpc_request, $options );
	}

	/**
	 * Send a JSON-RPC 2.0 request to a remote A2A agent.
	 *
	 * @param string $endpoint    The A2A endpoint URL.
	 * @param array  $rpc_request The JSON-RPC request body.
	 * @param array  $options     Request options including auth.
	 * @return array|\WP_Error The result or error.
	 */
	protected static function send_jsonrpc_request( $endpoint, $rpc_request, $options = array() ) {
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : self::DEFAULT_TIMEOUT;

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'A2A-Version'  => AgentCard::PROTOCOL_VERSION,
		);

		// Apply authentication headers.
		$auth = isset( $options['auth'] ) ? $options['auth'] : array();
		if ( ! empty( $auth['type'] ) ) {
			switch ( $auth['type'] ) {
				case 'bearer':
					if ( ! empty( $auth['token'] ) ) {
						$headers['Authorization'] = 'Bearer ' . $auth['token'];
					}
					break;

				case 'apiKey':
					$header_name = isset( $auth['header'] ) ? $auth['header'] : 'X-API-Key';
					if ( ! empty( $auth['key'] ) ) {
						$headers[ $header_name ] = $auth['key'];
					}
					break;
			}
		}

		$response = wp_remote_post(
			esc_url_raw( $endpoint ),
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
				'headers'   => $headers,
				'body'      => wp_json_encode( $rpc_request ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'a2a_invalid_response',
				__( 'Invalid JSON response from remote A2A agent.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Check for JSON-RPC error.
		if ( isset( $data['error'] ) ) {
			return new \WP_Error(
				'a2a_remote_error',
				isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown remote error.', 'nvoos-content-graph-ai-platform' ),
				array(
					'code'   => isset( $data['error']['code'] ) ? $data['error']['code'] : -32603,
					'status' => $status_code,
				)
			);
		}

		return isset( $data['result'] ) ? $data['result'] : $data;
	}

	/**
	 * Check if a remote agent supports a specific capability.
	 *
	 * @param array  $agent_card The Agent Card.
	 * @param string $capability The capability to check (streaming, pushNotifications).
	 * @return bool True if the capability is supported.
	 */
	public static function has_capability( $agent_card, $capability ) {
		return isset( $agent_card['capabilities'][ $capability ] ) && $agent_card['capabilities'][ $capability ];
	}

	/**
	 * Find a skill in an agent card by keyword.
	 *
	 * @param array  $agent_card The Agent Card.
	 * @param string $keyword    The keyword to search for in skill names/descriptions/tags.
	 * @return array|null The matching skill or null.
	 */
	public static function find_skill( $agent_card, $keyword ) {
		$keyword = strtolower( $keyword );
		$skills  = isset( $agent_card['skills'] ) ? $agent_card['skills'] : array();

		foreach ( $skills as $skill ) {
			// Check name.
			if ( isset( $skill['name'] ) && false !== strpos( strtolower( $skill['name'] ), $keyword ) ) {
				return $skill;
			}

			// Check description.
			if ( isset( $skill['description'] ) && false !== strpos( strtolower( $skill['description'] ), $keyword ) ) {
				return $skill;
			}

			// Check tags.
			if ( isset( $skill['tags'] ) && is_array( $skill['tags'] ) ) {
				foreach ( $skill['tags'] as $tag ) {
					if ( false !== strpos( strtolower( $tag ), $keyword ) ) {
						return $skill;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get the list of approved A2A peer hostnames from the federation settings.
	 *
	 * Used by discover_agent() to allow private-network A2A peers that
	 * have been explicitly added to the federation peer list.
	 *
	 * @since 2.0.0 Ported from mcp-ai-wpoos (since 1.1.43).
	 *
	 * @return string[] Array of approved peer hostnames.
	 */
	private static function get_approved_peer_hosts() {
		$peers = get_option( 'wp_mcp_ai_federation_peers', array() );
		if ( ! is_array( $peers ) ) {
			return array();
		}
		$hosts = array();
		foreach ( $peers as $peer ) {
			if ( ! empty( $peer['url'] ) ) {
				$h = wp_parse_url( $peer['url'], PHP_URL_HOST );
				if ( $h ) {
					$hosts[] = $h;
				}
			}
		}
		return array_unique( $hosts );
	}
}
