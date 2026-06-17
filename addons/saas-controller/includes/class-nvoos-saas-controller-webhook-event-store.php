<?php
/**
 * Webhook event ring-buffer store.
 *
 * Persists a bounded history of inbound webhook events (today: Stripe
 * only) so the Operations tab can show "what just happened" without a
 * round-trip to the upstream provider. Each entry captures only a small,
 * privacy-respecting summary of the event — the raw body is never
 * retained, and PII fields are not extracted.
 *
 * The store is intentionally idempotent: re-delivery of the same event id
 * (Stripe will retry until it sees a 2xx) is detected at insertion time
 * and no second copy is appended. The receiver's REST handler relies on
 * this so that retries produce 200 fast without double-recording the
 * audit-log entry.
 *
 * Privacy: the store keeps `event.id`, `event.type`, the timestamp, the
 * provider name, and a short message. It deliberately does **not** keep
 * customer email / billing address / card-fingerprint data.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event-store singleton.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Webhook_Event_Store {

	/**
	 * WP option name.
	 *
	 * @var string
	 */
	const OPTION = 'nvoos_saas_controller_webhook_events';

	/**
	 * Default ring-buffer cap.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ENTRIES = 200;

	/**
	 * Allowed provider channels.
	 *
	 * @var string[]
	 */
	const ALLOWED_PROVIDERS = array( 'stripe' );

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
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
	 * Record a webhook event.
	 *
	 * If an entry with the same `provider` + `event_id` already exists,
	 * this is a no-op and the existing entry is returned (idempotent
	 * re-delivery).
	 *
	 * @since 0.1.0
	 *
	 * @param array $entry {
	 *     Entry data.
	 *
	 *     @type string $provider   One of {@see self::ALLOWED_PROVIDERS}.
	 *     @type string $event_id   Provider-supplied event id (Stripe `evt_…`).
	 *     @type string $event_type Provider-supplied event type (e.g. `invoice.paid`).
	 *     @type int    $timestamp  Provider-supplied event timestamp (unix seconds).
	 *     @type string $message    Short human-readable message; never contains PII.
	 *     @type string $signature_status  `verified` / `mismatch` / etc.
	 * }
	 * @return array|null The recorded (or pre-existing) entry, or null when the input is invalid.
	 */
	public function record( array $entry ) {
		$provider = isset( $entry['provider'] ) ? (string) $entry['provider'] : '';
		if ( ! in_array( $provider, self::ALLOWED_PROVIDERS, true ) ) {
			return null;
		}

		$event_id = isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '';
		$event_id = self::sanitise_short( $event_id );
		if ( '' === $event_id ) {
			return null;
		}

		$existing = $this->find_by_event_id( $provider, $event_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$sanitised = array(
			'ts'               => time(),
			'provider'         => $provider,
			'event_id'         => $event_id,
			'event_type'       => self::sanitise_short( isset( $entry['event_type'] ) ? $entry['event_type'] : '' ),
			'event_timestamp'  => isset( $entry['timestamp'] ) ? max( 0, (int) $entry['timestamp'] ) : 0,
			'signature_status' => self::sanitise_short( isset( $entry['signature_status'] ) ? $entry['signature_status'] : '' ),
			'message'          => self::sanitise_message( isset( $entry['message'] ) ? $entry['message'] : '' ),
		);

		$entries   = $this->read_entries();
		$entries[] = $sanitised;

		$max = (int) apply_filters( 'nvoos_saas_controller_webhook_events_max_entries', self::DEFAULT_MAX_ENTRIES );
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
	 * Find an existing entry by provider + event_id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider Provider name.
	 * @param string $event_id Event id.
	 * @return array|null
	 */
	public function find_by_event_id( $provider, $event_id ) {
		$provider = (string) $provider;
		$event_id = (string) $event_id;
		if ( '' === $provider || '' === $event_id ) {
			return null;
		}
		foreach ( $this->read_entries() as $row ) {
			if (
				isset( $row['provider'], $row['event_id'] )
				&& $row['provider'] === $provider
				&& $row['event_id'] === $event_id
			) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Get the most recent entries, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit  Max entries (1..max-cap, default 50).
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
	 * Clear the entire store.
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
	 * Sanitise a short identifier-style field. Caps at 96 chars.
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
