<?php
/**
 * Normalised lifecycle descriptor for `wp_mcp_ai_after_tool_execution`.
 *
 * Phase P4 of the Unix Theory Compliance Enhancement Proposal adds an optional
 * 5th argument to the `wp_mcp_ai_after_tool_execution` action — a small array
 * that pre-derives the common observability fields (`success`, `error_code`,
 * `data_type`, `duration_ms`) so subscribers don't each roll their own
 * normalisation logic from the raw `$result`.
 *
 * Subscribers registered with `accepted_args = 4` continue to work unchanged.
 * Subscribers that bump to `accepted_args = 5` receive the descriptor.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the normalised lifecycle descriptor passed as the 5th argument to
 * `wp_mcp_ai_after_tool_execution`.
 *
 * The descriptor is intentionally small and stable: it is the contract that
 * observability subscribers (OTel, audit log, token tracking) rely on.
 *
 * @since 1.2.1
 */
class WP_MCP_AI_Tool_Lifecycle_Descriptor {

	/**
	 * Build a lifecycle descriptor for the given tool result.
	 *
	 * @since 1.2.1
	 *
	 * @param mixed      $result        Tool execution result (array, scalar, WP_Error).
	 * @param float|null $start_micros  High-resolution start timestamp from
	 *                                  `microtime( true )` captured immediately
	 *                                  before the tool's `execute()` call. Pass
	 *                                  `null` when duration is unknown
	 *                                  (e.g. completion fires from a different
	 *                                  process than start).
	 * @param string     $tool_slug     Tool slug (for context only — does not
	 *                                  appear in the descriptor itself).
	 * @param array      $context       Execution context (reserved for future use).
	 * @return array{success: bool, error_code: ?string, data_type: ?string, duration_ms: ?float}
	 */
	public static function build( $result, $start_micros = null, $tool_slug = '', array $context = array() ) {
		$is_error = is_wp_error( $result );

		$descriptor = array(
			'success'     => ! $is_error,
			'error_code'  => null,
			'data_type'   => null,
			'duration_ms' => null,
		);

		if ( $is_error ) {
			$descriptor['error_code'] = (string) $result->get_error_code();
		}

		$descriptor['data_type'] = self::derive_data_type( $result );

		if ( null !== $start_micros && is_numeric( $start_micros ) ) {
			$descriptor['duration_ms'] = round( ( microtime( true ) - (float) $start_micros ) * 1000.0, 3 );
			if ( $descriptor['duration_ms'] < 0 ) {
				$descriptor['duration_ms'] = 0.0;
			}
		}

		/**
		 * Filter the lifecycle descriptor before it is dispatched.
		 *
		 * Allows extensions to add normalised fields (e.g. `bytes`, `row_count`)
		 * without modifying every firing site.
		 *
		 * @since 1.2.1
		 *
		 * @param array  $descriptor Normalised descriptor.
		 * @param mixed  $result     Raw tool result.
		 * @param string $tool_slug  Tool slug.
		 * @param array  $context    Execution context.
		 */
		return (array) apply_filters( 'wp_mcp_ai_tool_lifecycle_descriptor', $descriptor, $result, $tool_slug, $context );
	}

	/**
	 * Derive a coarse data-type label for the result.
	 *
	 * Looks for the optional `produces` field declared by tools implementing
	 * {@see WP_MCP_AI_Tool_Data_Contract_Interface} (Phase P3) when callers
	 * embed it in the success payload. Falls back to a generic shape label
	 * so subscribers always get a non-null `data_type` for non-error results.
	 *
	 * @since 1.2.1
	 *
	 * @param mixed $result Tool result.
	 * @return string|null Data-type label, or null for WP_Error.
	 */
	protected static function derive_data_type( $result ) {
		if ( is_wp_error( $result ) ) {
			return null;
		}

		if ( is_array( $result ) ) {
			if ( isset( $result['produces'] ) && is_string( $result['produces'] ) && '' !== $result['produces'] ) {
				return sanitize_key( $result['produces'] );
			}
			return 'array';
		}

		if ( is_string( $result ) ) {
			return 'string';
		}

		if ( is_bool( $result ) ) {
			return 'bool';
		}

		if ( is_int( $result ) ) {
			return 'int';
		}

		if ( is_float( $result ) ) {
			return 'float';
		}

		if ( null === $result ) {
			return 'null';
		}

		if ( is_object( $result ) ) {
			return 'object';
		}

		return 'generic';
	}
}
