<?php
/**
 * Shared Gmail API client for the Google Workspace tool family.
 *
 * Centralises OAuth credential resolution, token refresh, HTTP transport,
 * and message-payload decoding (base64url bodies, quoted-printable transfer
 * encoding, plain-text extraction, HTML sanitisation, attachment discovery)
 * so each Gmail tool stays a thin canonical-envelope wrapper.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail API client helpers shared by the Gmail tool family.
 */
class WP_MCP_AI_Pro_Gmail_Client {

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const GMAIL_API_BASE = 'https://gmail.googleapis.com/gmail/v1';

	/**
	 * Connection types that expose a Gmail-compatible OAuth credential set.
	 */
	const GMAIL_CONNECTION_TYPES = array( 'gmail', 'google_workspace', 'email_imap' );

	/**
	 * Resolve Gmail credentials from a Remote Sites connection or from base settings.
	 *
	 * @param string $connection_id Optional Remote Sites connection ID. Empty falls back to settings.
	 * @return array|WP_Error Credentials array with keys client_id, client_secret,
	 *         refresh_token, configured_user and source — or WP_Error.
	 */
	public static function resolve_credentials( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		$client_id       = '';
		$client_secret   = '';
		$refresh_token   = '';
		$configured_user = '';
		$source          = 'settings';

		if ( '' !== $connection_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( empty( $connection ) ) {
				return new WP_Error(
					'wp_mcp_ai_gmail_connection_not_found',
					sprintf(
						/* translators: %s: connection ID. */
						__( 'Gmail connection "%s" not found. Call list_gmail_connections to see available connection IDs.', 'mcp-ai-wpoos-pro' ),
						$connection_id
					)
				);
			}

			$connection_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( ! in_array( $connection_type, self::GMAIL_CONNECTION_TYPES, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_gmail_wrong_connection_type',
					sprintf(
						/* translators: %s: connection type. */
						__( 'Connection "%s" is not a Gmail connection. Please use a Gmail connection type.', 'mcp-ai-wpoos-pro' ),
						$connection_type
					)
				);
			}

			$client_id       = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret   = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token   = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';
			$configured_user = isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '';

			// Decrypt encrypted fields.
			if ( '' !== $client_secret ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}
			if ( '' !== $refresh_token ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}

			$source = 'connection';
		} else {
			$settings = self::get_settings();

			$client_id       = isset( $settings['gmail_client_id'] ) ? trim( (string) $settings['gmail_client_id'] ) : '';
			$client_secret   = isset( $settings['gmail_client_secret'] ) ? trim( (string) $settings['gmail_client_secret'] ) : '';
			$refresh_token   = isset( $settings['gmail_refresh_token'] ) ? trim( (string) $settings['gmail_refresh_token'] ) : '';
			$configured_user = isset( $settings['gmail_user_email'] ) ? trim( (string) $settings['gmail_user_email'] ) : '';
		}

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error(
				'wp_mcp_ai_gmail_missing_credentials',
				__( 'Gmail API credentials are not configured. Add the client ID, client secret, and refresh token either in a Gmail connection (Remote Sites) or in the NV oOS settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'client_id'       => $client_id,
			'client_secret'   => $client_secret,
			'refresh_token'   => $refresh_token,
			'configured_user' => $configured_user,
			'source'          => $source,
		);
	}

	/**
	 * Request an access token from Google's OAuth endpoint using a stored refresh token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $refresh_token Refresh token for the Gmail API.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return string|WP_Error Access token or WP_Error.
	 */
	public static function request_access_token( $client_id, $client_secret, $refresh_token, $timeout ) {
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

			return new WP_Error( 'wp_mcp_ai_gmail_token_error', __( 'Failed to refresh the Gmail access token.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Gmail token request returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_gmail_token_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Gmail token endpoint returned an unexpected status: %d.', 'mcp-ai-wpoos-pro' ),
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

			return new WP_Error( 'wp_mcp_ai_gmail_token_invalid', __( 'Gmail returned an invalid token response.', 'mcp-ai-wpoos-pro' ) );
		}

		return (string) $payload['access_token'];
	}

	/**
	 * Perform an authenticated Gmail API request and decode the JSON response.
	 *
	 * @param string     $method       HTTP method: GET or POST.
	 * @param string     $gmail_user   Gmail user identifier (email address or "me").
	 * @param string     $path         Path relative to /gmail/v1/users/{userId}/ (e.g. "messages/abc").
	 * @param string     $access_token OAuth access token.
	 * @param int        $timeout      Request timeout in seconds.
	 * @param array      $query        Optional query parameters.
	 * @param array|null $body         Optional JSON body for POST requests.
	 * @return array|WP_Error Decoded JSON payload or WP_Error.
	 */
	public static function request( $method, $gmail_user, $path, $access_token, $timeout, $query = array(), $body = null ) {
		$url = sprintf( '%s/users/%s/%s', self::GMAIL_API_BASE, rawurlencode( $gmail_user ), $path );
		if ( ! empty( $query ) ) {
			$url = self::append_query_string( $url, $query );
		}

		$args = array(
			'timeout' => $timeout,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
		);

		if ( 'POST' === strtoupper( (string) $method ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( null === $body ? array() : $body );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail API request failed.',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error( 'wp_mcp_ai_gmail_http_error', __( 'The Gmail API request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail API request returned unexpected status.',
				array(
					'path'   => $path,
					'status' => $status_code,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_gmail_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Gmail returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$payload  = json_decode( $body_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Gmail API request returned invalid JSON.',
				array(
					'path' => $path,
					'body' => $body_raw,
				)
			);

			return new WP_Error( 'wp_mcp_ai_gmail_invalid_json', __( 'Gmail returned an invalid response.', 'mcp-ai-wpoos-pro' ) );
		}

		return $payload;
	}

	/**
	 * Build a normalised message array from a raw Gmail message payload.
	 *
	 * @param array  $payload             Raw Gmail messages.get / threads.get payload.
	 * @param string $format              Body format: "plain" or "html".
	 * @param int    $max_chars           Maximum body characters to return.
	 * @param bool   $include_headers     Whether to include the full header list.
	 * @param bool   $include_attachments Whether to include attachment names.
	 * @param string $fallback_id         Fallback message ID when the payload omits it.
	 * @return array Normalised message data.
	 */
	public static function normalize_message( $payload, $format, $max_chars, $include_headers = false, $include_attachments = true, $fallback_id = '' ) {
		$format = ( 'html' === $format ) ? 'html' : 'plain';

		$headers = isset( $payload['payload']['headers'] ) && is_array( $payload['payload']['headers'] ) ? $payload['payload']['headers'] : array();

		$body  = self::extract_body( $payload, $format );
		$cut   = self::truncate_text( $body, $max_chars );
		$names = self::get_attachment_names( $payload );

		$message = array(
			'id'              => isset( $payload['id'] ) ? (string) $payload['id'] : (string) $fallback_id,
			'thread_id'       => isset( $payload['threadId'] ) ? (string) $payload['threadId'] : '',
			'labels'          => isset( $payload['labelIds'] ) && is_array( $payload['labelIds'] ) ? array_values( array_map( 'strval', $payload['labelIds'] ) ) : array(),
			'subject'         => (string) self::find_header_value( $headers, 'Subject' ),
			'from'            => (string) self::find_header_value( $headers, 'From' ),
			'to'              => (string) self::find_header_value( $headers, 'To' ),
			'date'            => (string) self::find_header_value( $headers, 'Date' ),
			'timestamp'       => self::get_timestamp( $payload ),
			'body_format'     => $format,
			'body'            => $cut['text'],
			'truncated'       => $cut['truncated'],
			'body_chars'      => self::mb_strlen_safe( $cut['text'] ),
			'has_attachments' => count( $names ) > 0,
			'permalink'       => sprintf( 'https://mail.google.com/mail/u/0/#all/%s', rawurlencode( (string) ( isset( $payload['id'] ) ? $payload['id'] : $fallback_id ) ) ),
		);

		if ( $include_attachments ) {
			$message['attachment_names'] = $names;
		}
		if ( $include_headers ) {
			$message['headers'] = self::get_headers_list( $payload );
		}

		return $message;
	}

	/**
	 * Extract a readable body from a Gmail message payload.
	 *
	 * Prefers the text/plain part (plain format) or the text/html part (html
	 * format) and falls back to the other when missing. HTML is sanitised to a
	 * narrow allowlist that excludes images, styles, and scripts (no tracking
	 * pixels). Plain output strips tags and inserts line breaks for blocks.
	 *
	 * @param array  $payload Raw Gmail message payload.
	 * @param string $format  Body format: "plain" or "html".
	 * @return string Extracted body text.
	 */
	public static function extract_body( $payload, $format = 'plain' ) {
		$format = ( 'html' === $format ) ? 'html' : 'plain';

		$root       = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$candidates = array();
		self::collect_body_parts( $root, $candidates );

		$plain_part = null;
		$html_part  = null;
		foreach ( $candidates as $candidate ) {
			$mime = isset( $candidate['mimeType'] ) ? strtolower( (string) $candidate['mimeType'] ) : '';
			if ( 'text/plain' === $mime && null === $plain_part ) {
				$plain_part = $candidate;
			}
			if ( 'text/html' === $mime && null === $html_part ) {
				$html_part = $candidate;
			}
		}

		if ( 'html' === $format ) {
			$chosen = null !== $html_part ? $html_part : $plain_part;
		} else {
			$chosen = null !== $plain_part ? $plain_part : $html_part;
		}
		if ( null === $chosen ) {
			return '';
		}

		$text = self::decode_part_body( $chosen );
		if ( '' === $text ) {
			return '';
		}

		$mime = isset( $chosen['mimeType'] ) ? strtolower( (string) $chosen['mimeType'] ) : '';
		if ( 'text/html' === $mime && 'plain' === $format ) {
			return wp_strip_all_tags( self::html_to_plain_lines( $text ) );
		}
		if ( 'text/html' === $mime && 'html' === $format ) {
			return wp_kses( $text, self::get_html_allowlist() );
		}

		return $text;
	}

	/**
	 * Recursively collect body-capable parts from a MIME part tree.
	 *
	 * Attached emails (message/rfc822) are deliberately skipped: they are
	 * attachments, not body candidates.
	 *
	 * @param array $part       MIME part.
	 * @param array $candidates Accumulator for body-capable parts.
	 * @return void
	 */
	private static function collect_body_parts( $part, &$candidates ) {
		if ( ! is_array( $part ) ) {
			return;
		}

		$mime = isset( $part['mimeType'] ) ? strtolower( (string) $part['mimeType'] ) : '';
		if ( in_array( $mime, array( 'text/plain', 'text/html' ), true ) ) {
			$candidates[] = $part;
		}

		if ( ! empty( $part['parts'] ) && is_array( $part['parts'] ) ) {
			foreach ( $part['parts'] as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$child_mime = isset( $child['mimeType'] ) ? strtolower( (string) $child['mimeType'] ) : '';
				if ( 'message/rfc822' === $child_mime ) {
					continue;
				}
				self::collect_body_parts( $child, $candidates );
			}
		}
	}

	/**
	 * Decode a MIME part body (base64url + optional quoted-printable).
	 *
	 * @param array $part MIME part with body.data.
	 * @return string Decoded, UTF-8-safe text (empty on failure).
	 */
	public static function decode_part_body( $part ) {
		if ( ! is_array( $part ) || empty( $part['body']['data'] ) || ! is_string( $part['body']['data'] ) ) {
			return '';
		}

		// Gmail body.data is base64url without padding.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding Gmail message-part bodies is the documented API behaviour.
		$decoded = base64_decode( strtr( $part['body']['data'], '-_', '+/' ), true );
		if ( false === $decoded ) {
			return '';
		}

		$headers  = isset( $part['headers'] ) && is_array( $part['headers'] ) ? $part['headers'] : array();
		$encoding = strtolower( self::find_header_value( $headers, 'Content-Transfer-Encoding' ) );
		if ( 'quoted-printable' === $encoding ) {
			$decoded = quoted_printable_decode( $decoded );
		}

		$decoded = str_replace( "\x00", '', $decoded );
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$decoded = wp_check_invalid_utf8( $decoded );
		}

		return $decoded;
	}

	/**
	 * Convert HTML to plain-ish text by inserting line breaks for block boundaries.
	 *
	 * @param string $html HTML body.
	 * @return string Tag-free text with block-level line breaks.
	 */
	private static function html_to_plain_lines( $html ) {
		$breaks = array( '</p>', '<br>', '<br/>', '<br />', '</div>', '</tr>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</td>', '</th>' );

		return wp_strip_all_tags( str_ireplace( $breaks, "\n", $html ) );
	}

	/**
	 * Narrow HTML allowlist for sanitised HTML bodies.
	 *
	 * Deliberately excludes img, style, script, form, iframe and similar tags:
	 * mail HTML is untrusted content and remote images double as tracking
	 * pixels. Basic text structure survives; everything else is dropped.
	 *
	 * @return array wp_kses allowlist.
	 */
	private static function get_html_allowlist() {
		return array(
			'p'          => array(),
			'br'         => array(),
			'hr'         => array(),
			'div'        => array(),
			'span'       => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'a'          => array(
				'href'  => true,
				'title' => true,
			),
			'code'       => array(),
			'pre'        => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'td'         => array(
				'colspan' => true,
			),
			'th'         => array(
				'colspan' => true,
			),
		);
	}

	/**
	 * Truncate text to a character cap, preferring a word boundary.
	 *
	 * @param string $text      Text to truncate.
	 * @param int    $max_chars Maximum characters.
	 * @return array Array with keys text and truncated.
	 */
	public static function truncate_text( $text, $max_chars ) {
		$text      = (string) $text;
		$max_chars = absint( $max_chars );

		if ( $max_chars < 1 || self::mb_strlen_safe( $text ) <= $max_chars ) {
			return array(
				'text'      => $text,
				'truncated' => false,
			);
		}

		$cut        = self::mb_substr_safe( $text, 0, $max_chars );
		$last_space = self::mb_strrpos_safe( $cut, ' ' );
		if ( false !== $last_space && $last_space > (int) floor( $max_chars * 0.5 ) ) {
			$cut = self::mb_substr_safe( $cut, 0, $last_space );
		}

		return array(
			'text'      => rtrim( $cut ),
			'truncated' => true,
		);
	}

	/**
	 * Truncate a search snippet to a character cap.
	 *
	 * @param string $snippet   Snippet text.
	 * @param int    $max_chars Maximum characters (0 returns an empty string).
	 * @return string Truncated snippet.
	 */
	public static function truncate_snippet( $snippet, $max_chars ) {
		$snippet   = (string) $snippet;
		$max_chars = absint( $max_chars );

		if ( '' === $snippet || $max_chars < 1 ) {
			return '';
		}
		if ( self::mb_strlen_safe( $snippet ) <= $max_chars ) {
			return $snippet;
		}

		return rtrim( self::mb_substr_safe( $snippet, 0, $max_chars ) );
	}

	/**
	 * Collect attachment filenames from a message payload.
	 *
	 * @param array $payload Raw Gmail message payload.
	 * @return array<string> Unique attachment filenames.
	 */
	public static function get_attachment_names( $payload ) {
		$names = array();
		$root  = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();

		self::collect_attachment_names( $root, $names );

		return array_values( array_unique( $names ) );
	}

	/**
	 * Recursively walk a MIME part tree collecting attachment filenames.
	 *
	 * @param array $part  MIME part.
	 * @param array $names Accumulator for filenames.
	 * @return void
	 */
	private static function collect_attachment_names( $part, &$names ) {
		if ( ! is_array( $part ) ) {
			return;
		}

		if ( ! empty( $part['filename'] ) && is_string( $part['filename'] ) ) {
			$names[] = $part['filename'];
		}

		if ( ! empty( $part['parts'] ) && is_array( $part['parts'] ) ) {
			foreach ( $part['parts'] as $child ) {
				self::collect_attachment_names( $child, $names );
			}
		}
	}

	/**
	 * Collect the full header list from a message payload.
	 *
	 * @param array $payload Raw Gmail message payload.
	 * @return array List of arrays with name and value keys.
	 */
	public static function get_headers_list( $payload ) {
		$headers = isset( $payload['payload']['headers'] ) && is_array( $payload['payload']['headers'] ) ? $payload['payload']['headers'] : array();

		$list = array();
		foreach ( $headers as $header ) {
			if ( ! is_array( $header ) || empty( $header['name'] ) ) {
				continue;
			}
			$list[] = array(
				'name'  => (string) $header['name'],
				'value' => isset( $header['value'] ) ? (string) $header['value'] : '',
			);
		}

		return $list;
	}

	/**
	 * Extract the epoch-seconds timestamp from a message payload.
	 *
	 * @param array $payload Raw Gmail message payload.
	 * @return int|null Epoch seconds or null when unavailable.
	 */
	public static function get_timestamp( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['internalDate'] ) ) {
			return null;
		}

		return (int) floor( absint( $payload['internalDate'] ) / 1000 );
	}

