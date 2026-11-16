<?php
/**
 * Port: a Wi-Fi controller the product can read telemetry from.
 *
 * The port is **ours**, not the vendor's. `node-unifi`, `unifi-client` and the
 * OpenWrt tooling all change shape between releases; adapters absorb that churn
 * so domain and service code never sees it.
 *
 * Adapters report values in their **own native vocabulary** and declare a map
 * to canonical KPI keys via {@see self::kpi_map()}. Normalisation therefore
 * lives in one place (the service) rather than being reimplemented per adapter —
 * which is what makes a second controller a small job instead of a rewrite.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Interfaces;

use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;
use NvoosWifi\Domain\Window;

/**
 * Read-only controller telemetry adapter.
 *
 * Write operations (authorise/deauthorise a client) are deliberately absent from
 * Phase 0–2: the portal grants access through the guest network's own policy
 * mechanism, and mutating a controller is a separate, higher-risk surface that
 * needs its own proposal.
 *
 * @since 0.1.0
 */
interface Interface_WiFi_Controller_Adapter {

	/**
	 * Stable adapter identifier, e.g. `mock`, `unifi`.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Operator-facing adapter label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Probe adapter reachability and credential validity.
	 *
	 * Must distinguish authentication failure from network failure from rate
	 * limiting, so the caller never reports a misleading cause
	 * (implementation plan §7, row 7).
	 *
	 * @return Result `ok( array{reachable:bool, latency_ms:int, detail:string} )`.
	 */
	public function health(): Result;

	/**
	 * Map native keys to canonical KPI keys.
	 *
	 * Keys absent from this map are ignored by the normaliser, which is how an
	 * adapter declines to report a KPI it cannot measure.
	 *
	 * @return array<string, string> `native_key => canonical_kpi_key`.
	 */
	public function kpi_map(): array;

	/**
	 * The strongest device-identity method this adapter can supply.
	 *
	 * A controller that exposes only MAC addresses must return `probe_hash`
	 * (with the caveat that counts are weaker), and one that cannot supply a
	 * qualifying identity at all must return `null`. When this is `null` the
	 * service **skips** identity-sensitive KPIs rather than emitting an
	 * unqualified entity count.
	 *
	 * @return string|null One of `Device_Identity::METHODS`, or null.
	 */
	public function identity_method(): ?string;

	/**
	 * Read native radio/service statistics for a venue over a window.
	 *
	 * @param Venue  $venue  Venue.
	 * @param Window $window Time window.
	 * @return Result `ok( array{at:int, values:array<string, int|float|null>} )`.
	 */
	public function read_radio_stats( Venue $venue, Window $window ): Result;

	/**
	 * List currently associated clients.
	 *
	 * @param Venue $venue Venue.
	 * @return Result `ok( array<int, array{identity: \\NvoosWifi\\Domain\\Device_Identity, connected_at:int, rssi:int|null}> )`.
	 */
	public function list_clients( Venue $venue ): Result;
}
