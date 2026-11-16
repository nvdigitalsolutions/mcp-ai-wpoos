<?php
/**
 * Port: generation of opaque identifiers.
 *
 * Injected so tests can be deterministic. Identifiers produced here are
 * **opaque** — never derived from a MAC address (802.11bh-2024, RFC 9724) and
 * never sequential in a way that leaks venue volume.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Interfaces;

/**
 * An opaque identifier generator.
 *
 * @since 0.1.0
 */
interface Interface_WiFi_Id_Generator {

	/**
	 * A new opaque device identifier.
	 *
	 * @return string
	 */
	public function new_device_id(): string;

	/**
	 * A new opaque session identifier.
	 *
	 * @return string
	 */
	public function new_session_id(): string;
}
