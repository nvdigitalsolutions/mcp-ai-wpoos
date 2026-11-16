<?php
/**
 * Port implementation: opaque identifier generation.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Wp;

use NvoosWifi\Interfaces\Interface_WiFi_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cryptographically random opaque identifiers.
 *
 * Identifiers are random, not derived from a MAC address or from venue volume —
 * see {@see \NvoosWifi\Domain\Device_Identity} for why that distinction is
 * load-bearing.
 *
 * @since 0.1.0
 */
final class Class_Random_Id_Generator implements Interface_WiFi_Id_Generator {

	/**
	 * {@inheritdoc}
	 */
	public function new_device_id(): string {
		return 'dev_' . $this->random_hex( 16 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function new_session_id(): string {
		return 'ses_' . $this->random_hex( 16 );
	}

	/**
	 * Random lowercase hex string of the requested length.
	 *
	 * @param int $bytes Number of random bytes (produces 2x hex characters).
	 * @return string
	 */
	private function random_hex( int $bytes ): string {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Exception ) {
			// random_bytes only throws when no CSPRNG is available, which is a
			// fatal environment problem. Fail loudly rather than degrade to a
			// predictable identifier — a guessable device id would silently
			// undermine every identity guarantee in the domain layer.
			wp_die(
				esc_html__( 'NV oOS WiFi could not generate a secure identifier: no CSPRNG is available.', 'nvoos-wifi' )
			);
		}

		// Unreachable: wp_die() terminates the request.
		return '';
	}
}
