<?php
declare(strict_types=1);

namespace NvoosContentGraph\Commerce;

use NvoosContentGraph\Schema;

use function bin2hex;
use function get_option;
use function random_bytes;
use function update_option;

/**
 * Local license/purchase record.
 *
 * A single, autoload-free option holds the record of the most recent
 * successful purchase for this site: the license key, Stripe identifiers,
 * price paid, and purchaser. No card or secret-key data is ever stored.
 *
 * @since 1.0.4
 */
final class License {

	/**
	 * Persist a purchase record.
	 *
	 * @since 1.0.4
	 *
	 * @param array<string,mixed> $record Purchase record.
	 * @return bool True on success.
	 */
	public static function save( array $record ): bool {
		return (bool) update_option( Schema::OPTION_LICENSE, $record, false );
	}

	/**
	 * Retrieve the purchase record.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,mixed> Empty array when no purchase exists.
	 */
	public static function get(): array {
		$record = get_option( Schema::OPTION_LICENSE, array() );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Whether a valid purchase record exists.
	 *
	 * @since 1.0.4
	 *
	 * @return bool
	 */
	public static function isLicensed(): bool {
		return '' !== self::licenseKey();
	}

	/**
	 * The stored license key (empty when unlicensed).
	 *
	 * @return string
	 */
	public static function licenseKey(): string {
		return (string) ( self::get()['license_key'] ?? '' );
	}

	/**
	 * Generate a new license key.
	 *
	 * 40 hex characters from a CSPRNG — suitable as an opaque license
	 * identifier (not a bearer credential; validation happens on the
	 * vendor side when the addon checks in).
	 *
	 * @since 1.0.4
	 *
	 * @return string
	 */
	public static function generateKey(): string {
		return bin2hex( random_bytes( 20 ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