	/**
	 * Locate a header value within the Gmail message headers array.
	 *
	 * @param array  $headers List of header objects from the Gmail API response.
	 * @param string $name    Header name to find.
	 * @return string Header value (empty when missing).
	 */
	public static function find_header_value( $headers, $name ) {
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
	 * Append query parameters to a URL, expanding list values into repeated parameters.
	 *
	 * @param string $url        Base URL.
	 * @param array  $parameters Query parameters. Array values expand into repeated keys.
	 * @return string URL with query string appended.
	 */
	public static function append_query_string( $url, $parameters ) {
		$parts = array();

		foreach ( $parameters as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( null === $item || '' === $item ) {
						continue;
					}

					$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $item );
				}
			} elseif ( null !== $value && '' !== $value ) {
				$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}

		if ( empty( $parts ) ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $parts );
	}

	/**
	 * Resolve the plugin settings array.
	 *
	 * @return array Plugin settings.
	 */
	private static function get_settings() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
		}

		return WP_MCP_AI_Admin_Settings::get_settings();
	}

	/**
	 * Resolve the configured HTTP request timeout.
	 *
	 * @return int Timeout in seconds (minimum 5).
	 */
	public static function get_request_timeout() {
		$settings = self::get_settings();

		return isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;
	}

	/**
	 * Multibyte-safe string length.
	 *
	 * @param string $text Text to measure.
	 * @return int Character count.
	 */
	public static function mb_strlen_safe( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string   $text   Text to slice.
	 * @param int      $start  Start offset.
	 * @param int|null $length Optional length.
	 * @return string Sliced text.
	 */
	public static function mb_substr_safe( $text, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $text, $start, $length, 'UTF-8' );
		}

		return null === $length ? substr( (string) $text, $start ) : substr( (string) $text, $start, $length );
	}

	/**
	 * Multibyte-safe last position of a needle.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Needle to find.
	 * @return int|false Position or false when not found.
	 */
	public static function mb_strrpos_safe( $haystack, $needle ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( (string) $haystack, (string) $needle, 0, 'UTF-8' );
		}

		return strrpos( (string) $haystack, (string) $needle );
	}
}
