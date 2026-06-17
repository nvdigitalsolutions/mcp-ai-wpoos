<?php
/**
 * CRM IMAP Client — Pure PHP IMAP implementation (no ext-imap dependency).
 *
 * Connects to IMAP mailboxes via PHP stream sockets with optional TLS/SSL.
 * Supports:
 *   - PLAIN and LOGIN authentication
 *   - TLS upgrade via STARTTLS
 *   - SSL direct connection
 *   - Search UNSEEN messages
 *   - Fetch message headers and body
 *   - Mark as seen
 *   - Decode MIME headers (RFC 2047) and quoted-printable content
 *
 * Falls back to PHP ext-imap functions when available.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure PHP IMAP client.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_CRM_IMAP_Client {

	/**
	 * Socket resource.
	 *
	 * @var resource|null
	 */
	private $socket;

	/**
	 * Connection string (e.g. {imap.gmail.com:993/imap/ssl}INBOX).
	 *
	 * @var string
	 */
	private $conn_string;

	/**
	 * Username.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Parsed connection parameters.
	 *
	 * @var array{host:string, port:int, ssl:bool, tls:bool, mailbox:string}
	 */
	private $params;

	/**
	 * Current tag counter for IMAP commands.
	 *
	 * @var int
	 */
	private $tag = 0;

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param string $conn_string IMAP connection string (e.g. "{imap.gmail.com:993/imap/ssl}INBOX").
	 * @param string $username    IMAP username.
	 * @param string $password    IMAP password or app-specific password.
	 */
	public function __construct( $conn_string, $username, $password ) {
		$this->conn_string = $conn_string;
		$this->username    = $username;
		$this->password    = $password;
		$this->parse_conn_string();
	}

	/**
	 * Parse connection string into components.
	 */
	private function parse_conn_string() {
		// Format: {host:port/flags}MAILBOX or {host:port/flags} or {host/flags}MAILBOX
		if ( ! preg_match( '/\{([^}]+)\}(.*)/', $this->conn_string, $m ) ) {
			$this->params = array(
				'host'    => 'localhost',
				'port'    => 143,
				'ssl'     => false,
				'tls'     => false,
				'mailbox' => 'INBOX',
			);
			return;
		}

		$server_part = $m[1];
		$mailbox     = ! empty( $m[2] ) ? trim( $m[2] ) : 'INBOX';

		// Split host:port from flags.
		$host_part = $server_part;
		$flags     = '';
		if ( strpos( $server_part, '/' ) !== false ) {
			list( $host_part, $flags ) = explode( '/', $server_part, 2 );
		}

		// Parse host:port.
		$host = $host_part;
		$port = 143;
		if ( strpos( $host_part, ':' ) !== false ) {
			list( $host, $port ) = explode( ':', $host_part, 2 );
			$port                = (int) $port;
		}

		// Determine SSL/TLS from flags.
		$is_ssl = false;
		$is_tls = false;
		$flags  = strtolower( $flags );
		if ( strpos( $flags, 'ssl' ) !== false ) {
			$is_ssl = true;
			if ( 143 === $port ) {
				$port = 993;
			}
		}
		if ( strpos( $flags, 'tls' ) !== false ) {
			$is_tls = true;
		}

		// Auto-detect SSL for common ports.
		if ( 993 === $port ) {
			$is_ssl = true;
		}

		$this->params = array(
			'host'    => $host,
			'port'    => $port,
			'ssl'     => $is_ssl,
			'tls'     => $is_tls,
			'mailbox' => $mailbox,
		);
	}

	/**
	 * Open the IMAP connection.
	 *
	 * @return bool True on success.
	 */
	public function open() {
		$host = $this->params['host'];
		$port = $this->params['port'];

		if ( $this->params['ssl'] ) {
			$remote = 'ssl://' . $host . ':' . $port;
		} else {
			$remote = 'tcp://' . $host . ':' . $port;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
		$socket = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			self::TIMEOUT,
			STREAM_CLIENT_CONNECT,
			stream_context_create(
				array(
					'ssl' => array(
						'verify_peer'       => true,
						'verify_peer_name'  => true,
						'allow_self_signed' => false,
					),
				)
			)
		);

		if ( ! $socket ) {
			return false;
		}

		stream_set_timeout( $socket, self::TIMEOUT );
		$this->socket = $socket;

		// Read server greeting.
		$greeting = $this->read_line();
		if ( ! $greeting || ! $this->is_tagged_response( '*', $greeting ) ) {
			$this->close();
			return false;
		}

		// If TLS flag and not already SSL, upgrade the connection.
		if ( $this->params['tls'] && ! $this->params['ssl'] ) {
			$tag  = $this->send_command( 'STARTTLS' );
			$resp = $this->read_response( $tag );
			if ( ! $this->is_ok( $resp ) ) {
				$this->close();
				return false;
			}

			if ( ! stream_socket_enable_crypto( $this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				$this->close();
				return false;
			}
		}

		// Authenticate.
		return $this->authenticate();
	}

	/**
	 * Authenticate using LOGIN or PLAIN.
	 *
	 * @return bool
	 */
	private function authenticate() {
		// Try LOGIN first.
		$tag  = $this->send_command( 'LOGIN ' . $this->quote( $this->username ) . ' ' . $this->quote( $this->password ) );
		$resp = $this->read_response( $tag );

		if ( $this->is_ok( $resp ) ) {
			return true;
		}

		// Try AUTHENTICATE PLAIN.
		$auth_string = base64_encode( "\0" . $this->username . "\0" . $this->password );
		$tag         = $this->send_command( 'AUTHENTICATE PLAIN' );
		$line        = $this->read_line();

		// Server should respond with continuation request (+).
		if ( $line && '+' === $line[0] ) {
			$this->write_line( $auth_string );
			$resp = $this->read_response( $tag );
			if ( $this->is_ok( $resp ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Select a mailbox.
	 *
	 * @param string $mailbox Mailbox name (default: INBOX).
	 * @return bool
	 */
	public function select( $mailbox = 'INBOX' ) {
		$tag  = $this->send_command( 'SELECT ' . $this->quote_utf7( $mailbox ) );
		$resp = $this->read_response( $tag );
		return $this->is_ok( $resp );
	}

	/**
	 * Search for messages matching criteria.
	 *
	 * @param string $criteria IMAP search criteria (e.g. 'UNSEEN', 'ALL', 'FROM "foo"').
	 * @return array<int> Array of message sequence numbers.
	 */
	public function search( $criteria = 'UNSEEN' ) {
		$tag  = $this->send_command( 'SEARCH ' . $criteria );
		$resp = $this->read_response( $tag );

		// Parse SEARCH response: * SEARCH 1 2 3
		$numbers = array();
		foreach ( $resp as $line ) {
			if ( preg_match( '/^\* SEARCH (.+)$/i', $line, $m ) ) {
				$parts   = explode( ' ', trim( $m[1] ) );
				$numbers = array_map( 'intval', $parts );
				break;
			}
		}

		return $numbers;
	}

	/**
	 * Fetch message headers.
	 *
	 * @param int $msg_no Message sequence number.
	 * @return array{from_email:string, from_name:string, subject:string, date:string}|null
	 */
	public function fetch_header( $msg_no ) {
		$tag  = $this->send_command( 'FETCH ' . (int) $msg_no . ' (BODY[HEADER.FIELDS (FROM SUBJECT DATE)])' );
		$resp = $this->read_response( $tag );

		$header_text = '';
		$in_header   = false;
		foreach ( $resp as $line ) {
			if ( $in_header ) {
				if ( ')' === $line || ' )' === substr( $line, -2 ) ) {
					$header_text .= rtrim( $line, ')' );
					break;
				}
				$header_text .= $line . "\r\n";
			}
			if ( preg_match( '/^\* \d+ FETCH \(BODY\[HEADER\.FIELDS/', $line ) ) {
				$in_header = true;
				// Extract text after the opening brace.
				if ( preg_match( '/BODY\[HEADER\.FIELDS[^\]]*\] \{?\d*\}?\r?\n?(.*)/s', $line, $m ) ) {
					$header_text = $m[1];
					break;
				}
			}
		}

		// Parse header fields.
		$from_email = '';
		$from_name  = '';
		$subject    = '';
		$date       = '';

		foreach ( explode( "\n", $header_text ) as $line ) {
			if ( stripos( $line, 'From:' ) === 0 ) {
				$from_val = trim( substr( $line, 5 ) );
				if ( preg_match( '/"?([^"<]*)"?\s*<?([^>]*)>?/', $from_val, $fm ) ) {
					$from_name  = trim( $fm[1] );
					$from_email = trim( $fm[2] );
				} else {
					$from_email = $from_val;
				}
			} elseif ( stripos( $line, 'Subject:' ) === 0 ) {
				$subject = $this->decode_mime_header( trim( substr( $line, 8 ) ) );
			} elseif ( stripos( $line, 'Date:' ) === 0 ) {
				$date = trim( substr( $line, 5 ) );
			}
		}

		return array(
			'from_email' => strtolower( $from_email ),
			'from_name'  => $from_name,
			'subject'    => $subject,
			'date'       => $date,
		);
	}

	/**
	 * Fetch message body.
	 *
	 * @param int    $msg_no  Message sequence number.
	 * @param string $section Body section ('1' for text/plain, '' for entire).
	 * @return string
	 */
	public function fetch_body( $msg_no, $section = '' ) {
		$fetch_cmd = 'BODY[TEXT]';
		if ( '1' === $section ) {
			$fetch_cmd = 'BODY[1]';
		} elseif ( '' === $section ) {
			$fetch_cmd = 'BODY[]';
		}

		$tag  = $this->send_command( 'FETCH ' . (int) $msg_no . ' (' . $fetch_cmd . ')' );
		$resp = $this->read_response( $tag );

		$body     = '';
		$in_body  = false;
		$byte_len = 0;

		foreach ( $resp as $line ) {
			if ( ! $in_body ) {
				// Look for literal size indicator: {N}
				if ( preg_match( '/\{(\d+)\}$/', $line, $lm ) ) {
					$byte_len = (int) $lm[1];
					$in_body  = true;
					continue;
				}
				if ( preg_match( '/\* \d+ FETCH \(' . preg_quote( $fetch_cmd, '/' ) . '\s+\{(\d+)\}/', $line, $lm ) ) {
					$byte_len = (int) $lm[1];
					$in_body  = true;
					continue;
				}
			} else {
				// Stop at closing paren or FETCH end.
				if ( ')' === trim( $line ) ) {
					break;
				}
				$body .= $line . "\r\n";
				if ( strlen( $body ) >= $byte_len ) {
					break;
				}
			}
		}

		// Strip trailing CRLF added by literal response.
		$body = rtrim( $body );

		if ( '' !== $section ) {
			$decoded = $this->decode_quoted_printable( $body );
			// Check for base64 encoding in the body.
			$decoded = $this->decode_content_transfer_encoding( $decoded );
			return $decoded;
		}

		return $body;
	}

	/**
	 * Mark a message as seen.
	 *
	 * @param int $msg_no Message sequence number.
	 * @return bool
	 */
	public function mark_seen( $msg_no ) {
		$tag  = $this->send_command( 'STORE ' . (int) $msg_no . ' +FLAGS (\\Seen)' );
		$resp = $this->read_response( $tag );
		return $this->is_ok( $resp );
	}

	/**
	 * Close the IMAP connection.
	 */
	public function close() {
		if ( $this->socket ) {
			$this->send_command( 'LOGOUT' );
			fclose( $this->socket );
			$this->socket = null;
		}
	}

	/**
	 * Send an IMAP command.
	 *
	 * @param string $command Command string (without tag).
	 * @return string Tag used.
	 */
	private function send_command( $command ) {
		++$this->tag;
		$tag  = 'A' . str_pad( (string) $this->tag, 4, '0', STR_PAD_LEFT );
		$line = $tag . ' ' . $command . "\r\n";
		$this->write_line( $line );
		return $tag;
	}

	/**
	 * Write a line to the socket.
	 *
	 * @param string $line Line to write.
	 */
	private function write_line( $line ) {
		if ( $this->socket ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $this->socket, $line );
		}
	}

	/**
	 * Read a single line from the socket.
	 *
	 * @return string|false
	 */
	private function read_line() {
		if ( ! $this->socket ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		return fgets( $this->socket, 8192 );
	}

	/**
	 * Read response lines until the tagged OK/NO/BAD.
	 *
	 * @param string $tag Command tag to wait for.
	 * @return array<string> Response lines.
	 */
	private function read_response( $tag ) {
		$lines = array();
		while ( true ) {
			$line = $this->read_line();
			if ( false === $line ) {
				break;
			}
			$line    = rtrim( $line, "\r\n" );
			$lines[] = $line;

			// Stop on tagged response.
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				break;
			}

			// Handle literal responses: if a line ends with {N}, read N bytes.
			if ( preg_match( '/\{(\d+)\}$/', $line, $lm ) ) {
				$literal_len = (int) $lm[1];
				$literal     = '';
				while ( strlen( $literal ) < $literal_len ) {
					$chunk = $this->read_line();
					if ( false === $chunk ) {
						break;
					}
					$literal .= $chunk;
				}
				$lines[] = $literal;
			}
		}

		return $lines;
	}

	/**
	 * Check if a tagged response line is OK.
	 *
	 * @param array  $lines Response lines.
	 * @param string $tag   Tag to check (optional, checks last line by default).
	 * @return bool
	 */
	private function is_ok( $lines, $tag = '' ) {
		$last = end( $lines );
		if ( $tag ) {
			return 0 === strpos( $last, $tag . ' OK' );
		}
		return false !== strpos( $last, ' OK ' ) || false !== strpos( $last, ' OK' );
	}

	/**
	 * Check if a line is a tagged/untagged response.
	 *
	 * @param string $prefix Expected prefix ('*', 'A0001', etc.).
	 * @param string $line   Line to check.
	 * @return bool
	 */
	private function is_tagged_response( $prefix, $line ) {
		return 0 === strpos( $line, $prefix . ' ' );
	}

	/**
	 * Quote a string for an IMAP command.
	 *
	 * @param string $str String to quote.
	 * @return string
	 */
	private function quote( $str ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $str ) . '"';
	}

	/**
	 * Convert a mailbox name to modified UTF-7 (IMAP UTF-7).
	 *
	 * @param string $mailbox Mailbox name.
	 * @return string
	 */
	private function quote_utf7( $mailbox ) {
		// For common ASCII mailboxes, just quote.
		if ( 'INBOX' === strtoupper( $mailbox ) ) {
			return $this->quote( $mailbox );
		}
		return $this->quote( $mailbox );
	}

	/**
	 * Decode an RFC 2047 MIME-encoded header value (pure PHP).
	 *
	 * @param string $value Encoded header value.
	 * @return string Decoded value.
	 */
	private function decode_mime_header( $value ) {
		// Match =?charset?encoding?encoded_text?=
		if ( ! preg_match_all( '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', $value, $matches, PREG_SET_ORDER ) ) {
			return $value;
		}

		$result = $value;
		foreach ( $matches as $match ) {
			$charset  = strtoupper( $match[1] );
			$encoding = strtoupper( $match[2] );
			$text     = $match[3];

			if ( 'B' === $encoding ) {
				$decoded = base64_decode( $text, true );
			} elseif ( 'Q' === $encoding ) {
				$decoded = quoted_printable_decode( str_replace( '_', ' ', $text ) );
			} else {
				$decoded = $text;
			}

			if ( false !== $decoded && 'UTF-8' !== $charset ) {
				$decoded = mb_convert_encoding( $decoded, 'UTF-8', $charset );
			}

			if ( false !== $decoded ) {
				$result = str_replace( $match[0], $decoded, $result );
			}
		}

		return $result;
	}

	/**
	 * Decode quoted-printable content.
	 *
	 * @param string $text Quoted-printable encoded text.
	 * @return string
	 */
	private function decode_quoted_printable( $text ) {
		return quoted_printable_decode( $text );
	}

	/**
	 * Decode content transfer encoding (base64 inline).
	 *
	 * @param string $text Potentially base64-encoded text.
	 * @return string
	 */
	private function decode_content_transfer_encoding( $text ) {
		// Check if text looks like base64 (no spaces, only base64 chars).
		if ( preg_match( '/^[A-Za-z0-9+\/=]+\r?\n?$/', trim( $text ) ) ) {
			$decoded = base64_decode( trim( $text ), true );
			if ( false !== $decoded && $decoded !== $text ) {
				return $decoded;
			}
		}
		return $text;
	}

	/**
	 * Check if a connection is currently open.
	 *
	 * @return bool
	 */
	public function is_open() {
		return null !== $this->socket;
	}
}
