<?php
/**
 * The canonical KPI vocabulary — modelled on Wi-Fi Alliance Data Elements.
 *
 * The Wi-Fi Alliance "Wi-Fi CERTIFIED Data Elements" specification (v2.1)
 * defines 130 KPIs reported agent→collector. This class ships a documented
 * **subset** in that shape so that:
 *
 *   1. every controller adapter has one fixed target to normalise to;
 *   2. exported metrics speak the vocabulary enterprise RFPs already use;
 *   3. a metric cannot be invented ad hoc inside a dashboard.
 *
 * Two properties matter more than the list itself:
 *
 *   - `identity_sensitive` marks KPIs that count *entities* (clients, sessions).
 *     Those require a `method` on every sample, because an entity count is
 *     meaningless without knowing whether it counted Device IDs, consenting
 *     subjects, or rotating probe hashes (research §6.1).
 *
 *   - `de_name` is the upstream name. Our keys are stable internal identifiers;
 *     the DE name is what we export.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

/**
 * Static registry of permitted KPI keys and their definitions.
 *
 * @since 0.1.0
 */
final class Kpi_Schema {

	/**
	 * Canonical KPI definitions.
	 *
	 * Shape: `internal_key => array( de_name, type, unit, identity_sensitive )`.
	 *
	 * `type` is `gauge` (a value at a point in time) or `counter` (monotonic
	 * within a window). `unit` uses SI-ish tokens so a consumer can format
	 * without a lookup table: `count`, `percent`, `dbm`, `ms`, `mbps`.
	 *
	 * @var array<string, array{de_name:string, type:string, unit:string, identity_sensitive:bool}>
	 */
	const DEFINITIONS = array(
		// --- Service / access ------------------------------------------------
		'wifi.clients.connected'                => array(
			'de_name'            => 'Connected Clients',
			'type'               => 'gauge',
			'unit'               => 'count',
			'identity_sensitive' => true,
		),
		'wifi.clients.unique'                   => array(
			'de_name'            => 'Unique Clients',
			'type'               => 'gauge',
			'unit'               => 'count',
			'identity_sensitive' => true,
		),
		'wifi.sessions.started'                 => array(
			'de_name'            => 'Sessions Started',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => true,
		),
		'wifi.sessions.completed'               => array(
			'de_name'            => 'Sessions Completed',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => true,
		),
		'wifi.portal.splash_views'              => array(
			'de_name'            => 'Captive Portal Views',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => false,
		),
		'wifi.portal.splash_completions'        => array(
			'de_name'            => 'Captive Portal Completions',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => false,
		),
		'wifi.portal.consent_marketing_granted' => array(
			'de_name'            => 'Marketing Consent Granted',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => false,
		),
		'wifi.portal.consent_marketing_refused' => array(
			'de_name'            => 'Marketing Consent Refused',
			'type'               => 'counter',
			'unit'               => 'count',
			'identity_sensitive' => false,
		),
		// --- Radio -----------------------------------------------------------
		'wifi.radio.retry_rate'                 => array(
			'de_name'            => 'Retry Rate',
			'type'               => 'gauge',
			'unit'               => 'percent',
			'identity_sensitive' => false,
		),
		'wifi.radio.airtime_utilization'        => array(
			'de_name'            => 'Airtime Utilization',
			'type'               => 'gauge',
			'unit'               => 'percent',
			'identity_sensitive' => false,
		),
		'wifi.radio.channel_utilization'        => array(
			'de_name'            => 'Channel Utilization',
			'type'               => 'gauge',
			'unit'               => 'percent',
			'identity_sensitive' => false,
		),
		'wifi.radio.noise_floor'                => array(
			'de_name'            => 'Noise Floor',
			'type'               => 'gauge',
			'unit'               => 'dbm',
			'identity_sensitive' => false,
		),
		'wifi.radio.tx_power'                   => array(
			'de_name'            => 'Transmit Power',
			'type'               => 'gauge',
			'unit'               => 'dbm',
			'identity_sensitive' => false,
		),
		'wifi.radio.rssi_median'                => array(
			'de_name'            => 'Median RSSI',
			'type'               => 'gauge',
			'unit'               => 'dbm',
			'identity_sensitive' => false,
		),
		// --- Quality ---------------------------------------------------------
		'wifi.quality.throughput_down'          => array(
			'de_name'            => 'Downlink Throughput',
			'type'               => 'gauge',
			'unit'               => 'mbps',
			'identity_sensitive' => false,
		),
		'wifi.quality.throughput_up'            => array(
			'de_name'            => 'Uplink Throughput',
			'type'               => 'gauge',
			'unit'               => 'mbps',
			'identity_sensitive' => false,
		),
		'wifi.quality.latency'                  => array(
			'de_name'            => 'Latency',
			'type'               => 'gauge',
			'unit'               => 'ms',
			'identity_sensitive' => false,
		),
		'wifi.quality.dns_failure_rate'         => array(
			'de_name'            => 'DNS Failure Rate',
			'type'               => 'gauge',
			'unit'               => 'percent',
			'identity_sensitive' => false,
		),
	);

	/**
	 * All canonical KPI keys.
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array_keys( self::DEFINITIONS );
	}

	/**
	 * Whether a key is part of the schema.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return isset( self::DEFINITIONS[ $key ] );
	}

	/**
	 * Definition for a key.
	 *
	 * @param string $key Canonical key.
	 * @return array{de_name:string, type:string, unit:string, identity_sensitive:bool}|null
	 */
	public static function definition( string $key ): ?array {
		return self::DEFINITIONS[ $key ] ?? null;
	}

	/**
	 * Whether a key counts entities and therefore requires a `method`.
	 *
	 * @param string $key Canonical key.
	 * @return bool
	 */
	public static function requires_method( string $key ): bool {
		$definition = self::definition( $key );

		return null !== $definition && true === $definition['identity_sensitive'];
	}

	/**
	 * The schema as a canonical array, for exports and contract tests.
	 *
	 * @return array<string, array{de_name:string, type:string, unit:string, identity_sensitive:bool}>
	 */
	public static function to_array(): array {
		return self::DEFINITIONS;
	}
}
