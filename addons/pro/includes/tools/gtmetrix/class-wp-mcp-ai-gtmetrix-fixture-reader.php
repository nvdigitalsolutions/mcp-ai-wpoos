<?php
/**
 * GTmetrix Toolkit — Fixture Reader.
 *
 * JSON fixture adapter backing the client in fixture mode
 * (WP_MCP_AI_GTMETRIX_USE_FIXTURES): deterministic, network-free reads for
 * tests, demos and offline environments. Fixtures live in tests/fixtures/*.json.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixture-backed GTmetrix data reader.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Fixture_Reader {

	/**
	 * Fixtures directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return __DIR__ . '/tests/fixtures';
	}

	/**
	 * Load a fixture JSON file.
	 *
	 * @param string $name File basename without extension.
	 * @return array|WP_Error
	 */
	public static function load( $name ) {
		// WP 6.9's sanitize_file_name() renames bare extension-less names to
		// "unnamed-file.x" — fixtures use a strict literal whitelist instead.
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_invalid', __( 'Invalid fixture name.', 'mcp-ai-wpoos-pro' ) );
		}

		$file = self::dir() . '/' . $name . '.json';
		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_missing', sprintf( __( 'Fixture "%s" not found.', 'mcp-ai-wpoos-pro' ), $name ) );
		}

		$decoded = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local committed fixture file, not a remote URL.
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_invalid', __( 'Fixture JSON is invalid.', 'mcp-ai-wpoos-pro' ) );
		}

		return $decoded;
	}

	/**
	 * Read a fixture endpoint's `data` block.
	 *
	 * @param string $name Fixture name (status, locations, browsers, devices, pages, tests).
	 * @return array|WP_Error
	 */
	public static function read( $name ) {
		$decoded = self::load( $name );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
	}

	/**
	 * Account status (defaults to the BASIC fixture).
	 *
	 * @return array|WP_Error
	 */
	public static function status() {
		$data = self::read( 'status-basic' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['attributes'] ) ) {
			return $data;
		}

		return new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_invalid', __( 'Status fixture is malformed.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Simulated test lifecycle: queued on first poll, started on second,
	 * completed (with report) from the third poll onwards.
	 *
	 * @param string $test_id Test ID.
	 * @return array|WP_Error
	 */
	public static function get_test( $test_id ) {
		if ( strpos( $test_id, 'T1E' ) === 0 ) {
			$data = self::read( 'test-error' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return $data;
		}

		// Fixture tests flip through queued → started → completed on repeated reads.
		$state = get_transient( 'wp_mcp_ai_gtmetrix_fixture_state_' . $test_id );
		if ( false === $state || 'queued' === $state ) {
			set_transient( 'wp_mcp_ai_gtmetrix_fixture_state_' . $test_id, 'started', MINUTE_IN_SECONDS );
			$data = self::read( 'test-queued' );
		} elseif ( 'started' === $state ) {
			set_transient( 'wp_mcp_ai_gtmetrix_fixture_state_' . $test_id, 'completed', MINUTE_IN_SECONDS );
			$data = self::read( 'test-started' );
		} else {
			$report = self::read( 'report-lighthouse-good' );
			$data   = self::read( 'test-started' );
			if ( ! is_wp_error( $data ) ) {
				$data['attributes']['state']  = 'completed';
				$data['attributes']['report'] = is_wp_error( $report ) ? '' : $report['id'];
			}
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return isset( $data['attributes'] ) ? $data : new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_invalid', __( 'Test fixture is malformed.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Simulated test start: returns a queued test with meta.
	 *
	 * @param array $params Requested test parameters.
	 * @return array|WP_Error
	 */
	public static function start_test( $params ) {
		$data = self::read( 'test-queued' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['attributes']['url']      = isset( $params['url'] ) ? $params['url'] : 'https://example.test/';
		$data['attributes']['location'] = isset( $params['location'] ) ? $params['location'] : '24';
		$data['attributes']['browser']  = isset( $params['browser'] ) ? $params['browser'] : '3';
		$data['_meta']                  = array(
			'credits_used' => 1.0,
			'credits_left' => 120.2,
		);
		set_transient( 'wp_mcp_ai_gtmetrix_fixture_state_' . $data['id'], 'queued', MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Report by ID: RGOOD → good, RPOOR → poor, otherwise metrics-only.
	 *
	 * @param string $report_id Report ID.
	 * @return array|WP_Error
	 */
	public static function get_report( $report_id ) {
		if ( 'RPOOR' === $report_id ) {
			$name = 'report-lighthouse-poor';
		} elseif ( 'RMETRICS' === $report_id ) {
			$name = 'report-metrics-only';
		} else {
			$name = 'report-lighthouse-good';
		}

		$data = self::read( $name );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return isset( $data['attributes'] ) ? $data : new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_invalid', __( 'Report fixture is malformed.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Resource body: lighthouse-audits.json and resource-summary.json.
	 *
	 * @param string $report_id Report ID (unused — fixtures are report-agnostic).
	 * @param string $name      Resource name.
	 * @return array|WP_Error
	 */
	public static function get_resource( $report_id, $name ) {
		if ( 'lighthouse.json' === $name ) {
			return self::load( 'lighthouse-audits' );
		}
		if ( 'resource-summary' === $name ) {
			return self::load( 'resource-summary' );
		}

		return new WP_Error( 'wp_mcp_ai_gtmetrix_fixture_missing', sprintf( __( 'No fixture for resource "%s".', 'mcp-ai-wpoos-pro' ), $name ) );
	}

	/**
	 * Page by ID: P1/P2 from the pages fixture.
	 *
	 * @param string $page_id Page ID.
	 * @return array|WP_Error
	 */
	public static function get_page( $page_id ) {
		$data = self::read( 'pages' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( $data as $page ) {
			if ( isset( $page['id'] ) && $page['id'] === $page_id ) {
				return $page;
			}
		}

		return new WP_Error( 'wp_mcp_ai_gtmetrix_not_found', __( 'GTmetrix page not found.', 'mcp-ai-wpoos-pro' ) );
	}
}
