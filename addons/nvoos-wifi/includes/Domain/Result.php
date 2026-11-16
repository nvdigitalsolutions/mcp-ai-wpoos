<?php
/**
 * Immutable outcome of a domain operation.
 *
 * The domain layer may not return `WP_Error`. `WP_Error` is a WordPress class,
 * and ADR 001 requires the domain to be a leaf that knows nothing about
 * WordPress (enforced by `bin/check-domain-purity.php`). Every host therefore
 * maps this object to its own wire format at the boundary:
 *
 *   WordPress → `WP_Error` / `WP_REST_Response`
 *   Laravel   → exception / `JsonResponse`
 *
 * `to_array()` is the canonical serialisation. Both hosts must produce
 * byte-identical output from it — that is the Phase 0 portability test
 * (Proposal 150, implementation plan T-0.11).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

use LogicException;

/**
 * A success or failure outcome with an optional value and a stable error code.
 *
 * @since 0.1.0
 */
final class Result {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private bool $ok;

	/**
	 * Payload on success. Callers must treat this as read-only.
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Machine-readable error code. Empty on success.
	 *
	 * Codes are stable identifiers (`invalid_window`, `mac_not_allowed`, …) so
	 * that both hosts and the tool layer can branch on them without parsing
	 * prose.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Human-readable error message. Empty on success.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Private constructor — use {@see self::ok()} or {@see self::fail()}.
	 *
	 * @param bool   $ok      Whether the operation succeeded.
	 * @param mixed  $value   Payload on success.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	private function __construct( bool $ok, $value, string $code, string $message ) {
		$this->ok      = $ok;
		$this->value   = $value;
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Build a success outcome.
	 *
	 * @param mixed $value Optional payload.
	 * @return self
	 */
	public static function ok( $value = null ): self {
		return new self( true, $value, '', '' );
	}

	/**
	 * Build a failure outcome.
	 *
	 * @param string $code    Stable machine-readable error code.
	 * @param string $message Human-readable explanation.
	 * @return self
	 */
	public static function fail( string $code, string $message ): self {
		return new self( false, null, $code, $message );
	}

	/**
	 * Whether the operation succeeded.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * Whether the operation failed.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return ! $this->ok;
	}

	/**
	 * The success payload.
	 *
	 * @return mixed
	 * @throws LogicException When called on a failed result.
	 */
	public function value() {
		if ( ! $this->ok ) {
			throw new LogicException(
				sprintf( 'Cannot read the value of a failed Result (%s).', $this->code )
			);
		}

		return $this->value;
	}

	/**
	 * The error code, or an empty string on success.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * The error message, or an empty string on success.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Canonical serialisation shared by every host.
	 *
	 * The key order is fixed so that two hosts producing the same domain outcome
	 * produce byte-identical JSON.
	 *
	 * @return array{ok:bool, error:array{code:string, message:string}|null, value:mixed}
	 */
	public function to_array(): array {
		return array(
			'ok'    => $this->ok,
			'error' => $this->ok
				? null
				: array(
					'code'    => $this->code,
					'message' => $this->message,
				),
			'value' => $this->value,
		);
	}
}
