<?php
/**
 * Concurrency Guard — Prevents resource exhaustion from simultaneous
 * resource-intensive AI operations.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Concurrency_Guard' ) ) {
	/**
	 * Limits concurrent resource-intensive operations.
	 *
	 * Usage:
	 *   $slot = WP_MCP_AI_Concurrency_Guard::acquire( 'image_generation' );
	 *   if ( is_wp_error( $slot ) ) { return $slot; }
	 *   // ... do work ...
	 *   WP_MCP_AI_Concurrency_Guard::release( 'image_generation' );
	 */
	class WP_MCP_AI_Concurrency_Guard {

		/**
		 * Transient prefix for slot counters.
		 */
		const TRANSIENT_PREFIX = 'wp_mcp_ai_concurrency_';

		/**
		 * Default TTL for concurrency locks (10 minutes).
		 */
		const LOCK_TTL = 600;

		/**
		 * Operation type → max concurrent slots.
		 *
		 * @var array<string, int>
		 */
		const LIMITS = array(
			'image_generation'    => 3,
			'video_generation'    => 1,
			'music_generation'    => 2,
			'deep_research'       => 2,
			'model_download'      => 1,
			'document_ocr'        => 2,
			'pdf_generation'      => 2,
			'embeddings_batch'    => 3,
			'video_frame_extract' => 1,
			'default'             => 5,
		);

		/**
		 * Acquire a concurrency slot for an operation type.
		 *
		 * @param string $operation_type Type from LIMITS (e.g. 'image_generation').
		 * @return true|WP_Error True if slot acquired, WP_Error if at capacity.
		 */
		public static function acquire( $operation_type ) {
			$max     = self::get_limit( $operation_type );
			$key     = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );
			$current = absint( get_transient( $key ) );

			if ( $current >= $max ) {
				return new WP_Error(
					'concurrency_limit',
					sprintf(
						/* translators: 1=operation, 2=max count */
						__( 'Maximum %2$d concurrent %1$s operations reached. Please try again later.', 'mcp-ai-wpoos' ),
						esc_html( $operation_type ),
						esc_html( (string) $max )
					)
				);
			}

			set_transient( $key, $current + 1, self::LOCK_TTL );

			return true;
		}

		/**
		 * Release a concurrency slot after an operation completes.
		 *
		 * Always call this, even on failure paths (use try/finally or shutdown handler).
		 *
		 * @param string $operation_type Operation type.
		 * @return void
		 */
		public static function release( $operation_type ) {
			$key     = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );
			$current = absint( get_transient( $key ) );

			if ( $current <= 1 ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $current - 1, self::LOCK_TTL );
			}
		}

		/**
		 * Get the concurrency limit for an operation type.
		 *
		 * @param string $operation_type Operation type.
		 * @return int Maximum concurrent operations allowed.
		 */
		public static function get_limit( $operation_type ) {
			$limits = apply_filters( 'wp_mcp_ai_concurrency_limits', self::LIMITS );

			return isset( $limits[ $operation_type ] )
				? absint( $limits[ $operation_type ] )
				: absint( $limits['default'] ?? 5 );
		}

		/**
		 * Get current usage for all operation types.
		 *
		 * @return array<string, array{current: int, max: int}>
		 */
		public static function get_usage() {
			$usage = array();

			foreach ( self::LIMITS as $type => $max ) {
				$key     = self::TRANSIENT_PREFIX . $type;
				$current = absint( get_transient( $key ) );

				$usage[ $type ] = array(
					'current' => $current,
					'max'     => self::get_limit( $type ),
				);
			}

			return $usage;
		}
	}
}
