<?php
/**
 * Language Detection Service
 *
 * Provides language detection and phone formatting using franc, iso-639-1,
 * libphonenumber-js, and google-translate-api-x NPM packages.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for language detection and localization helpers.
 *
 * Wraps Node.js services (franc, iso-639-1, libphonenumber-js) via filter hooks,
 * with PHP-native fallbacks for environments without Node.js.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Language_Detection_Service {

	/**
	 * Common ISO 639-1 code → language name map (PHP fallback).
	 *
	 * @var array<string,string>
	 */
	private static $iso_names = array(
		'af' => 'Afrikaans',
		'ar' => 'Arabic',
		'bg' => 'Bulgarian',
		'bn' => 'Bengali',
		'ca' => 'Catalan',
		'cs' => 'Czech',
		'cy' => 'Welsh',
		'da' => 'Danish',
		'de' => 'German',
		'el' => 'Greek',
		'en' => 'English',
		'es' => 'Spanish',
		'et' => 'Estonian',
		'eu' => 'Basque',
		'fa' => 'Persian',
		'fi' => 'Finnish',
		'fr' => 'French',
		'ga' => 'Irish',
		'gl' => 'Galician',
		'gu' => 'Gujarati',
		'he' => 'Hebrew',
		'hi' => 'Hindi',
		'hr' => 'Croatian',
		'hu' => 'Hungarian',
		'hy' => 'Armenian',
		'id' => 'Indonesian',
		'is' => 'Icelandic',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ka' => 'Georgian',
		'ko' => 'Korean',
		'lt' => 'Lithuanian',
		'lv' => 'Latvian',
		'mk' => 'Macedonian',
		'ml' => 'Malayalam',
		'mn' => 'Mongolian',
		'mr' => 'Marathi',
		'ms' => 'Malay',
		'mt' => 'Maltese',
		'nl' => 'Dutch',
		'no' => 'Norwegian',
		'pa' => 'Punjabi',
		'pl' => 'Polish',
		'pt' => 'Portuguese',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sk' => 'Slovak',
		'sl' => 'Slovenian',
		'sq' => 'Albanian',
		'sr' => 'Serbian',
		'sv' => 'Swedish',
		'sw' => 'Swahili',
		'ta' => 'Tamil',
		'te' => 'Telugu',
		'th' => 'Thai',
		'tr' => 'Turkish',
		'uk' => 'Ukrainian',
		'ur' => 'Urdu',
		'vi' => 'Vietnamese',
		'zh' => 'Chinese',
	);

	/**
	 * Check if franc/iso-639-1 packages are available.
	 *
	 * @return bool True if at least one detection package is present.
	 */
	public function is_available() {
		$franc   = WP_MCP_AI_PRO_PATH . 'node_modules/franc';
		$iso6391 = WP_MCP_AI_PRO_PATH . 'node_modules/iso-639-1';
		return is_dir( $franc ) || is_dir( $iso6391 );
	}

	/**
	 * Detect the language of a text string.
	 *
	 * Tries the franc Node.js service first via the wp_mcp_ai_lang_detect filter;
	 * falls back to a PHP heuristic based on character-set detection.
	 *
	 * @param string $text Text to analyse.
	 * @return array {
	 *     @type string $code         ISO 639-1 language code (or 'und' if undetermined).
	 *     @type string $name         Human-readable language name.
	 *     @type float  $confidence   Confidence score 0–1.
	 *     @type array  $alternatives Array of alternative detections.
	 *     @type string $source       'franc' | 'php-heuristic'.
	 * }
	 */
	public function detect_language( $text ) {
		if ( empty( $text ) ) {
			return array(
				'code'         => 'und',
				'name'         => 'Undetermined',
				'confidence'   => 0.0,
				'alternatives' => array(),
				'source'       => 'none',
			);
		}

		// Attempt Node.js franc detection via filter.
		$result = apply_filters(
			'wp_mcp_ai_lang_detect',
			false,
			array( 'text' => $text )
		);

		if ( is_array( $result ) && ! empty( $result['code'] ) ) {
			$result['source'] = 'franc';
			return $result;
		}

		// PHP heuristic fallback.
		return $this->detect_language_php( $text );
	}

	/**
	 * Get the human-readable name for an ISO 639-1 language code.
	 *
	 * @param string $code ISO 639-1 two-letter code.
	 * @return string Language name, or the code itself if unknown.
	 */
	public function get_language_name( $code ) {
		$code = strtolower( sanitize_key( $code ) );

		// Attempt Node.js iso-639-1 lookup via filter.
		$result = apply_filters(
			'wp_mcp_ai_lang_code_info',
			false,
			array( 'code' => $code )
		);

		if ( is_array( $result ) && ! empty( $result['name'] ) ) {
			return $result['name'];
		}

		return isset( self::$iso_names[ $code ] ) ? self::$iso_names[ $code ] : $code;
	}

	/**
	 * Validate an ISO 639-1 language code.
	 *
	 * @param string $code Language code to validate.
	 * @return bool True if the code is a known ISO 639-1 code.
	 */
	public function validate_language_code( $code ) {
		$code = strtolower( sanitize_key( $code ) );
		return isset( self::$iso_names[ $code ] );
	}

	/**
	 * Format a phone number using libphonenumber-js (via Node filter).
	 *
	 * Falls back to a simple digit-count sanity check.
	 *
	 * @param string $phone       Phone number string.
	 * @param string $country_code ISO 3166-1 alpha-2 country code (e.g. 'US').
	 * @return array {
	 *     @type string $formatted     Formatted international number.
	 *     @type string $national      National format.
	 *     @type string $international International format.
	 *     @type bool   $valid         Whether the number is valid.
	 *     @type string $country       Resolved country code.
	 * }
	 */
	public function format_phone( $phone, $country_code = 'US' ) {
		$phone        = sanitize_text_field( $phone );
		$country_code = strtoupper( sanitize_key( $country_code ) );

		// Attempt libphonenumber-js via filter.
		$result = apply_filters(
			'wp_mcp_ai_phone_format',
			false,
			array(
				'phone'        => $phone,
				'country_code' => $country_code,
			)
		);

		if ( is_array( $result ) ) {
			return $result;
		}

		// PHP fallback: strip non-digits and basic length check.
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		$valid  = strlen( $digits ) >= 10 && strlen( $digits ) <= 15;

		return array(
			'formatted'     => $phone,
			'national'      => $digits,
			'international' => $phone,
			'valid'         => $valid,
			'country'       => $country_code,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Simple PHP heuristic language detection via character-set patterns.
	 *
	 * Checks for CJK, Arabic, Cyrillic, Hebrew, etc., then falls back to
	 * returning 'en' for Latin-script text (most common default).
	 *
	 * @param string $text Input text.
	 * @return array Detection result with source = 'php-heuristic'.
	 */
	private function detect_language_php( $text ) {
		// CJK Unified Ideographs (Chinese, Japanese Kanji).
		if ( preg_match( '/[\x{4e00}-\x{9fff}]/u', $text ) ) {
			return $this->make_result( 'zh', 0.7, 'php-heuristic' );
		}
		// Japanese Hiragana/Katakana.
		if ( preg_match( '/[\x{3040}-\x{30ff}]/u', $text ) ) {
			return $this->make_result( 'ja', 0.8, 'php-heuristic' );
		}
		// Korean Hangul.
		if ( preg_match( '/[\x{ac00}-\x{d7af}]/u', $text ) ) {
			return $this->make_result( 'ko', 0.8, 'php-heuristic' );
		}
		// Arabic.
		if ( preg_match( '/[\x{0600}-\x{06ff}]/u', $text ) ) {
			return $this->make_result( 'ar', 0.8, 'php-heuristic' );
		}
		// Hebrew.
		if ( preg_match( '/[\x{0590}-\x{05ff}]/u', $text ) ) {
			return $this->make_result( 'he', 0.8, 'php-heuristic' );
		}
		// Cyrillic (Russian, Bulgarian, etc.).
		if ( preg_match( '/[\x{0400}-\x{04ff}]/u', $text ) ) {
			return $this->make_result( 'ru', 0.6, 'php-heuristic' );
		}
		// Greek.
		if ( preg_match( '/[\x{0370}-\x{03ff}]/u', $text ) ) {
			return $this->make_result( 'el', 0.8, 'php-heuristic' );
		}
		// Thai.
		if ( preg_match( '/[\x{0e00}-\x{0e7f}]/u', $text ) ) {
			return $this->make_result( 'th', 0.8, 'php-heuristic' );
		}
		// Default: English (Latin script).
		return $this->make_result( 'en', 0.4, 'php-heuristic' );
	}

	/**
	 * Build a standardised detection result array.
	 *
	 * @param string $code       ISO 639-1 code.
	 * @param float  $confidence Confidence 0–1.
	 * @param string $source     Detection source label.
	 * @return array
	 */
	private function make_result( $code, $confidence, $source ) {
		return array(
			'code'         => $code,
			'name'         => $this->get_language_name( $code ),
			'confidence'   => $confidence,
			'alternatives' => array(),
			'source'       => $source,
		);
	}
}
