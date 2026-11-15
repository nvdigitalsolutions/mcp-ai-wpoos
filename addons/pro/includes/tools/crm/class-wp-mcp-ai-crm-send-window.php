<?php
/**
 * CRM Send Window
 *
 * Resolves a recipient's timezone and evaluates a channel send window in
 * that recipient's local time. Replies are never window-blocked.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipient-local send windows.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Send_Window {

	/**
	 * Settings option.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mcp_ai_crm_send_window_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Off by default: a hard business-hours gate would silently change
			// the timing of sequences that already exist. Opt in per site.
			'enabled'           => false,
			'start'             => 8,
			'end'               => 17,
			'weekdays_only'     => true,
			'fallback_timezone' => '',
			'country_overrides' => array(),
		);
	}

	/**
	 * Resolved settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args( $stored, self::defaults() );

		/**
		 * Filter send-window settings.
		 *
		 * @param array $settings Settings.
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_send_window_settings', $settings );

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Flush cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Whether a kind is a reply (never held by the window).
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public static function is_reply( $kind ) {
		return in_array( sanitize_key( $kind ), array( 'reply', 'transactional' ), true );
	}

	/**
	 * Resolve a lead's timezone.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return array{zone:string,source:string}
	 */
	public static function resolve_timezone( $lead_id ) {
		$lead_id = absint( $lead_id );

		$explicit = $lead_id ? (string) get_post_meta( $lead_id, '_crm_timezone', true ) : '';
		if ( self::is_valid_zone( $explicit ) ) {
			return array(
				'zone'   => $explicit,
				'source' => 'explicit',
			);
		}

		$country = $lead_id ? strtoupper( (string) get_post_meta( $lead_id, '_crm_country', true ) ) : '';
		$state   = $lead_id ? strtoupper( (string) get_post_meta( $lead_id, '_crm_state', true ) ) : '';
		if ( $country ) {
			$zone = self::zone_for_country( $country, $state );
			if ( $zone ) {
				return array(
					'zone'   => $zone,
					'source' => 'country',
				);
			}
		}

		$phone = $lead_id ? (string) get_post_meta( $lead_id, 'phone', true ) : '';
		if ( $phone ) {
			$zone = self::zone_for_phone( $phone );
			if ( $zone ) {
				return array(
					'zone'   => $zone,
					'source' => 'phone',
				);
			}
		}

		$settings = self::get_settings();
		if ( self::is_valid_zone( $settings['fallback_timezone'] ) ) {
			return array(
				'zone'   => $settings['fallback_timezone'],
				'source' => 'fallback',
			);
		}

		$site = wp_timezone_string();
		return array(
			'zone'   => self::is_valid_zone( $site ) ? $site : 'UTC',
			'source' => 'site',
		);
	}

	/**
	 * Whether a zone identifier is valid.
	 *
	 * @param string $zone Zone.
	 * @return bool
	 */
	private static function is_valid_zone( $zone ) {
		if ( ! is_string( $zone ) || '' === $zone ) {
			return false;
		}
		return in_array( $zone, timezone_identifiers_list(), true );
	}

	/**
	 * Map a country (and optional state) to a representative zone.
	 *
	 * This is intentionally a compact, explicit map: a wrong guess is worse
	 * than the site fallback, and the resolution source is logged.
	 *
	 * @param string $country Country code.
	 * @param string $state   State/province code.
	 * @return string
	 */
	private static function zone_for_country( $country, $state = '' ) {
		$settings  = self::get_settings();
		$overrides = isset( $settings['country_overrides'] ) && is_array( $settings['country_overrides'] ) ? $settings['country_overrides'] : array();
		if ( isset( $overrides[ $country ] ) && self::is_valid_zone( $overrides[ $country ] ) ) {
			return $overrides[ $country ];
		}

		if ( 'US' === $country ) {
			$us = array(
				'CT' => 'America/New_York',
				'DE' => 'America/New_York',
				'FL' => 'America/New_York',
				'GA' => 'America/New_York',
				'IN' => 'America/Indiana/Indianapolis',
				'KY' => 'America/New_York',
				'ME' => 'America/New_York',
				'MD' => 'America/New_York',
				'MA' => 'America/New_York',
				'MI' => 'America/Detroit',
				'NH' => 'America/New_York',
				'NJ' => 'America/New_York',
				'NY' => 'America/New_York',
				'NC' => 'America/New_York',
				'OH' => 'America/New_York',
				'PA' => 'America/New_York',
				'RI' => 'America/New_York',
				'SC' => 'America/New_York',
				'VT' => 'America/New_York',
				'VA' => 'America/New_York',
				'WV' => 'America/New_York',
				'AL' => 'America/Chicago',
				'AR' => 'America/Chicago',
				'IL' => 'America/Chicago',
				'IA' => 'America/Chicago',
				'KS' => 'America/Chicago',
				'LA' => 'America/Chicago',
				'MN' => 'America/Chicago',
				'MS' => 'America/Chicago',
				'MO' => 'America/Chicago',
				'NE' => 'America/Chicago',
				'ND' => 'America/Chicago',
				'OK' => 'America/Chicago',
				'SD' => 'America/Chicago',
				'TN' => 'America/Chicago',
				'TX' => 'America/Chicago',
				'WI' => 'America/Chicago',
				'AZ' => 'America/Phoenix',
				'CO' => 'America/Denver',
				'ID' => 'America/Denver',
				'MT' => 'America/Denver',
				'NM' => 'America/Denver',
				'UT' => 'America/Denver',
				'WY' => 'America/Denver',
				'CA' => 'America/Los_Angeles',
				'NV' => 'America/Los_Angeles',
				'OR' => 'America/Los_Angeles',
				'WA' => 'America/Los_Angeles',
				'AK' => 'America/Anchorage',
				'HI' => 'Pacific/Honolulu',
			);
			if ( $state && isset( $us[ $state ] ) ) {
				return $us[ $state ];
			}
			return 'America/New_York';
		}

		if ( 'CA' === $country ) {
			return 'America/Toronto';
		}

		$map = array(
			'GB' => 'Europe/London',
			'IE' => 'Europe/Dublin',
			'DE' => 'Europe/Berlin',
			'FR' => 'Europe/Paris',
			'ES' => 'Europe/Madrid',
			'IT' => 'Europe/Rome',
			'NL' => 'Europe/Amsterdam',
			'BE' => 'Europe/Brussels',
			'CH' => 'Europe/Zurich',
			'AT' => 'Europe/Vienna',
			'SE' => 'Europe/Stockholm',
			'NO' => 'Europe/Oslo',
			'DK' => 'Europe/Copenhagen',
			'FI' => 'Europe/Helsinki',
			'PL' => 'Europe/Warsaw',
			'PT' => 'Europe/Lisbon',
			'GR' => 'Europe/Athens',
			'TR' => 'Europe/Istanbul',
			'AE' => 'Asia/Dubai',
			'SA' => 'Asia/Riyadh',
			'IN' => 'Asia/Kolkata',
			'LK' => 'Asia/Colombo',
			'SG' => 'Asia/Singapore',
			'MY' => 'Asia/Kuala_Lumpur',
			'ID' => 'Asia/Jakarta',
			'PH' => 'Asia/Manila',
			'JP' => 'Asia/Tokyo',
			'KR' => 'Asia/Seoul',
			'CN' => 'Asia/Shanghai',
			'HK' => 'Asia/Hong_Kong',
			'AU' => 'Australia/Sydney',
			'NZ' => 'Pacific/Auckland',
			'ZA' => 'Africa/Johannesburg',
			'NG' => 'Africa/Lagos',
			'KE' => 'Africa/Nairobi',
			'BR' => 'America/Sao_Paulo',
			'AR' => 'America/Argentina/Buenos_Aires',
			'MX' => 'America/Mexico_City',
			'CL' => 'America/Santiago',
		);

		return isset( $map[ $country ] ) ? $map[ $country ] : '';
	}

	/**
	 * Map a phone country code to a zone.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	private static function zone_for_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		$map   = array(
			'+1'   => 'America/New_York',
			'+44'  => 'Europe/London',
			'+49'  => 'Europe/Berlin',
			'+33'  => 'Europe/Paris',
			'+34'  => 'Europe/Madrid',
			'+39'  => 'Europe/Rome',
			'+31'  => 'Europe/Amsterdam',
			'+61'  => 'Australia/Sydney',
			'+64'  => 'Pacific/Auckland',
			'+91'  => 'Asia/Kolkata',
			'+94'  => 'Asia/Colombo',
			'+65'  => 'Asia/Singapore',
			'+81'  => 'Asia/Tokyo',
			'+82'  => 'Asia/Seoul',
			'+86'  => 'Asia/Shanghai',
			'+971' => 'Asia/Dubai',
			'+27'  => 'Africa/Johannesburg',
			'+55'  => 'America/Sao_Paulo',
			'+52'  => 'America/Mexico_City',
		);
		foreach ( $map as $prefix => $zone ) {
			if ( 0 === strpos( $phone, $prefix ) ) {
				return $zone;
			}
		}
		return '';
	}

	/**
	 * Whether the window is open for a lead.
	 *
	 * @param int                    $lead_id Lead post ID.
	 * @param string                 $channel Channel.
	 * @param string                 $kind    sequence|one_off|reply|transactional.
	 * @param DateTimeImmutable|null $now     Reference time (UTC).
	 * @return bool
	 */
	public static function is_open( $lead_id, $channel = 'email', $kind = 'sequence', ?DateTimeImmutable $now = null ) {
		if ( self::is_reply( $kind ) ) {
			return true;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return true;
		}

		$resolved = self::resolve_timezone( $lead_id );
		$tz       = new DateTimeZone( $resolved['zone'] );
		$now      = $now ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );

		$day = (int) $now->format( 'N' );
		if ( ! empty( $settings['weekdays_only'] ) && $day > 5 ) {
			return false;
		}

		$hour  = (int) $now->format( 'G' );
		$start = (int) $settings['start'];
		$end   = (int) $settings['end'];

		if ( $start === $end ) {
			return true;
		}
		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}
		// Overnight window.
		return $hour >= $start || $hour < $end;
	}

	/**
	 * Next time the window opens for a lead.
	 *
	 * @param int                    $lead_id Lead post ID.
	 * @param string                 $channel Channel.
	 * @param string                 $kind    Kind.
	 * @param DateTimeImmutable|null $now     Reference time (UTC).
	 * @return DateTimeImmutable In the recipient's timezone.
	 */
	public static function next_open( $lead_id, $channel = 'email', $kind = 'sequence', ?DateTimeImmutable $now = null ) {
		$settings = self::get_settings();
		$resolved = self::resolve_timezone( $lead_id );
		$tz       = new DateTimeZone( $resolved['zone'] );
		$now      = $now ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );

		if ( self::is_open( $lead_id, $channel, $kind, $now ) ) {
			return $now;
		}

		$start     = (int) $settings['start'];
		$end       = (int) $settings['end'];
		$candidate = $now;

		// If today's window has not opened yet, try today at start.
		if ( $start < $end && (int) $now->format( 'G' ) < $start ) {
			$candidate = $now->setTime( $start, 0 );
		} else {
			$candidate = $now->modify( '+1 day' )->setTime( $start, 0 );
		}

		// Skip weekends when configured.
		for ( $i = 0; $i < 14; $i++ ) {
			if ( empty( $settings['weekdays_only'] ) || (int) $candidate->format( 'N' ) <= 5 ) {
				return $candidate;
			}
			$candidate = $candidate->modify( '+1 day' )->setTime( $start, 0 );
		}

		return $candidate;
	}
}
