<?php
/**
 * Helper for streaming oversized tool results to artifacts.
 *
 * When a tool would otherwise return a multi-megabyte payload to the LLM,
 * use this helper to persist the full payload as an artifact (transient or
 * attachment) and return a small `{ summary, count, artifact_id, artifact_url }`
 * envelope instead. Keeps token usage and SSE message size bounded.
 *
 * Used in conjunction with `WP_MCP_AI_Batch_Iterator` (Phase 1) to land the
 * "massive-data hardening" plan's Phase 2 contract.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact streaming helper for bulk tool results.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Artifact_Helper {

	/**
	 * Default inline-rows ceiling.
	 */
	const DEFAULT_MAX_INLINE_ROWS = 100;

	/**
	 * Default per-tool max_items ceiling.
	 */
	const DEFAULT_TOOL_MAX_ITEMS = 500;

	/**
	 * Transient prefix for stored artifacts.
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_artifact_';

	/**
	 * Default TTL for transient-backed artifacts (24h).
	 */
	const DEFAULT_TTL_SECONDS = DAY_IN_SECONDS;

	/**
	 * Resolve the max_items ceiling for a given tool slug.
	 *
	 * Tools should call this to clamp user-supplied `max_items` arguments.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug Tool slug (used as filter context).
	 * @param int    $requested User-supplied value (or default if none provided).
	 * @param int    $hard_default Tool-specific default to fall back to.
	 * @return int
	 */
	public static function resolve_max_items( $tool_slug, $requested = 0, $hard_default = self::DEFAULT_TOOL_MAX_ITEMS ) {
		$value = (int) $requested > 0 ? (int) $requested : (int) $hard_default;

		/**
		 * Filters the per-tool max_items ceiling.
		 *
		 * Site owners can clamp specific tools without code changes:
		 *
		 *     add_filter( 'wp_mcp_ai_tool_max_items', function ( $max, $slug ) {
		 *         return 'media_library_optimizer' === $slug ? 200 : $max;
		 *     }, 10, 2 );
		 *
		 * @since 1.2.0
		 *
		 * @param int    $value     Resolved value.
		 * @param string $tool_slug Tool slug context.
		 */
		$value = (int) apply_filters( 'wp_mcp_ai_tool_max_items', $value, $tool_slug );

		return max( 1, $value );
	}

	/**
	 * Check if a payload row count exceeds the inline-return threshold.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $row_count Number of rows in the would-be payload.
	 * @param string $tool_slug Tool slug context (for filter).
	 * @return bool
	 */
	public static function should_stream_to_artifact( $row_count, $tool_slug = '' ) {
		/**
		 * Filters the inline-rows ceiling.
		 *
		 * Payloads with more rows than this are persisted as artifacts and
		 * the tool returns a `{ summary, count, artifact_id, artifact_url }`
		 * envelope instead of the full result.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $max       Maximum inline rows. Default 100.
		 * @param string $tool_slug Tool slug context.
		 */
		$max = (int) apply_filters( 'wp_mcp_ai_max_inline_rows', self::DEFAULT_MAX_INLINE_ROWS, $tool_slug );
		$max = max( 1, $max );

		return (int) $row_count > $max;
	}

	/**
	 * Persist a payload as a transient-backed artifact and return a small envelope.
	 *
	 * The envelope shape is stable and intended for direct return from
	 * `execute()`:
	 *
	 *     {
	 *         summary:      string,    // human-readable one-line summary
	 *         count:        int,       // total rows in the full payload
	 *         truncated:    true,      // marker so callers know to fetch
	 *         artifact_id:  string,    // pass to retrieve()
	 *         artifact_url: string,    // signed REST URL (when available)
	 *         original_bytes: int,
	 *         expires_at:   int,       // unix timestamp
	 *     }
	 *
	 * The transient stores `{ tool_slug, payload, created_at, byte_size }`.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed  $payload   The full payload (will be JSON-encoded for size measurement).
	 * @param string $tool_slug Tool slug for diagnostics.
	 * @param array  $args {
	 *     Optional. Envelope overrides.
	 *
	 *     @type string $summary      Custom summary line. Default auto-generated.
	 *     @type int    $count        Override the row count. Defaults to count( $payload ) when array.
	 *     @type int    $ttl_seconds  Transient TTL. Default DEFAULT_TTL_SECONDS.
	 *     @type array  $extra        Extra envelope fields.
	 * }
	 * @return array Envelope (always an array).
	 */
	public static function stream_to_artifact( $payload, $tool_slug, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'summary'     => '',
				'count'       => null,
				'ttl_seconds' => self::DEFAULT_TTL_SECONDS,
				'extra'       => array(),
			)
		);

		$encoded    = wp_json_encode( $payload );
		$byte_size  = is_string( $encoded ) ? strlen( $encoded ) : 0;
		$row_count  = null !== $args['count']
			? (int) $args['count']
			: ( is_array( $payload ) || $payload instanceof Countable ? count( $payload ) : 1 );

		$artifact_id = self::generate_artifact_id( $tool_slug );
		$ttl         = max( 60, (int) $args['ttl_seconds'] );

		$record = array(
			'tool_slug' => sanitize_key( $tool_slug ),
			'payload'   => $payload,
			'count'     => $row_count,
			'byte_size' => $byte_size,
			'created'   => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $artifact_id, $record, $ttl );

		$summary = '' !== $args['summary']
			? (string) $args['summary']
			: sprintf(
				/* translators: 1: row count, 2: tool slug */
				_x( '%1$d rows from %2$s (full payload streamed to artifact).', 'tool artifact summary', 'mcp-ai-wpoos' ),
				$row_count,
				$tool_slug
			);

		$envelope = array(
			'summary'        => $summary,
			'count'          => $row_count,
			'truncated'      => true,
			'artifact_id'    => $artifact_id,
			'artifact_url'   => self::build_artifact_url( $artifact_id ),
			'original_bytes' => $byte_size,
			'expires_at'     => time() + $ttl,
		);

		if ( ! empty( $args['extra'] ) && is_array( $args['extra'] ) ) {
			$envelope = array_merge( $envelope, $args['extra'] );
		}

		/**
		 * Fires after a tool result has been persisted as an artifact.
		 *
		 * Site owners can hook in to mirror the artifact to S3 / CCT / etc.
		 *
		 * @since 1.2.0
		 *
		 * @param string $artifact_id Generated artifact id.
		 * @param array  $record      Stored record.
		 * @param array  $envelope    Returned envelope.
		 */
		do_action( 'wp_mcp_ai_tool_artifact_stored', $artifact_id, $record, $envelope );

		return $envelope;
	}

	/**
	 * Wrap an oversized tool-result string with an artifact-spill envelope.
	 *
	 * Phase 3 of the massive-data hardening plan. When the agentic-loop output
	 * guard detects a tool message that would exceed the per-message or
	 * cumulative byte budget, callers can pass the already-sanitised string
	 * payload here and receive a small JSON-encoded envelope referencing an
	 * artifact instead. The envelope is the tool message's `content` for the
	 * remainder of the request and is safe to feed back into the LLM.
	 *
	 * Fires `wp_mcp_ai_tool_output_truncated` with
	 * `( $tool_name, $original_bytes, $artifact_id, $context )` so observers
	 * (logger, OTel exporter, SSE bridge) can react.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sanitized_content Already-sanitised tool result string.
	 * @param string $tool_name         Tool slug / name (for diagnostics).
	 * @param array  $context           Optional. `assistant_id`, `iteration`,
	 *                                  `tool_call_id`, `request_id`.
	 * @return string JSON-encoded envelope ready for the tool message body.
	 */
	public static function wrap_oversized_tool_result( $sanitized_content, $tool_name = '', $context = array() ) {
		$sanitized_content = is_string( $sanitized_content ) ? $sanitized_content : (string) wp_json_encode( $sanitized_content );
		$original_bytes    = strlen( $sanitized_content );

		$artifact_id = self::generate_artifact_id( $tool_name );
		$ttl         = self::DEFAULT_TTL_SECONDS;

		$record = array(
			'tool_slug' => sanitize_key( $tool_name ),
			'payload'   => $sanitized_content,
			'count'     => 1,
			'byte_size' => $original_bytes,
			'created'   => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $artifact_id, $record, $ttl );

		$preview_limit = 256;
		$preview       = $original_bytes > $preview_limit
			? substr( $sanitized_content, 0, $preview_limit ) . '…'
			: $sanitized_content;

		$envelope = array(
			'truncated'      => true,
			'reason'         => 'agentic_output_budget_exceeded',
			'tool_name'      => (string) $tool_name,
			'preview'        => $preview,
			'artifact_id'    => $artifact_id,
			'artifact_url'   => self::build_artifact_url( $artifact_id ),
			'original_bytes' => $original_bytes,
			'expires_at'     => time() + $ttl,
		);

		/**
		 * Fires when a tool output is artifact-spilled by the agentic-loop guard.
		 *
		 * @since 1.2.0
		 *
		 * @param string $tool_name      Tool slug / name.
		 * @param int    $original_bytes Original byte size before spill.
		 * @param string $artifact_id    Generated artifact id.
		 * @param array  $context        Caller-supplied context.
		 */
		do_action( 'wp_mcp_ai_tool_output_truncated', $tool_name, $original_bytes, $artifact_id, $context );

		do_action( 'wp_mcp_ai_tool_artifact_stored', $artifact_id, $record, $envelope );

		$encoded = wp_json_encode( $envelope );

		return is_string( $encoded ) ? $encoded : '{"truncated":true}';
	}

	/**
	 * Retrieve a previously streamed artifact.
	 *
	 * @since 1.2.0
	 *
	 * @param string $artifact_id Artifact id.
	 * @return array|null Stored record or null if expired / missing.
	 */
	public static function retrieve( $artifact_id ) {
		$artifact_id = self::sanitize_artifact_id( $artifact_id );
		if ( '' === $artifact_id ) {
			return null;
		}

		$record = get_transient( self::TRANSIENT_PREFIX . $artifact_id );
		if ( false === $record || ! is_array( $record ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Delete an artifact (e.g. after the LLM has consumed it).
	 *
	 * @since 1.2.0
	 *
	 * @param string $artifact_id Artifact id.
	 * @return bool
	 */
	public static function delete( $artifact_id ) {
		$artifact_id = self::sanitize_artifact_id( $artifact_id );
		if ( '' === $artifact_id ) {
			return false;
		}
		return (bool) delete_transient( self::TRANSIENT_PREFIX . $artifact_id );
	}

	/**
	 * Generate a unique artifact id (with tool slug prefix for traceability).
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @return string
	 */
	protected static function generate_artifact_id( $tool_slug ) {
		$slug = sanitize_key( $tool_slug );
		if ( '' === $slug ) {
			$slug = 'tool';
		}
		$rand = function_exists( 'wp_generate_uuid4' )
			? str_replace( '-', '', wp_generate_uuid4() )
			: md5( uniqid( (string) wp_rand(), true ) );
		return substr( $slug . '_' . $rand, 0, 64 );
	}

	/**
	 * Validate / sanitize an artifact id supplied by external code.
	 *
	 * @since 1.2.0
	 *
	 * @param string $artifact_id Raw value.
	 * @return string Sanitized id, or empty string if invalid.
	 */
	protected static function sanitize_artifact_id( $artifact_id ) {
		$artifact_id = is_string( $artifact_id ) ? trim( $artifact_id ) : '';
		if ( '' === $artifact_id ) {
			return '';
		}
		// Allow alphanumerics + underscore, max 64 chars.
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $artifact_id ) ) {
			return '';
		}
		return $artifact_id;
	}

	/**
	 * Build a URL the LLM (or chat UI) can use to fetch the artifact.
	 *
	 * Returns an empty string if the REST URL helper is unavailable; callers
	 * should treat the URL as optional.
	 *
	 * @since 1.2.0
	 *
	 * @param string $artifact_id Artifact id.
	 * @return string
	 */
	protected static function build_artifact_url( $artifact_id ) {
		if ( ! function_exists( 'rest_url' ) ) {
			return '';
		}
		return rest_url( 'mcp-ai/v1/artifacts/' . rawurlencode( $artifact_id ) );
	}
}
