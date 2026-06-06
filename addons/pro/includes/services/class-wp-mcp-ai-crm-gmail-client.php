<?php
/**
 * CRM Gmail Client — external Gmail inbox search via OAuth 2.0.
 *
 * Searches Gmail accounts for lead emails via the Gmail API.
 * Uses Remote Sites connections for credentials and OAuth token management.
 *
 * Decoupled from individual CRM tools — any tool that needs to search
 * external Gmail inboxes can instantiate this client.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Services
 * @since 2.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail API client for CRM lead discovery across external inboxes.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_CRM_Gmail_Client {

	/**
	 * Gmail API base URL.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	const API_BASE = 'https://gmail.googleapis.com/gmail/v1';

	/**
	 * OAuth 2.0 token endpoint.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @since 2.4.0
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @since 2.4.0
	 *
	 * @param int $timeout HTTP request timeout in seconds (default 30).
	 */
	public function __construct( $timeout = 30 ) {
		$this->timeout = max( 5, absint( $timeout ) );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Search Gmail accounts for lead emails.
	 *
	 * Queries one or more Gmail connections for messages matching
	 * lead-filter criteria, normalises results to CRM lead format.
	 *
	 * @since 2.4.0
	 *
	 * @param array $arguments {
	 *     Search arguments.
	 *
	 *     @type string[] $connection_ids Optional explicit Gmail connection IDs.
	 *                                    When omitted, all configured Gmail connections
	 *                                    are searched when include_external is true.
	 *     @type string   $email_domain   Filter by sender domain.
	 *     @type string   $date_from      Messages after this date.
	 *     @type string   $date_to        Messages before this date.
	 *     @type string   $inquiry_type   Lead inquiry type for keyword mapping.
	 *     @type int      $per_page       Max messages per connection (1-50, default 20).
	 * }
	 * @return array{
	 *     leads: array<int,array>,
	 *     external: array<string,array{status:string,label?:string,messages_found?:int,query?:string,error?:string,reason?:string}>
	 * }
	 */
	public function search_leads( array $arguments = array() ) {
		$connection_ids = self::resolve_connection_ids( $arguments );

		if ( empty( $connection_ids ) ) {
			return array( 'leads' => array(), 'external' => array() );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array( 'leads' => array(), 'external' => array() );
		}

		$all_leads     = array();
		$external_meta = array();

		foreach ( $connection_ids as $connection_id ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( empty( $connection ) || empty( $connection['connection_type'] ) || 'gmail' !== $connection['connection_type'] ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'skipped',
					'reason' => 'not_gmail_connection',
				);
				continue;
			}

			$client_id     = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';
			$user_email    = isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '';

			// Decrypt encrypted fields.
			if ( '' !== $client_secret ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}
			if ( '' !== $refresh_token ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}

			if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'skipped',
					'reason' => 'missing_credentials',
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$gmail_query  = self::build_search_query( $arguments );
			$access_token = self::request_access_token( $client_id, $client_secret, $refresh_token );

			if ( is_wp_error( $access_token ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => $access_token->get_error_message(),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$gmail_user  = '' !== $user_email ? $user_email : 'me';
			$max_results = isset( $arguments['per_page'] ) ? min( 50, absint( $arguments['per_page'] ) ) : 20;

			$list_url = self::API_BASE . '/users/' . rawurlencode( $gmail_user ) . '/messages'
				. '?q=' . rawurlencode( $gmail_query )
				. '&maxResults=' . $max_results;

			$response = wp_remote_get(
				$list_url,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $access_token,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => $response->get_error_message(),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => sprintf(
						/* translators: %d: HTTP status code */
						__( 'HTTP %d from Gmail API.', 'mcp-ai-wpoos-pro' ),
						$status_code
					),
					'label' => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$body         = wp_remote_retrieve_body( $response );
			$list_payload = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list_payload ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => __( 'Invalid JSON from Gmail API.', 'mcp-ai-wpoos-pro' ),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$messages_found = 0;
			if ( ! empty( $list_payload['messages'] ) && is_array( $list_payload['messages'] ) ) {
				foreach ( $list_payload['messages'] as $message_ref ) {
					if ( empty( $message_ref['id'] ) ) {
						continue;
					}

					$msg_data = $this->fetch_message_headers( $gmail_user, $message_ref['id'], $access_token );
					if ( null === $msg_data ) {
						continue;
					}

					$from    = '';
					$subject = '';
					$date    = '';
					$snippet = isset( $msg_data['snippet'] ) ? sanitize_text_field( $msg_data['snippet'] ) : '';

					if ( ! empty( $msg_data['payload']['headers'] ) && is_array( $msg_data['payload']['headers'] ) ) {
						foreach ( $msg_data['payload']['headers'] as $header ) {
							$name  = isset( $header['name'] ) ? strtolower( $header['name'] ) : '';
							$value = isset( $header['value'] ) ? $header['value'] : '';
							if ( 'from' === $name ) {
								$from = $value;
							} elseif ( 'subject' === $name ) {
								$subject = $value;
							} elseif ( 'date' === $name ) {
								$date = $value;
							}
						}
					}

					$from_name  = '';
					$from_email = '';
					if ( preg_match( '/^([^<]+)<([^>]+)>/', $from, $matches ) ) {
						$from_name  = trim( $matches[1] );
						$from_email = trim( $matches[2] );
					} elseif ( '' !== $from ) {
						$from_email = trim( $from );
					}

					$all_leads[] = array(
						'id'             => 'gmail:' . $message_ref['id'],
						'name'           => sanitize_text_field( $from_name ),
						'email'          => sanitize_email( $from_email ),
						'first_name'     => '',
						'last_name'      => '',
						'company'        => '',
						'lead_status'    => 'new',
						'inquiry_type'   => 'new_inquiry',
						'mql_stage'      => '',
						'priority'       => '',
						'contact_owner'  => '',
						'source'         => 'email_inbound',
						'lead_score'     => null,
						'score_label'    => __( 'unscored', 'mcp-ai-wpoos-pro' ),
						'added_date'     => sanitize_text_field( $date ),
						'edit_url'       => '',
						'origin'         => 'external',
						'origin_label'   => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
						'gmail_subject'  => sanitize_text_field( $subject ),
						'gmail_snippet'  => $snippet,
					);
					$messages_found++;
				}
			}

			$external_meta[ $connection_id ] = array(
				'status'         => 'success',
				'label'          => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				'messages_found' => $messages_found,
				'query'          => $gmail_query,
			);
		}

		return array(
			'leads'    => $all_leads,
			'external' => $external_meta,
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch message metadata (headers only) for a single Gmail message.
	 *
	 * Uses format=metadata for performance — only retrieves headers, not body.
	 *
	 * @since 2.4.0
	 *
	 * @param string $gmail_user   Gmail user identifier ('me' or email).
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth 2.0 access token.
	 * @return array|null Decoded message payload, or null on failure.
	 */
	private function fetch_message_headers( $gmail_user, $message_id, $access_token ) {
		$detail_url = self::API_BASE . '/users/' . rawurlencode( $gmail_user )
			. '/messages/' . rawurlencode( $message_id )
			. '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date';

		$response = wp_remote_get(
			$detail_url,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Static utility methods
	// -------------------------------------------------------------------------

	/**
	 * Resolve which Gmail connection IDs to query.
	 *
	 * If explicit connection_ids are provided, use those (validated).
	 * Otherwise returns all configured Gmail connections.
	 *
	 * @since 2.4.0
	 *
	 * @param array $arguments Tool arguments with optional connection_ids.
	 * @return string[] Array of connection IDs.
	 */
	public static function resolve_connection_ids( array $arguments ) {
		if ( ! empty( $arguments['connection_ids'] ) && is_array( $arguments['connection_ids'] ) ) {
			return array_map( 'sanitize_key', $arguments['connection_ids'] );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$gmail_ids       = array();

		foreach ( $all_connections as $cid => $conn ) {
			if ( ! empty( $conn['connection_type'] ) && 'gmail' === $conn['connection_type'] ) {
				$gmail_ids[] = $cid;
			}
		}

		return $gmail_ids;
	}

	/**
	 * Build a Gmail search query string from lead filter arguments.
	 *
	 * Translates CRM lead filter parameters into Gmail search operators.
	 *
	 * @since 2.4.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Gmail-compatible search query.
	 */
	public static function build_search_query( array $arguments ) {
		$parts = array( 'is:unread' );

		if ( ! empty( $arguments['email_domain'] ) ) {
			$parts[] = 'from:' . sanitize_text_field( $arguments['email_domain'] );
		}

		if ( ! empty( $arguments['date_from'] ) ) {
			$parts[] = 'after:' . sanitize_text_field( $arguments['date_from'] );
		}
		if ( ! empty( $arguments['date_to'] ) ) {
			$parts[] = 'before:' . sanitize_text_field( $arguments['date_to'] );
		}

		$inquiry_type = isset( $arguments['inquiry_type'] ) ? sanitize_key( $arguments['inquiry_type'] ) : 'all';
		if ( 'all' !== $inquiry_type ) {
			$keyword_map = array(
				'demo_request'          => 'demo OR demonstration OR "book a demo"',
				'pricing_inquiry'       => 'pricing OR price OR quote OR cost OR estimate',
				'trial_request'         => 'trial OR "free trial" OR "try it"',
				'support_request'       => 'support OR help OR issue OR problem OR "not working"',
				'partnership'           => 'partnership OR partner OR collaboration OR affiliate',
				'referral'              => 'referral OR referred OR "recommended by"',
				'consultation_request'  => 'consultation OR consulting OR "book a call" OR assessment',
				'event_registration'    => 'webinar OR event OR register OR registration OR rsvp',
				'content_download'      => 'download OR whitepaper OR ebook OR guide OR pdf',
				'newsletter_signup'     => 'newsletter OR subscribe OR subscription',
				'account_management'    => 'account OR upgrade OR renew OR billing OR invoice',
			);

			if ( isset( $keyword_map[ $inquiry_type ] ) ) {
				$parts[] = '{' . $keyword_map[ $inquiry_type ] . '}';
			}
		}

		// Exclude common non-lead emails.
		$parts[] = '-{spam OR "out of office" OR "delivery failure" OR noreply OR no-reply OR "mailer daemon"}';

		return implode( ' ', $parts );
	}

	/**
	 * Request an OAuth 2.0 access token for a Gmail connection.
	 *
	 * Uses the refresh_token grant type to obtain a short-lived access token.
	 *
	 * @since 2.4.0
	 *
	 * @param string $client_id     Gmail API client ID.
	 * @param string $client_secret Gmail API client secret.
	 * @param string $refresh_token Gmail API refresh token.
	 * @return string|WP_Error Access token or error.
	 */
	public static function request_access_token( $client_id, $client_secret, $refresh_token ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( 200 !== $status || JSON_ERROR_NONE !== json_last_error() || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_crm_gmail_token_failed',
				__( 'Failed to obtain Gmail access token.', 'mcp-ai-wpoos-pro' )
			);
		}

		return (string) $data['access_token'];
	}
}
