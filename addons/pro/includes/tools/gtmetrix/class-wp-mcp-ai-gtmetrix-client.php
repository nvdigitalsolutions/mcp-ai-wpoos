<?php
/**
 * GTmetrix Toolkit — API Client.
 *
 * HTTP adapter for the GTmetrix REST API v2.0 (https://gtmetrix.com/api/2.0/).
 * HTTPS Basic auth (API key as username, blank password), JSON:API v1.1
 * request/response handling, error-code taxonomy, rate-limit awareness with
 * exponential backoff on E42901, and fixture-mode short-circuiting.
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

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-settings.php';

/**
 * GTmetrix REST API v2.0 client.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Client {

	/**
	 * Resource names that are never fetched: they contain sensitive data
	 * (HTTP auth credentials, cookies) or raw HAR dumps.
	 *
	 * @var string[]
	 */
	const BLOCKED_RESOURCES = array( 'analysis-options', 'net.har' );

	/**
	 * GTmetrix error code → WP_Error code + default message.
	 *
	 * @var array<string, array{code: string, message: string}>
	 */
	const ERROR_MAP = array(
		'E40100' => array(
			'code'    => 'auth_failed',
			'message' => 'Invalid GTmetrix API key.',
		),
		'E40300' => array(
			'code'    => 'email_unvalidated',
			'message' => 'GTmetrix account e-mail address is not validated.',
		),
		'E40301' => array(
			'code'    => 'role_denied',
			'message' => 'Your GTmetrix role does not permit this operation.',
		),
		'E40201' => array(
			'code'    => 'insufficient_credits',
			'message' => 'Insufficient GTmetrix API credits for this request.',
		),
		'E41500' => array(
			'code'    => 'unsupported_media_type',
			'message' => 'Unsupported media type (JSON:API content type required).',
		),
		'E42900' => array(
			'code'    => 'too_many_tests',
			'message' => 'Too many tests pending — retry after an existing test completes.',
		),
		'E42901' => array(
			'code'    => 'rate_limited',
			'message' => 'GTmetrix API rate limit exceeded.',
		),
		'E42902' => array(
			'code'    => 'report_list_busy',
			'message' => 'A report-list query is already running — retry shortly.',
		),
		'E42903' => array(
			'code'    => 'pdf_queue_full',
			'message' => 'PDF generation is busy — retry in a few moments.',
		),
		'E40400' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix test not found.',
		),
		'E40401' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix report not found.',
		),
		'E40402' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix page not found.',
		),
		'E40403' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix browser not found.',
		),
		'E40404' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix location not found.',
		),
		'E40417' => array(
			'code'    => 'not_found',
			'message' => 'GTmetrix tag not found.',
		),
		'E50000' => array(
			'code'    => 'server_error',
			'message' => 'GTmetrix internal server error.',
		),
		'E50001' => array(
			'code'    => 'server_error',
			'message' => 'Error generating the GTmetrix report PDF.',
		),
		'E50002' => array(
			'code'    => 'server_error',
			'message' => 'Error generating the GTmetrix Lighthouse JSON.',
		),
		'E50200' => array(
			'code'    => 'unavailable',
			'message' => 'GTmetrix application server temporarily unavailable.',
		),
		'E50300' => array(
			'code'    => 'unavailable',
			'message' => 'GTmetrix application server temporarily unavailable.',
		),
	);

	/**
	 * Last response headers (rate-limit state).
	 *
	 * @var array<string, string>
	 */
	private $last_headers = array();

	/**
	 * Perform an API request.
	 *
	 * @param string $method   HTTP method (GET/POST/DELETE).
	 * @param string $path     API path relative to the base URL (no leading slash).
	 * @param array  $body     JSON body (only for POST).
	 * @param int    $attempts Remaining retry budget (internal).
	 * @return array|WP_Error  Normalised JSON:API `data`, or WP_Error.
	 */
	public function request( $method, $path, $body = null, $attempts = 3 ) {
		$api_key = WP_MCP_AI_GTmetrix_Settings::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_no_key', __( 'No GTmetrix API key configured. Add it in the NV oOS API key settings.', 'mcp-ai-wpoos-pro' ) );
		}

		$url  = WP_MCP_AI_GTmetrix_Settings::API_BASE_URL . ltrim( $path, '/' );
		$args = array(
			'method'     => $method,
			'timeout'    => 30,
			'user-agent' => 'NV-oOS/1.0 (WordPress; GTmetrix toolkit)',
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth per the GTmetrix API contract (API key as username, blank password).
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/vnd.api+json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code               = wp_remote_retrieve_response_code( $response );
		$this->last_headers = wp_remote_retrieve_headers( $response );
		if ( ! is_array( $this->last_headers ) ) {
			$this->last_headers = array();
		}
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_bad_response', __( 'GTmetrix returned a non-JSON response.', 'mcp-ai-wpoos-pro' ) );
		}

		// JSON:API error envelope.
		if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$first  = $decoded['errors'][0];
			$err    = isset( $first['code'] ) ? strtoupper( (string) $first['code'] ) : '';
			$detail = isset( $first['detail'] ) ? (string) $first['detail'] : '';

			// E42901 is the only error worth retrying with backoff.
			if ( 'E42901' === $err && $attempts > 0 ) {
				$wait = 1 << ( 3 - $attempts );
				sleep( $wait );

				return $this->request( $method, $path, $body, $attempts - 1 );
			}

			$map = isset( self::ERROR_MAP[ $err ] ) ? self::ERROR_MAP[ $err ] : array(
				'code'    => 'api_error',
				'message' => __( 'GTmetrix API error.', 'mcp-ai-wpoos-pro' ),
			);
			if ( '' !== $detail ) {
				$map['message'] = $detail;
			}

			return new WP_Error( 'wp_mcp_ai_gtmetrix_' . $map['code'], $map['message'] );
		}

		if ( isset( $decoded['data'] ) ) {
			return $decoded['data'];
		}

		return $decoded;
	}

	/**
	 * Normalise a JSON:API datum: a single resource (object) or a list of
	 * resources (array) — never assume a shape.
	 *
	 * @param array $data Raw `data` block.
	 * @return array|WP_Error Single resource as array, or WP_Error.
	 */
	public function normalise_datum( $data ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_bad_response', __( 'Unexpected GTmetrix response shape.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( isset( $data['attributes'] ) ) {
			return $data;
		}

		// A paginated list: return the first item or an empty datum.
		if ( ! empty( $data ) && isset( $data[0]['attributes'] ) ) {
			return $data[0];
		}

		return new WP_Error( 'wp_mcp_ai_gtmetrix_bad_response', __( 'GTmetrix response missing resource attributes.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Last response headers (rate-limit state).
	 *
	 * @return array<string, string>
	 */
	public function get_last_headers() {
		return $this->last_headers;
	}

	/**
	 * Rate-limit remaining from the last response (or -1 when unknown).
	 *
	 * @return int
	 */
	public function rate_limit_remaining() {
		if ( isset( $this->last_headers['x-ratelimit-remaining'] ) ) {
			return absint( $this->last_headers['x-ratelimit-remaining'] );
		}

		return -1;
	}

	/**
	 * GET /status — account credits, plan and feature flags.
	 *
	 * @return array|WP_Error
	 */
	public function get_status() {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'status-basic' );
		}

		return $this->normalise_datum( $this->request( 'GET', 'status' ) );
	}

	/**
	 * GET /locations — test locations.
	 *
	 * @return array|WP_Error
	 */
	public function get_locations() {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'locations' );
		}

		$data = $this->request( 'GET', 'locations' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /browsers — available test browsers.
	 *
	 * @return array|WP_Error
	 */
	public function get_browsers() {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'browsers' );
		}

		$data = $this->request( 'GET', 'browsers' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /simulated-devices — simulated devices (PRO plans).
	 *
	 * @return array|WP_Error
	 */
	public function get_devices() {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'devices' );
		}

		$data = $this->request( 'GET', 'simulated-devices' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * POST /tests — start a test.
	 *
	 * @param array $params Test parameters (url, location, browser, report, ...).
	 * @return array|WP_Error Test resource with meta (credits_used, credits_left).
	 */
	public function start_test( $params ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::start_test( $params );
		}

		$body   = array(
			'data' => array(
				'type'       => 'test',
				'attributes' => $params,
			),
		);
		$result = $this->request( 'POST', 'tests', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = $this->normalise_datum( $result );
		if ( ! is_wp_error( $out ) ) {
			$out['_meta'] = array(
				'credits_left' => isset( $result['meta']['credits_left'] ) ? $result['meta']['credits_left'] : null,
				'credits_used' => isset( $result['meta']['credits_used'] ) ? $result['meta']['credits_used'] : null,
			);
		}

		return $out;
	}

	/**
	 * GET /tests/{id} — poll test state.
	 *
	 * @param string $test_id Test ID.
	 * @return array|WP_Error
	 */
	public function get_test( $test_id ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::get_test( $test_id );
		}

		return $this->normalise_datum( $this->request( 'GET', 'tests/' . rawurlencode( $test_id ) ) );
	}

	/**
	 * GET /tests — list tests (24 h window).
	 *
	 * @param array $query Query parameters (sort, filter, page).
	 * @return array|WP_Error
	 */
	public function list_tests( $query = array() ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'tests' );
		}

		$path = 'tests';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		$data = $this->request( 'GET', $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /reports/{id} — report metrics and resource links.
	 *
	 * @param string $report_id Report ID.
	 * @return array|WP_Error
	 */
	public function get_report( $report_id ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( $report_id );
		}

		return $this->normalise_datum( $this->request( 'GET', 'reports/' . rawurlencode( $report_id ) ) );
	}

	/**
	 * GET /reports/{id}/resources/{name} — raw resource body.
	 *
	 * Blocked resources (analysis-options, net.har) return a WP_Error.
	 *
	 * @param string $report_id Report ID.
	 * @param string $name      Resource name (lighthouse.json, resource-summary, ...).
	 * @return array|WP_Error
	 */
	public function get_resource( $report_id, $name ) {
		if ( in_array( $name, self::BLOCKED_RESOURCES, true ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_blocked_resource', __( 'This resource contains sensitive data and is not fetched by the toolkit.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( $report_id, $name );
		}

		$raw = $this->request( 'GET', 'reports/' . rawurlencode( $report_id ) . '/resources/' . rawurlencode( $name ) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( is_array( $raw ) && isset( $raw['attributes'] ) ) {
			return $raw['attributes'];
		}

		return $raw;
	}

	/**
	 * DELETE /reports/{id} — delete a report (irreversible).
	 *
	 * @param string $report_id Report ID.
	 * @return array|WP_Error
	 */
	public function delete_report( $report_id ) {
		$data = $this->request( 'DELETE', 'reports/' . rawurlencode( $report_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * POST /reports/{id}/retest — retest with the same options.
	 *
	 * @param string $report_id Report ID.
	 * @return array|WP_Error
	 */
	public function retest_report( $report_id ) {
		$result = $this->request( 'POST', 'reports/' . rawurlencode( $report_id ) . '/retest' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}

	/**
	 * GET /pages — list pages (account registry).
	 *
	 * @param array $query Query parameters (filter, sort, page).
	 * @return array|WP_Error
	 */
	public function list_pages( $query = array() ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'pages' );
		}

		$path = 'pages';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		$data = $this->request( 'GET', $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /pages/{id} — page detail.
	 *
	 * @param string $page_id Page ID.
	 * @return array|WP_Error
	 */
	public function get_page( $page_id ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::get_page( $page_id );
		}

		return $this->normalise_datum( $this->request( 'GET', 'pages/' . rawurlencode( $page_id ) ) );
	}

	/**
	 * POST /pages/{id}/retest — retest a page with its saved options.
	 *
	 * @param string $page_id Page ID.
	 * @return array|WP_Error
	 */
	public function retest_page( $page_id ) {
		$result = $this->request( 'POST', 'pages/' . rawurlencode( $page_id ) . '/retest' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}

	/**
	 * POST /pages/{id}/performance_monitor — update monitoring frequency.
	 *
	 * @param string $page_id   Page ID.
	 * @param string $frequency no|none|hourly|daily|weekly|monthly.
	 * @return array|WP_Error
	 */
	public function set_monitoring( $page_id, $frequency ) {
		$body = array(
			'data' => array(
				'type'       => 'performance_monitor',
				'attributes' => array( 'frequency' => $frequency ),
			),
		);

		$result = $this->request( 'POST', 'pages/' . rawurlencode( $page_id ) . '/performance_monitor', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}

	/**
	 * GET /pages/{id}/performance_alert_conditions — alert configuration.
	 *
	 * @param string $page_id Page ID.
	 * @return array|WP_Error
	 */
	public function get_alert_conditions( $page_id ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'alert-conditions' );
		}

		$data = $this->request( 'GET', 'pages/' . rawurlencode( $page_id ) . '/performance_alert_conditions' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->normalise_datum( $data );
	}

	/**
	 * POST /pages/{id}/performance_alert_conditions — replace alert config.
	 *
	 * @param string   $page_id      Page ID.
	 * @param array    $conditions   Condition set (metric, operator, value).
	 * @param bool     $create_video Keep a video on trigger (PRO).
	 * @param string[] $send_to      Recipient e-mails (Team organisations).
	 * @return array|WP_Error
	 */
	public function set_alert_conditions( $page_id, $conditions, $create_video = false, $send_to = array() ) {
		$attributes = array(
			'create_video' => (bool) $create_video,
			'conditions'   => $conditions,
		);
		if ( ! empty( $send_to ) ) {
			$emails = array();
			foreach ( $send_to as $email ) {
				if ( is_email( $email ) ) {
					$emails[] = array( 'email' => $email );
				}
			}
			if ( ! empty( $emails ) ) {
				$attributes['send_to'] = $emails;
			}
		}

		$body = array(
			'data' => array(
				'type'       => 'performance_alert_conditions',
				'attributes' => $attributes,
			),
		);

		$result = $this->request( 'POST', 'pages/' . rawurlencode( $page_id ) . '/performance_alert_conditions', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}

	/**
	 * DELETE /pages/{id}/performance_alert_conditions — remove all alerts.
	 *
	 * @param string $page_id Page ID.
	 * @return array|WP_Error
	 */
	public function delete_alert_conditions( $page_id ) {
		$data = $this->request( 'DELETE', 'pages/' . rawurlencode( $page_id ) . '/performance_alert_conditions' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /pages/{id}/performance_alert_events — alert history.
	 *
	 * @param string $page_id Page ID.
	 * @param array  $query   Pagination.
	 * @return array|WP_Error
	 */
	public function get_alert_events( $page_id, $query = array() ) {
		if ( WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return WP_MCP_AI_GTmetrix_Fixture_Reader::read( 'alert-events' );
		}

		$path = 'pages/' . rawurlencode( $page_id ) . '/performance_alert_events';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		$data = $this->request( 'GET', $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * GET /page_tags — list tags.
	 *
	 * @return array|WP_Error
	 */
	public function list_tags() {
		$data = $this->request( 'GET', 'page_tags' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * POST /page_tags — create (or update when data.id supplied) a tag.
	 *
	 * @param array $attributes name, description, color; optional id.
	 * @return array|WP_Error
	 */
	public function create_tag( $attributes ) {
		$body = array(
			'data' => array(
				'type'       => 'tag',
				'attributes' => $attributes,
			),
		);
		if ( ! empty( $attributes['id'] ) ) {
			$body['data']['id'] = $attributes['id'];
			unset( $body['data']['attributes']['id'] );
		}

		$result = $this->request( 'POST', 'page_tags', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}

	/**
	 * POST /pages/{id}/attach_tags or detach_tags.
	 *
	 * @param string   $page_id Page ID.
	 * @param string[] $tag_ids Tag IDs.
	 * @param bool     $detach  Whether to detach instead of attach.
	 * @return array|WP_Error
	 */
	public function update_page_tags( $page_id, $tag_ids, $detach = false ) {
		$action = $detach ? 'detach_tags' : 'attach_tags';
		$body   = array( 'page_tags' => array_values( $tag_ids ) );

		$result = $this->request( 'POST', 'pages/' . rawurlencode( $page_id ) . '/' . $action, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalise_datum( $result );
	}
}
