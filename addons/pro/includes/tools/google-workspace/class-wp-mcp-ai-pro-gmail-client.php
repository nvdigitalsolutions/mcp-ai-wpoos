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

		// The tool schema documents the fallback as "settings-based
		// credentials"; assistants sometimes pass the literal string
		// "settings" instead of omitting the argument. Treat it as the
		// settings fallback so the call self-corrects instead of failing
		// with connection_not_found.
		if ( 'settings' === $connection_id ) {
			$connection_id = '';
		}

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

		$status = 'empty';
		$body   = self::extract_body( $payload, $format, $status );
		$cut    = self::truncate_text( $body, $max_chars );
		$names  = self::get_attachment_names( $payload );

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
			'body_source'     => $status,
			'truncated'       => $cut['truncated'],
			'body_chars'      => self::mb_strlen_safe( $cut['text'] ),
			'has_attachments' => count( $names ) > 0,
			'permalink'       => sprintf( 'https://mail.google.com/mail/u/0/#all/%s', rawurlencode( (string) ( isset( $payload['id'] ) ? $payload['id'] : $fallback_id ) ) ),
		);

		// When no text could be extracted, tell the caller why instead of
		// returning a bare empty string: no_text_parts means the message is
		// attachment-only (or the text lives somewhere unusual), empty_parts
		// means text parts exist but none decoded to non-empty text.
		if ( '' === $cut['text'] ) {
			$message['body_empty_reason'] = ( 'no_text_parts' === $status ) ? 'no_text_parts' : 'empty_parts';
		}

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
	 * Prefers the requested format (text/plain for "plain", text/html for
	 * "html") and falls back to the other format when the preferred parts are
	 * missing or decode to empty text — Gmail frequently returns zero-size
	 * placeholder text/plain parts next to the real text/html content. HTML is
	 * sanitised to a narrow allowlist that excludes images, styles, and scripts
	 * (no tracking pixels). Plain output strips tags and inserts line breaks
	 * for blocks. When nothing decodable exists, the optional $status
	 * reference reports why: "plain", "html", "no_text_parts", or
	 * "empty_parts".
	 *
	 * @param array       $payload Raw Gmail message payload.
	 * @param string      $format  Body format: "plain" or "html".
	 * @param string|null $status  Optional output: body source or empty reason.
	 * @return string Extracted body text.
	 */
	public static function extract_body( $payload, $format = 'plain', &$status = null ) {
		$format = ( 'html' === $format ) ? 'html' : 'plain';
		$status = 'empty';

		$root       = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$candidates = array();
		self::collect_body_parts( $root, $candidates );

		if ( empty( $candidates ) ) {
			// Attached/forwarded emails keep their text inside message/rfc822
			// parts; those are skipped in the main pass because they are
			// attachments, but when nothing else exists they are the only
			// place body text can be.
			self::collect_body_parts( $root, $candidates, true );
		}

		if ( empty( $candidates ) ) {
			$status = 'no_text_parts';

			return '';
		}

		$preferred = ( 'html' === $format ) ? array( 'text/html', 'text/plain' ) : array( 'text/plain', 'text/html' );

		foreach ( $preferred as $mime ) {
			foreach ( $candidates as $candidate ) {
				$candidate_mime = isset( $candidate['mimeType'] ) ? strtolower( (string) $candidate['mimeType'] ) : '';
				if ( $mime !== $candidate_mime ) {
					continue;
				}

				$text = self::decode_part_body( $candidate );
				if ( '' === trim( $text ) ) {
					continue;
				}

				$status = ( 'text/html' === $candidate_mime ) ? 'html' : 'plain';

				if ( 'text/html' === $candidate_mime && 'plain' === $format ) {
					return wp_strip_all_tags( self::html_to_plain_lines( $text ) );
				}
				if ( 'text/html' === $candidate_mime && 'html' === $format ) {
					return wp_kses( $text, self::get_html_allowlist() );
				}

				return $text;
			}
		}

		$status = 'empty_parts';

		return '';
	}

	/**
	 * Recursively collect body-capable parts from a MIME part tree.
	 *
	 * Attached emails (message/rfc822) are skipped by default: they are
	 * attachments, not body candidates. Set $include_rfc822 to also descend
	 * into them — used as a last resort when the outer message carries no
	 * text of its own.
	 *
	 * @param array $part           MIME part.
	 * @param array $candidates     Accumulator for body-capable parts.
	 * @param bool  $include_rfc822 Whether to descend into message/rfc822 parts.
	 * @return void
	 */
	private static function collect_body_parts( $part, &$candidates, $include_rfc822 = false ) {
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
				if ( 'message/rfc822' === $child_mime && ! $include_rfc822 ) {
					continue;
				}
				self::collect_body_parts( $child, $candidates, $include_rfc822 );
			}
		}
	}

	/**
	 * Decode a MIME part body (base64url + optional base64/quoted-printable
	 * transfer encoding).
	 *
	 * @param array $part MIME part with body.data.
	 * @return string Decoded, UTF-8-safe text (empty on failure).
	 */
	public static function decode_part_body( $part ) {
		if ( ! is_array( $part ) || empty( $part['body']['data'] ) || ! is_string( $part['body']['data'] ) ) {
			return '';
		}

		// Gmail body.data is base64url without padding — re-pad before strict
		// decoding or parts whose encoded length is not a multiple of 4 fail
		// with false (contributing to misleading empty_parts reports).
		$data = strtr( $part['body']['data'], '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( 0 !== $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding Gmail message-part bodies is the documented API behaviour.
		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return '';
		}

		$headers  = isset( $part['headers'] ) && is_array( $part['headers'] ) ? $part['headers'] : array();
		$encoding = strtolower( self::find_header_value( $headers, 'Content-Transfer-Encoding' ) );
		if ( 'base64' === $encoding ) {
			// A base64 transfer-encoded part stores base64-ASCII on the wire,
			// so the first decode yields another base64 layer. Tolerate
			// whitespace and absent padding on the inner layer.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding Gmail message-part bodies is the documented API behaviour.
			$inner = base64_decode( preg_replace( '/\s+/', '', $decoded ) );
			if ( false !== $inner ) {
				$decoded = $inner;
			}
		} elseif ( 'quoted-printable' === $encoding ) {
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
	 * Collect fetchable attachment descriptors from a message payload.
	 *
	 * Unlike get_attachment_names(), this also captures the attachmentId and
	 * partId needed to download attachment bytes via the Gmail attachments
	 * endpoint — so a tool can read the content of an attached PDF, image, or
	 * oversized inline part rather than only its filename.
	 *
	 * @param array $payload Raw Gmail message payload.
	 * @return array List of arrays with keys filename, attachment_id, part_id,
	 *               mime_type and size (when available).
	 */
	public static function get_attachment_descriptors( $payload ) {
		$descriptors = array();
		$root        = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();

		self::collect_attachment_descriptors( $root, $descriptors );

		return $descriptors;
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
	 * Recursively walk a MIME part tree collecting attachment descriptors.
	 *
	 * A part is treated as a fetchable attachment when it carries both an
	 * attachmentId and a filename (or a partId) — this covers both genuine
	 * attachments and oversized inline parts Gmail stores as attachmentId.
	 *
	 * @param array $part        MIME part.
	 * @param array $descriptors Accumulator for attachment descriptors.
	 * @return void
	 */
	private static function collect_attachment_descriptors( $part, &$descriptors ) {
		if ( ! is_array( $part ) ) {
			return;
		}

		$attachment_id = isset( $part['body']['attachmentId'] ) ? (string) $part['body']['attachmentId'] : '';
		if ( '' !== $attachment_id ) {
			$size = isset( $part['body']['size'] ) ? absint( $part['body']['size'] ) : 0;
			if ( $size < 1 && isset( $part['body']['data'] ) && is_string( $part['body']['data'] ) ) {
				$size = (int) ceil( strlen( $part['body']['data'] ) * 3 / 4 );
			}

			$descriptors[] = array(
				'filename'      => isset( $part['filename'] ) ? (string) $part['filename'] : '',
				'attachment_id' => $attachment_id,
				'part_id'       => isset( $part['partId'] ) ? (string) $part['partId'] : '',
				'mime_type'     => isset( $part['mimeType'] ) ? (string) $part['mimeType'] : '',
				'size'          => $size,
			);
		}

		if ( ! empty( $part['parts'] ) && is_array( $part['parts'] ) ) {
			foreach ( $part['parts'] as $child ) {
				self::collect_attachment_descriptors( $child, $descriptors );
			}
		}
	}

	/**
	 * Download attachment bytes via the Gmail attachments endpoint.
	 *
	 * @param string $gmail_user       Gmail user identifier (email address or "me").
	 * @param string $message_id       Gmail message ID.
	 * @param string $attachment_id    Attachment ID from the message payload.
	 * @param string $access_token     OAuth access token.
	 * @param int    $timeout          Request timeout in seconds.
	 * @return string|WP_Error Raw decoded attachment bytes or WP_Error.
	 */
	public static function fetch_attachment( $gmail_user, $message_id, $attachment_id, $access_token, $timeout ) {
		$path = sprintf( 'messages/%s/attachments/%s', rawurlencode( $message_id ), rawurlencode( $attachment_id ) );

		$payload = self::request( 'GET', $gmail_user, $path, $access_token, $timeout );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload['data'] ) || ! is_string( $payload['data'] ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_attachment_empty', __( 'The Gmail attachment returned no data.', 'mcp-ai-wpoos-pro' ) );
		}

		// Attachment data is base64url without padding — re-pad before strict
		// decoding or payloads whose encoded length is not a multiple of 4
		// (most binary attachments) fail with false.
		$data = strtr( $payload['data'], '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( 0 !== $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding Gmail attachment bodies is the documented API behaviour.
		$bytes = base64_decode( $data, true );
		if ( false === $bytes ) {
			return new WP_Error( 'wp_mcp_ai_gmail_attachment_invalid', __( 'The Gmail attachment data was not valid base64.', 'mcp-ai-wpoos-pro' ) );
		}

		return $bytes;
	}

	/**
	 * Fetch and inline any text-bearing part Gmail stored behind an attachmentId.
	 *
	 * Gmail serves oversized (or odd-encoded) text/plain and text/html parts as
	 * `body.attachmentId` with no `body.data`, which previously left an
	 * otherwise-readable message reporting `empty_parts`. This walks the MIME
	 * tree and, for each text/plain or text/html part that has an attachmentId
	 * but no usable `data`, downloads the bytes and stores them back as
	 * `body.data` so extract_body() can decode them normally.
	 *
	 * Failures are collected and returned rather than thrown, so a single
	 * un-fetchable part does not break the whole message.
	 *
	 * @param array  $payload      Raw Gmail message payload (mutated in place).
	 * @param string $gmail_user   Gmail user identifier (email address or "me").
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @return array List of non-fatal WP_Error entries; empty when all parts hydrated.
	 */
	public static function hydrate_inline_attachments( &$payload, $gmail_user, $message_id, $access_token, $timeout ) {
		$errors = array();

		if ( ! is_array( $payload ) || empty( $payload['payload'] ) || ! is_array( $payload['payload'] ) ) {
			return $errors;
		}

		self::hydrate_inline_parts( $payload['payload'], $gmail_user, $message_id, $access_token, $timeout, $errors );

		return $errors;
	}

	/**
	 * Recursive helper for hydrate_inline_attachments().
	 *
	 * @param array  $part         MIME part (mutated in place).
	 * @param string $gmail_user   Gmail user identifier.
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @param array  $errors       Accumulator for non-fatal WP_Error entries.
	 * @return void
	 */
	private static function hydrate_inline_parts( &$part, $gmail_user, $message_id, $access_token, $timeout, &$errors ) {
		if ( ! is_array( $part ) ) {
			return;
		}

		if ( isset( $part['parts'] ) && is_array( $part['parts'] ) ) {
			foreach ( $part['parts'] as &$child ) {
				self::hydrate_inline_parts( $child, $gmail_user, $message_id, $access_token, $timeout, $errors );
			}
			unset( $child );
		}

		$mime = isset( $part['mimeType'] ) ? strtolower( (string) $part['mimeType'] ) : '';
		if ( ! in_array( $mime, array( 'text/plain', 'text/html' ), true ) ) {
			return;
		}

		// A filename marks a genuine attachment (e.g. a .txt or .html file the
		// sender attached), not an inline body part — leave it for the
		// attachment pipeline instead of folding it into the message body.
		if ( ! empty( $part['filename'] ) ) {
			return;
		}

		// Only hydrate parts that lack usable inline data but carry an attachmentId.
		$has_data = isset( $part['body']['data'] ) && is_string( $part['body']['data'] ) && '' !== trim( $part['body']['data'] );
		if ( $has_data || empty( $part['body']['attachmentId'] ) ) {
			return;
		}

		$attachment_id = (string) $part['body']['attachmentId'];
		$bytes         = self::fetch_attachment( $gmail_user, $message_id, $attachment_id, $access_token, $timeout );
		if ( is_wp_error( $bytes ) ) {
			$errors[] = $bytes;

			return;
		}

		// Store the decoded bytes as base64url so decode_part_body() recovers
		// them; clear the transfer-encoding header so it does not double-decode.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Re-encoding fetched inline part bytes for decode_part_body() exactly mirrors the Gmail API body.data contract.
		$part['body']['data'] = rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
		unset( $part['body']['attachmentId'] );

		if ( isset( $part['headers'] ) && is_array( $part['headers'] ) ) {
			foreach ( $part['headers'] as $key => $header ) {
				if ( isset( $header['name'] ) && 0 === strcasecmp( (string) $header['name'], 'Content-Transfer-Encoding' ) ) {
					$part['headers'][ $key ]['value'] = '';
				}
			}
		}
	}

	/**
	 * Extract human-readable text from an attachment's bytes, by MIME type.
	 *
	 * PDFs use the bundled smalot/pdfparser (pure PHP) when available, with a
	 * clear fallback when it is missing or the PDF has no text layer. Other
	 * text-bearing types are decoded directly; binary/non-text types return a
	 * pointer to open the attachment in Gmail instead of failing silently.
	 *
	 * @param string $bytes     Raw attachment bytes.
	 * @param string $mime_type Attachment MIME type.
	 * @param string $filename  Optional filename for context.
	 * @return string|WP_Error Extracted text or a WP_Error with an actionable reason.
	 */
	public static function extract_attachment_text( $bytes, $mime_type, $filename = '' ) {
		$mime_type = strtolower( (string) $mime_type );

		if ( '' === $bytes ) {
			return new WP_Error( 'wp_mcp_ai_gmail_attachment_no_bytes', __( 'The attachment is empty.', 'mcp-ai-wpoos-pro' ) );
		}

		// Text-ish types can be returned directly without an extra dependency.
		if ( in_array( $mime_type, array( 'text/plain', 'text/csv', 'application/json', 'text/markdown' ), true ) || 0 === strpos( $mime_type, 'text/' ) ) {
			if ( function_exists( 'wp_check_invalid_utf8' ) ) {
				return wp_check_invalid_utf8( str_replace( "\x00", '', $bytes ) );
			}

			return str_replace( "\x00", '', $bytes );
		}

		if ( 'application/pdf' !== $mime_type ) {
			return new WP_Error(
				'wp_mcp_ai_gmail_attachment_unsupported_type',
				sprintf(
					/* translators: %s: attachment filename or MIME type. */
					__( 'Attachment "%s" is not a text or PDF file; open the permalink to view it in Gmail.', 'mcp-ai-wpoos-pro' ),
					'' !== (string) $filename ? (string) $filename : $mime_type
				)
			);
		}

		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_pdf_parser_missing', __( 'PDF text extraction is unavailable because the PDF parser library is not installed.', 'mcp-ai-wpoos-pro' ) );
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseContent( $bytes );
			$text   = $pdf->getText();

			if ( function_exists( 'wp_check_invalid_utf8' ) ) {
				$text = wp_check_invalid_utf8( $text );
			}

			if ( '' === trim( str_replace( "\x00", '', (string) $text ) ) ) {
				return new WP_Error( 'wp_mcp_ai_gmail_pdf_no_text_layer', __( 'The PDF has no extractable text layer (it may be a scanned image). Use the OCR tools to read it.', 'mcp-ai-wpoos-pro' ) );
			}

			return (string) $text;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_gmail_pdf_extraction_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'PDF text extraction failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Download every fetchable attachment and extract its text where possible.
	 *
	 * Returns one entry per attachment descriptor with filename, MIME type,
	 * attachment/part IDs, size, extracted text (truncated to $max_chars), and
	 * an error string when extraction failed or the type is unsupported. This
	 * lets a read tool surface the content of an attached PDF (e.g. settlement
	 * figures) rather than only its filename.
	 *
	 * @param array  $payload      Raw Gmail message payload.
	 * @param string $gmail_user   Gmail user identifier (email address or "me").
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @param int    $max_chars    Maximum extracted text characters per attachment.
	 * @return array List of attachment arrays with text extraction results.
	 */
	public static function get_attachments_with_text( $payload, $gmail_user, $message_id, $access_token, $timeout, $max_chars ) {
		$max_chars = absint( $max_chars );
		if ( $max_chars < 1 ) {
			$max_chars = 4000;
		}

		$descriptors = self::get_attachment_descriptors( $payload );
		$attachments = array();

		foreach ( $descriptors as $descriptor ) {
			$bytes = self::fetch_attachment( $gmail_user, $message_id, $descriptor['attachment_id'], $access_token, $timeout );

			$entry = array(
				'filename'      => $descriptor['filename'],
				'mime_type'     => $descriptor['mime_type'],
				'attachment_id' => $descriptor['attachment_id'],
				'part_id'       => $descriptor['part_id'],
				'size'          => $descriptor['size'],
				'text'          => '',
				'truncated'     => false,
				'error'         => '',
			);

			if ( is_wp_error( $bytes ) ) {
				$entry['error'] = $bytes->get_error_message();
				$attachments[]  = $entry;
				continue;
			}

			$text = self::extract_attachment_text( $bytes, $descriptor['mime_type'], $descriptor['filename'] );
			if ( is_wp_error( $text ) ) {
				$entry['error'] = $text->get_error_message();
				$attachments[]  = $entry;
				continue;
			}

			$cut                = self::truncate_text( (string) $text, $max_chars );
			$entry['text']      = $cut['text'];
			$entry['truncated'] = $cut['truncated'];
			$attachments[]      = $entry;
		}

		return $attachments;
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
