<?php
/**
 * Tool that searches Gmail messages using stored OAuth credentials.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Provides an assistant tool for searching Gmail messages via the Gmail REST API.
 */
class WP_MCP_AI_Tool_Search_Gmail implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const GMAIL_API_BASE = 'https://gmail.googleapis.com/gmail/v1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_gmail';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Gmail Messages', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches the configured Gmail inbox and returns recent matches, including sender, subject, and snippets.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'Gmail search query string. Supports the same syntax as the Gmail web interface.', 'wp-mcp-ai' ),
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of messages to return (1-50).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 5,
				),
				'label_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Optional Gmail label IDs to filter the results (for example INBOX or CATEGORY_PROMOTIONS).', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'page_token'  => array(
					'type'        => 'string',
					'description' => __( 'Page token returned by a previous Gmail search response to fetch the next page of results.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_search_gmail_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_forbidden', __( 'You do not have permission to search Gmail.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$client_id       = isset( $settings['gmail_client_id'] ) ? trim( (string) $settings['gmail_client_id'] ) : '';
		$client_secret   = isset( $settings['gmail_client_secret'] ) ? trim( (string) $settings['gmail_client_secret'] ) : '';
		$refresh_token   = isset( $settings['gmail_refresh_token'] ) ? trim( (string) $settings['gmail_refresh_token'] ) : '';
		$configured_user = isset( $settings['gmail_user_email'] ) ? trim( (string) $settings['gmail_user_email'] ) : '';

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error(
				'wp_mcp_ai_gmail_missing_credentials',
				__( 'Gmail API credentials are not configured. Add the client ID, client secret, and refresh token in the WP oOS settings.', 'wp-mcp-ai' )
			);
		}

		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';

		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_gmail_missing_query', __( 'A Gmail search query is required.', 'wp-mcp-ai' ) );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 5;
		if ( $max_results < 1 ) {
			$max_results = 1;
		}
		if ( $max_results > 50 ) {
			$max_results = 50;
		}

		$label_ids = array();
		if ( ! empty( $arguments['label_ids'] ) ) {
			if ( is_array( $arguments['label_ids'] ) ) {
				foreach ( $arguments['label_ids'] as $label ) {
					$label = trim( (string) $label );
					if ( '' !== $label ) {
						$label_ids[] = $label;
					}
				}
			} else {
				$label = trim( (string) $arguments['label_ids'] );
				if ( '' !== $label ) {
					$label_ids[] = $label;
				}
			}
		}

		$page_token = isset( $arguments['page_token'] ) ? trim( (string) $arguments['page_token'] ) : '';

		$timeout = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;

		$access_token = $this->request_access_token( $client_id, $client_secret, $refresh_token, $timeout );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$gmail_user = '' !== $configured_user ? $configured_user : 'me';

		$list_url = $this->build_messages_list_url( $gmail_user, $query, $max_results, $label_ids, $page_token );

		$response = wp_remote_get(
			$list_url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail search request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error( 'wp_mcp_ai_gmail_http_error', __( 'The Gmail search request failed.', 'wp-mcp-ai' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail search returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_gmail_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Gmail returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$list_payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list_payload ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail search returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_gmail_invalid_json', __( 'Gmail returned an invalid response.', 'wp-mcp-ai' ) );
		}

		$messages = array();
		if ( ! empty( $list_payload['messages'] ) && is_array( $list_payload['messages'] ) ) {
			foreach ( $list_payload['messages'] as $message_ref ) {
				if ( empty( $message_ref['id'] ) ) {
					continue;
				}

				$message_details = $this->fetch_message_details( $gmail_user, $message_ref['id'], $access_token, $timeout );
				if ( is_wp_error( $message_details ) ) {
					return $message_details;
				}

				if ( ! empty( $message_details ) ) {
					$messages[] = $message_details;
				}
			}
		}

		return array(
			'messages'             => $messages,
			'result_size_estimate' => isset( $list_payload['resultSizeEstimate'] ) ? absint( $list_payload['resultSizeEstimate'] ) : count( $messages ),
			'next_page_token'      => isset( $list_payload['nextPageToken'] ) ? (string) $list_payload['nextPageToken'] : '',
		);
	}

	/**
	 * Request an access token from Google's OAuth endpoint using a stored refresh token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $refresh_token Refresh token for the Gmail API.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return string|WP_Error
	 */
	protected function request_access_token( $client_id, $client_secret, $refresh_token, $timeout ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => $timeout,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail token request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error( 'wp_mcp_ai_gmail_token_error', __( 'Failed to refresh the Gmail access token.', 'wp-mcp-ai' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail token request returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_gmail_token_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Gmail token endpoint returned an unexpected status: %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || empty( $payload['access_token'] ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail token response returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_gmail_token_invalid', __( 'Gmail returned an invalid token response.', 'wp-mcp-ai' ) );
		}

		return (string) $payload['access_token'];
	}

	/**
	 * Fetch message metadata and snippets for a given Gmail message ID.
	 *
	 * @param string $user_id      Gmail user identifier (email address or "me").
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout.
	 * @return array|WP_Error
	 */
	protected function fetch_message_details( $user_id, $message_id, $access_token, $timeout ) {
		$detail_url = sprintf( '%s/users/%s/messages/%s', self::GMAIL_API_BASE, rawurlencode( $user_id ), rawurlencode( $message_id ) );
		$detail_url = $this->append_query_string(
			$detail_url,
			array(
				'format'          => 'metadata',
				'metadataHeaders' => array( 'Subject', 'From', 'To', 'Date' ),
				'fields'          => 'id,threadId,labelIds,snippet,internalDate,payload/headers',
			)
		);

		$response = wp_remote_get(
			$detail_url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail message detail request failed.',
				array(
					'id'    => $message_id,
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error( 'wp_mcp_ai_gmail_message_error', __( 'Failed to load Gmail message details.', 'wp-mcp-ai' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail message detail returned unexpected status.',
				array(
					'id'     => $message_id,
					'status' => $status_code,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_gmail_message_status',
				sprintf(
					/* translators: %1$s: Gmail message ID, %2$d: HTTP status code. */
					__( 'Gmail returned an unexpected status (%2$d) while loading message %1$s.', 'wp-mcp-ai' ),
					$message_id,
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail message detail returned invalid JSON.',
				array(
					'id'   => $message_id,
					'body' => $body,
				)
			);

			return new WP_Error( 'wp_mcp_ai_gmail_message_invalid', __( 'Gmail returned an invalid message response.', 'wp-mcp-ai' ) );
		}

		$headers = isset( $payload['payload']['headers'] ) && is_array( $payload['payload']['headers'] ) ? $payload['payload']['headers'] : array();

		$subject = $this->find_header_value( $headers, 'Subject' );
		$from    = $this->find_header_value( $headers, 'From' );
		$to      = $this->find_header_value( $headers, 'To' );
		$date    = $this->find_header_value( $headers, 'Date' );

		$timestamp = null;
		if ( isset( $payload['internalDate'] ) ) {
			$timestamp = (int) floor( absint( $payload['internalDate'] ) / 1000 );
		}

		return array(
			'id'        => isset( $payload['id'] ) ? (string) $payload['id'] : $message_id,
			'thread_id' => isset( $payload['threadId'] ) ? (string) $payload['threadId'] : '',
			'labels'    => isset( $payload['labelIds'] ) && is_array( $payload['labelIds'] ) ? array_values( array_map( 'strval', $payload['labelIds'] ) ) : array(),
			'subject'   => (string) $subject,
			'from'      => (string) $from,
			'to'        => (string) $to,
			'date'      => (string) $date,
			'timestamp' => $timestamp,
			'snippet'   => isset( $payload['snippet'] ) ? (string) $payload['snippet'] : '',
			'permalink' => sprintf( 'https://mail.google.com/mail/u/0/#all/%s', rawurlencode( isset( $payload['id'] ) ? $payload['id'] : $message_id ) ),
		);
	}

	/**
	 * Build the Gmail messages list endpoint URL with query parameters.
	 *
	 * @param string $user_id     Gmail user identifier.
	 * @param string $query       Search query.
	 * @param int    $max_results Maximum number of results to return.
	 * @param array  $label_ids   Optional label filters.
	 * @param string $page_token  Optional page token.
	 * @return string
	 */
	protected function build_messages_list_url( $user_id, $query, $max_results, $label_ids, $page_token ) {
		$base = sprintf( '%s/users/%s/messages', self::GMAIL_API_BASE, rawurlencode( $user_id ) );

		$params = array(
			'q'          => $query,
			'maxResults' => $max_results,
		);

		if ( '' !== $page_token ) {
			$params['pageToken'] = $page_token;
		}

		return $this->append_query_string( $base, array_merge( $params, array( 'labelIds' => (array) $label_ids ) ) );
	}

	/**
	 * Append query parameters to a URL, expanding list values into repeated parameters.
	 *
	 * @param string $url         Base URL.
	 * @param array  $parameters  Query parameters. Array values will be expanded into repeated keys.
	 * @return string
	 */
	protected function append_query_string( $url, $parameters ) {
		$parts = array();

		foreach ( $parameters as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( null === $item || '' === $item ) {
						continue;
					}

					$parts[] = rawurlencode( $key ) . '=' . rawurlencode( $item );
				}
			} elseif ( null !== $value && '' !== $value ) {
				$parts[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
			}
		}

		if ( empty( $parts ) ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $parts );
	}

	/**
	 * Locate a header value within the Gmail message headers array.
	 *
	 * @param array  $headers List of header objects from the Gmail API response.
	 * @param string $name    Header name to find.
	 * @return string
	 */
	protected function find_header_value( $headers, $name ) {
		if ( empty( $headers ) || '' === $name ) {
			return '';
		}

		foreach ( $headers as $header ) {
			if ( ! is_array( $header ) ) {
				continue;
			}

			$header_name = isset( $header['name'] ) ? (string) $header['name'] : '';
			if ( 0 === strcasecmp( $header_name, $name ) ) {
				return isset( $header['value'] ) ? (string) $header['value'] : '';
			}
		}

		return '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
