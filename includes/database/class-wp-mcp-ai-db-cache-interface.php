<?php
/**
 * Cache contract for introspected catalogue data.
 *
 * Proposal 167, component contract. The catalogue loader is the only consumer:
 * introspection is expensive on a large schema and changes rarely, so results
 * are cached for a short TTL and invalidated on demand.
 *
 * The interface exists so the adapter layer stays host-thin: the WordPress host
 * injects {@see WP_MCP_AI_Db_Transient_Cache}, and the portable tests inject
 * {@see WP_MCP_AI_Db_Array_Cache}. Nothing in `includes/database/` calls
 * `get_transient()` directly.
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
 * Minimal, array-shaped cache.
 *
 * Values are plain arrays (the serialised catalogue), never objects: a cached
 * object would resurrect stale class versions after an upgrade.
 */
interface WP_MCP_AI_Db_Cache_Interface {

	/**
	 * Read a cached value.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>|null Null on a miss.
	 */
	public function get( string $key ): ?array;

	/**
	 * Write a value.
	 *
	 * @param string               $key   Cache key.
	 * @param array<string, mixed> $value Value.
	 * @param int                  $ttl   Time to live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void;

	/**
	 * Remove a value.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void;
}
