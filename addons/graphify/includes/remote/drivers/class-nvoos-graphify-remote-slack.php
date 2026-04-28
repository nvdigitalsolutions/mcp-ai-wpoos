<?php
/**
 * NV oOS Graphify — Slack Remote Driver (Pro)
 *
 * Pulls Slack channel and user metadata as graph nodes. Channel-membership
 * relationships are emitted as edges:
 *
 *   user      MEMBER_OF   channel
 *
 * Authentication: Slack bot/user OAuth2 token (`xoxb-…` / `xoxp-…`). Real-time
 * sync is enabled via the shared webhook endpoint introduced in Phase 1
 * (`/wp-json/nvoos-graphify/v1/webhooks/{slug}`) — Slack Events API posts get
 * verified by HMAC against the per-source secret.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slack remote-source driver.
 *
 * @since 0.7.1
 */
class NV_oOS_Graphify_Remote_Slack extends NV_oOS_Graphify_Remote_Source_Base {

	const API_BASE = 'https://slack.com/api';

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'slack';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Slack', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges', 'webhooks' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => true,
			'supports_oauth'         => true,
			'supports_pagination'    => true,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'access_token'    => array(
				'type'        => 'password',
				'label'       => __( 'Slack Bot Token', 'nvoos-graphify' ),
				'description' => __( 'Slack bot OAuth token (xoxb-…) or user token (xoxp-…).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'webhook_secret'  => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Signing Secret', 'nvoos-graphify' ),
				'description' => __( 'Slack signing secret used to verify Events API webhook payloads.', 'nvoos-graphify' ),
			),
			'channel_types'   => array(
				'type'        => 'text',
				'label'       => __( 'Channel Types', 'nvoos-graphify' ),
				'description' => __( 'Comma-separated channel types (public_channel, private_channel).', 'nvoos-graphify' ),
				'default'     => 'public_channel',
			),
			'include_members' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Include Channel Members', 'nvoos-graphify' ),
				'default' => true,
			),
			'max_items'       => array(
				'type'    => 'number',
				'label'   => __( 'Max Channels', 'nvoos-graphify' ),
				'default' => 100,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$token = $this->resolve_token();
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'No Slack access_token configured.', 'nvoos-graphify' ),
			);
		}
		$result = $this->get_http()->get(
			self::API_BASE . '/auth.test',
			array( 'headers' => $this->auth_headers( $token ) )
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			return array(
				'success' => false,
				'message' => isset( $body['error'] ) ? sanitize_text_field( (string) $body['error'] ) : __( 'Slack auth.test failed.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to Slack.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$token = $this->resolve_token();
		if ( '' === $token ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$nodes     = array();

		// Channels.
		$channels = $this->fetch_channels( $token, $max_items );
		foreach ( $channels as $channel ) {
			$nodes[] = $this->channel_to_node( $channel, $slug );
		}

		// Workspace users.
		$users = $this->fetch_users( $token, $max_items );
		foreach ( $users as $user ) {
			$nodes[] = $this->user_to_node( $user, $slug );
		}

		NV_oOS_Graphify_Remote_State_Store::mark_synced( $slug );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$token = $this->resolve_token();
		if ( '' === $token || ! $this->include_members() ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$edges     = array();

		$channels = $this->fetch_channels( $token, $max_items );
		foreach ( $channels as $channel ) {
			$channel_id = isset( $channel['id'] ) ? (string) $channel['id'] : '';
			if ( '' === $channel_id ) {
				continue;
			}
			$members = $this->fetch_members( $channel_id, $token );
			foreach ( $members as $user_id ) {
				$edges[] = array(
					'source_node_id' => $this->user_node_id( $user_id, $slug ),
					'target_node_id' => $this->channel_node_id( $channel_id, $slug ),
					'relation'       => 'MEMBER_OF',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}
		}

		return $edges;
	}

	/**
	 * Convert a Slack channel object to a graph node.
	 *
	 * @param array  $channel Channel payload.
	 * @param string $slug    Source slug.
	 * @return array
	 */
	public function channel_to_node( array $channel, $slug ) {
		$id   = isset( $channel['id'] ) ? (string) $channel['id'] : '';
		$name = isset( $channel['name'] ) ? '#' . (string) $channel['name'] : ( '' !== $id ? 'channel:' . $id : 'channel' );
		return array(
			'node_id'     => $this->channel_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'channel',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'slack_id'    => sanitize_text_field( $id ),
				'is_private'  => ! empty( $channel['is_private'] ),
				'is_archived' => ! empty( $channel['is_archived'] ),
				'topic'       => isset( $channel['topic']['value'] ) ? sanitize_text_field( (string) $channel['topic']['value'] ) : '',
			),
			'external_id' => 'slack:channel:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Slack user object to a graph node.
	 *
	 * @param array  $user User payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function user_to_node( array $user, $slug ) {
		$id    = isset( $user['id'] ) ? (string) $user['id'] : '';
		$name  = isset( $user['real_name'] ) ? (string) $user['real_name'] : ( isset( $user['name'] ) ? (string) $user['name'] : ( '' !== $id ? $id : 'user' ) );
		$email = isset( $user['profile']['email'] ) ? sanitize_email( (string) $user['profile']['email'] ) : '';

		$out = array(
			'node_id'     => $this->user_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'person',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'slack_id'   => sanitize_text_field( $id ),
				'slack_name' => isset( $user['name'] ) ? sanitize_text_field( (string) $user['name'] ) : '',
				'is_bot'     => ! empty( $user['is_bot'] ),
			),
			'external_id' => 'slack:user:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
		if ( '' !== $email ) {
			$out['email']               = $email;
			$out['properties']['email'] = $email;
		}
		return $out;
	}

	/**
	 * Channel node_id helper.
	 *
	 * @param string $id   Channel ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function channel_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_channel_' . sanitize_key( $id );
	}

	/**
	 * User node_id helper.
	 *
	 * @param string $id   User ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function user_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_user_' . sanitize_key( $id );
	}

	/**
	 * Verify a Slack Events API webhook signature against the configured
	 * signing secret. Returns true on match. Used by the webhook driver path
	 * but exposed publicly so tests / admin can invoke it.
	 *
	 * @param string $body      Raw request body.
	 * @param string $timestamp X-Slack-Request-Timestamp header value.
	 * @param string $signature X-Slack-Signature header value (e.g. v0=abcdef…).
	 * @return bool
	 */
	public function verify_slack_signature( $body, $timestamp, $signature ) {
		$config = $this->get_config();
		$secret = isset( $config['webhook_secret'] ) ? (string) $config['webhook_secret'] : '';
		if ( '' === $secret || '' === (string) $signature || '' === (string) $timestamp ) {
			return false;
		}
		// Reject anything more than 5 minutes old.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}
		$base     = 'v0:' . $timestamp . ':' . (string) $body;
		$expected = 'v0=' . hash_hmac( 'sha256', $base, $secret );
		return hash_equals( $expected, (string) $signature );
	}

	/**
	 * Fetch channels via conversations.list.
	 *
	 * @param string $token Token.
	 * @param int    $limit Limit.
	 * @return array<array>
	 */
	private function fetch_channels( $token, $limit ) {
		$endpoint = self::API_BASE . '/conversations.list?limit=' . max( 1, min( 1000, (int) $limit ) ) . '&types=' . rawurlencode( $this->resolve_channel_types() );
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['channels'] ) ) {
			return array();
		}
		return is_array( $body['channels'] ) ? $body['channels'] : array();
	}

	/**
	 * Fetch users via users.list.
	 *
	 * @param string $token Token.
	 * @param int    $limit Limit.
	 * @return array<array>
	 */
	private function fetch_users( $token, $limit ) {
		$endpoint = self::API_BASE . '/users.list?limit=' . max( 1, min( 1000, (int) $limit ) );
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['members'] ) ) {
			return array();
		}
		return is_array( $body['members'] ) ? $body['members'] : array();
	}

	/**
	 * Fetch member IDs of a channel.
	 *
	 * @param string $channel_id Channel ID.
	 * @param string $token      Token.
	 * @return array<string>
	 */
	private function fetch_members( $channel_id, $token ) {
		$endpoint = self::API_BASE . '/conversations.members?channel=' . rawurlencode( $channel_id ) . '&limit=200';
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['members'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $body['members'] as $id ) {
			$out[] = (string) $id;
		}
		return $out;
	}

	/**
	 * Resolve the access token from config.
	 *
	 * @return string
	 */
	private function resolve_token() {
		$config = $this->get_config();
		return isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
	}

	/**
	 * Resolve configured channel types.
	 *
	 * @return string
	 */
	private function resolve_channel_types() {
		$config  = $this->get_config();
		$raw     = isset( $config['channel_types'] ) ? (string) $config['channel_types'] : 'public_channel';
		$allowed = array( 'public_channel', 'private_channel', 'mpim', 'im' );
		$out     = array();
		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = trim( $piece );
			if ( in_array( $piece, $allowed, true ) ) {
				$out[] = $piece;
			}
		}
		return ! empty( $out ) ? implode( ',', $out ) : 'public_channel';
	}

	/**
	 * Whether to expand channel members into edges.
	 *
	 * @return bool
	 */
	private function include_members() {
		$config = $this->get_config();
		return ! isset( $config['include_members'] ) || ! empty( $config['include_members'] );
	}

	/**
	 * Resolve channel limit.
	 *
	 * @param array $args Caller args.
	 * @return int
	 */
	private function resolve_max_items( array $args ) {
		if ( isset( $args['max_items'] ) ) {
			return max( 1, (int) $args['max_items'] );
		}
		$config = $this->get_config();
		return isset( $config['max_items'] ) ? max( 1, (int) $config['max_items'] ) : 100;
	}

	/**
	 * Build standard auth headers.
	 *
	 * @param string $token Token.
	 * @return array
	 */
	private function auth_headers( $token ) {
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Lazy HTTP client.
	 *
	 * @return NV_oOS_Graphify_HTTP_Client
	 */
	private function get_http() {
		if ( null === $this->http ) {
			$this->http = new NV_oOS_Graphify_HTTP_Client( $this->get_slug() );
		}
		return $this->http;
	}
}
