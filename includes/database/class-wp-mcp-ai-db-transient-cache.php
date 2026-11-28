<?php
/**
 * Transient-backed catalogue cache for the WordPress host.
 *
 * Proposal 167. Key names are namespaced and hashed so a connection id or a
 * schema name cannot collide with, or leak into, another plugin's transients.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient cache.
 *
 * A miss is distinguished from a cached empty array by wrapping the value in a
 * one-key envelope: `get_transient()` returns `false` for a miss and the
 * envelope for a hit, so an empty catalogue stays cached instead of being
 * re-introspected on every call.
 */
final class WP_MCP_AI_Db_Transient_Cache implements WP_MCP_AI_Db_Cache_Interface {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'wp_mcp_ai_db_cat_';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 */
	public function get( string $key ): ?array {
		$stored = get_transient( $this->key( $key ) );

		if ( ! is_array( $stored ) || ! array_key_exists( 'v', $stored ) || ! is_array( $stored['v'] ) ) {
			return null;
		}

		return $stored['v'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $key   Cache key.
	 * @param array<string, mixed> $value Value.
	 * @param int                  $ttl   Time to live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void {
		set_transient( $this->key( $key ), array( 'v' => $value ), max( 1, $ttl ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		delete_transient( $this->key( $key ) );
	}

	/**
	 * Build a bounded transient key.
	 *
	 * Transient names are capped at 172 characters; the digest keeps the key
	 * fixed-length whatever the caller passes.
	 *
	 * @param string $key Logical key.
	 */
	private function key( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}
}
