<?php
/**
 * Append-only audit log for the NV oOS SaaS Controller.
 *
 * Every outbound call the addon makes (Cloudflare list/probe, Stripe
 * preflight, OpenRouter preflight, plan run, eventually Apply) is recorded
 * here with an actor (logged-in admin), channel, action, target, status,
 * latency, and a short human-readable message. The store is a ring buffer
 * persisted in a single WP option — the addon never inserts into custom
 * tables and never logs secrets.
 *
 * The log is not a substitute for a full SIEM-style trail; it is a
 * **forensic-friendly recent history** for the operator running the
 * Operations tab in WP-Admin. The ring-buffer cap (default 200) is
 * filterable via `nvoos_saas_controller_audit_log_max_entries`.
 *
 * Privacy: callers must never pass plaintext credentials, full request
 * bodies, or response bodies into `record()`. The `message` field is
 * intended for short status strings; passwords and API tokens must be
 * stripped before this class is invoked. Callers can suppress an entry
 * entirely by returning `false` from the
 * `nvoos_saas_controller_audit_log_record` filter.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit log singleton.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Audit_Log {

	/**
	 * WP option name.
	 *
	 * @var string
	 */
	const OPTION = 'nvoos_saas_controller_audit_log';

	/**
	 * Default ring-buffer cap.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ENTRIES = 200;

	/**
	 * Allowed channels. Validated in {@see record()} so test code and
	 * downstream filters can't sneak in arbitrary values.
	 *
	 * @var string[]
	 */
	const ALLOWED_CHANNELS = array( 'cloudflare', 'stripe', 'openrouter', 'internal' );

	/**
	 * Allowed statuses.
	 *
	 * @var string[]
	 */
	const ALLOWED_STATUSES = array( 'ok', 'error' );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (test helper).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function reset_for_tests() {
		self::$instance = null;
	}

	/**
	 * Record an audit-log entry.
	 *
	 * Returns the recorded entry (after sanitisation) so callers — typically
	 * `Connection_Tester`, `Cloudflare_Client`, or the smoke tester — can
	 * include it in their own response without re-fetching the log.
	 *
	 * @since 0.1.0
	 *
	 * @param array $entry { // phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop -- inline @type comments handle periods individually.
	 *     @type string $channel    One of {@see self::ALLOWED_CHANNELS}.
	 *     @type string $action     Short verb (e.g., `list_d1_databases`, `preflight`).
	 *     @type string $target     Optional target identifier (account slug, namespace ID).
	 *     @type string $status     `ok` or `error`.
	 *     @type int    $latency_ms Optional latency in milliseconds.
	 *     @type string $message    Short human-readable message; never contains secrets.
	 *     @type string $request_id Optional caller-supplied correlation ID.
	 * }
	 * @return array|null The sanitised entry, or `null` if recording was suppressed.
	 */
	public function record( array $entry ) {
		$channel = isset( $entry['channel'] ) ? (string) $entry['channel'] : '';
		if ( ! in_array( $channel, self::ALLOWED_CHANNELS, true ) ) {
			$channel = 'internal';
		}

		$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'ok';
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			$status = 'error';
		}

		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user_login = '';
		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_login ) ) {
				$user_login = (string) $user->user_login;
			}
		}

		$sanitised = array(
			'ts'         => time(),
			'actor_id'   => $user_id,
			'actor'      => $user_login,
			'channel'    => $channel,
			'action'     => self::sanitise_short( isset( $entry['action'] ) ? $entry['action'] : '' ),
			'target'     => self::sanitise_short( isset( $entry['target'] ) ? $entry['target'] : '' ),
			'status'     => $status,
			'latency_ms' => isset( $entry['latency_ms'] ) ? max( 0, (int) $entry['latency_ms'] ) : 0,
			'message'    => self::sanitise_message( isset( $entry['message'] ) ? $entry['message'] : '' ),
			'request_id' => self::sanitise_short( isset( $entry['request_id'] ) ? $entry['request_id'] : '' ),
		);

		/**
		 * Filter audit-log entries before they are persisted.
		 *
		 * Return `false` to suppress the entry entirely (useful for privacy
		 * exemptions or when the calling site wants its own audit pipeline).
		 * Return a modified array to rewrite fields. Returning anything else
		 * preserves the sanitised entry as-is.
		 *
		 * @since 0.1.0
		 *
		 * @param array $sanitised The sanitised entry about to be persisted.
		 * @param array $original  The unsanitised input as supplied by the caller.
		 */
		$filtered = apply_filters( 'nvoos_saas_controller_audit_log_record', $sanitised, $entry );
		if ( false === $filtered ) {
			return null;
		}
		if ( is_array( $filtered ) ) {
			$sanitised = wp_parse_args( $filtered, $sanitised );
		}

		$entries   = $this->read_entries();
		$entries[] = $sanitised;

		$max = (int) apply_filters( 'nvoos_saas_controller_audit_log_max_entries', self::DEFAULT_MAX_ENTRIES );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_ENTRIES;
		}
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -$max );
		}

		update_option( self::OPTION, $entries, false );
		return $sanitised;
	}

	/**
	 * Get the most recent entries, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit  Max entries to return (1..max-cap, default 50).
	 * @param int $offset Offset into the reversed list.
	 * @return array<int,array>
	 */
	public function get_recent( $limit = 50, $offset = 0 ) {
		$limit  = max( 1, min( (int) $limit, self::DEFAULT_MAX_ENTRIES ) );
		$offset = max( 0, (int) $offset );

		$entries = array_reverse( $this->read_entries() );
		return array_slice( $entries, $offset, $limit );
	}

	/**
	 * Total number of entries currently retained.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->read_entries() );
	}

	/**
	 * Clear the entire audit log.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Read raw entries from the option, defending against corruption.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array>
	 */
	protected function read_entries() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Sanitise a short identifier-style field (action / target / request_id).
	 *
	 * Trims to 96 chars and runs `sanitize_text_field` to strip control
	 * characters and HTML.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function sanitise_short( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( wp_strip_all_tags( $value ) );
		if ( strlen( $value ) > 96 ) {
			$value = substr( $value, 0, 96 );
		}
		return $value;
	}

	/**
	 * Sanitise the human-readable message field. Caps at 512 chars.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function sanitise_message( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( wp_strip_all_tags( $value ) );
		if ( strlen( $value ) > 512 ) {
			$value = substr( $value, 0, 512 );
		}
		return $value;
	}
}
