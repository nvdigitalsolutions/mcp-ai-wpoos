<?php
/**
 * OTLP HTTP trace exporter — Proposal 091, Track E (decision D4).
 *
 * Opt-in, OFF by default: nothing is buffered, sent, or scheduled unless an
 * endpoint is explicitly configured. The endpoint is SSRF-guarded (scheme
 * allowlist + validated URL + no redirects). Spans arrive via the
 * `wp_mcp_ai_genai_span` action and are batched into a single OTLP/HTTP
 * JSON `resourceSpans` envelope, flushed on shutdown (capped batch).
 *
 * Configuration:
 *   - `wp_mcp_ai_otlp_endpoint` option (URL) — empty disables the exporter.
 *   - `wp_mcp_ai_otlp_endpoint` filter — overrides the option at runtime.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OTLP HTTP trace exporter.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_OTLP_Exporter {

	/**
	 * Maximum spans buffered per flush.
	 *
	 * @since 1.2.13
	 * @var int
	 */
	const MAX_BATCH = 50;

	/**
	 * Option key holding the OTLP endpoint URL.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const OPTION_ENDPOINT = 'wp_mcp_ai_otlp_endpoint';

	/**
	 * Buffered span payloads.
	 *
	 * @since 1.2.13
	 * @var array<int, array>
	 */
	private static $buffer = array();

	/**
	 * Whether the exporter is active for this request.
	 *
	 * @since 1.2.13
	 * @var bool|null
	 */
	private static $active = null;

	/**
	 * Initialize the exporter: subscribe to spans and the shutdown flush.
	 *
	 * No-op when no endpoint is configured (decision D4: off by default).
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public static function init() {
		if ( null !== self::$active ) {
			return;
		}

		$endpoint = self::get_endpoint();
		if ( '' === $endpoint || ! self::validate_endpoint( $endpoint ) ) {
			self::$active = false;
			return;
		}

		self::$active = true;
		add_action( 'wp_mcp_ai_genai_span', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 20 );
	}

	/**
	 * Whether the exporter is active this request.
	 *
	 * @since 1.2.13
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( null === self::$active ) {
			self::init();
		}
		return self::$active;
	}

	/**
	 * Reset the exporter state so the next is_active()/init() re-evaluates
	 * the endpoint (runtime re-configuration and test isolation).
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public static function reset() {
		self::$active = null;
		self::$buffer = array();
	}

	/**
	 * Resolve the endpoint: filter first, then the option.
	 *
	 * @since 1.2.13
	 * @return string
	 */
	public static function get_endpoint(): string {
		$endpoint = apply_filters( 'wp_mcp_ai_otlp_endpoint', get_option( self::OPTION_ENDPOINT, '' ) );
		return is_string( $endpoint ) ? trim( $endpoint ) : '';
	}

	/**
	 * Validate the endpoint URL (scheme allowlist, host required, no user
	 * info, host allowlist seam). Self-contained — deliberately does not
	 * rely on wp_http_validate_url()'s environment-dependent SSRF rules.
	 *
	 * @since 1.2.13
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return bool
	 */
	public static function validate_endpoint( string $endpoint ): bool {
		$parsed = wp_parse_url( $endpoint );
		if ( false === $parsed ) {
			return false;
		}

		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( empty( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		/**
		 * Filter the allowed OTLP endpoint hosts (default: the configured
		 * endpoint's own host — the endpoint is the allowlist).
		 *
		 * @since 1.2.13
		 *
		 * @param string[] $allowed  Allowed hosts (lowercase).
		 * @param string   $endpoint The full endpoint URL.
		 */
		$allowed = apply_filters( 'wp_mcp_ai_otlp_allowed_hosts', array( $host ), $endpoint );

		return in_array( $host, array_map( 'strtolower', (array) $allowed ), true );
	}

	/**
	 * Buffer a span payload.
	 *
	 * @since 1.2.13
	 *
	 * @param array $payload Span payload from the emitter.
	 * @return void
	 */
	public static function enqueue( array $payload ) {
		if ( count( self::$buffer ) >= self::MAX_BATCH ) {
			array_shift( self::$buffer );
		}
		self::$buffer[] = $payload;
	}

	/**
	 * Flush the buffer as one OTLP/HTTP JSON batch (no-op when empty).
	 *
	 * @since 1.2.13
	 * @return array|WP_Error|null Response array, WP_Error on failure, or
	 *                              null when there was nothing to send.
	 */
	public static function flush() {
		if ( ! self::is_active() || empty( self::$buffer ) ) {
			return null;
		}

		$payload      = self::build_batch( self::$buffer );
		self::$buffer = array();

		$endpoint = self::get_endpoint();
		$result   = wp_remote_post(
			$endpoint,
			array(
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload ),
				'timeout'     => 5,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $result ) && class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_event' ) ) {
			WP_MCP_AI_Logger::log_event( 'otlp_export_failed', 'OTLP export failed.', array( 'message' => $result->get_error_message() ) );
		}

		return $result;
	}

	/**
	 * Build an OTLP/HTTP JSON resourceSpans batch from span payloads.
	 *
	 * @since 1.2.13
	 *
	 * @param array<int, array> $spans Span payloads.
	 * @return array<string, array>
	 */
	public static function build_batch( array $spans ): array {
		$otlp_spans = array();
		foreach ( $spans as $span ) {
			$otlp_spans[] = self::format_span( $span );
		}

		return array(
			'resourceSpans' => array(
				array(
					'resource'   => array( 'attributes' => array() ),
					'scopeSpans' => array(
						array(
							'scope' => array( 'name' => 'nvoos' ),
							'spans' => $otlp_spans,
						),
					),
				),
			),
		);
	}

	/**
	 * Format one span payload as an OTLP span.
	 *
	 * @since 1.2.13
	 *
	 * @param array $payload Span payload (emitter shape).
	 * @return array<string, mixed>
	 */
	public static function format_span( array $payload ): array {
		$timestamp  = isset( $payload['nvoos.timestamp'] ) ? (int) $payload['nvoos.timestamp'] : time();
		$attributes = array();

		foreach ( $payload as $key => $value ) {
			if ( is_int( $value ) ) {
				$attributes[] = array(
					'key'   => $key,
					'value' => array( 'intValue' => (string) $value ),
				);
			} else {
				$attributes[] = array(
					'key'   => $key,
					'value' => array( 'stringValue' => (string) $value ),
				);
			}
		}

		return array(
			'traceId'           => bin2hex( random_bytes( 16 ) ),
			'spanId'            => bin2hex( random_bytes( 8 ) ),
			'name'              => isset( $payload['gen_ai.operation.name'] ) ? (string) $payload['gen_ai.operation.name'] : 'genai',
			'kind'              => 1,
			'startTimeUnixNano' => (string) ( $timestamp * 1000000000 ),
			'endTimeUnixNano'   => (string) ( $timestamp * 1000000000 ),
			'attributes'        => $attributes,
		);
	}
}
