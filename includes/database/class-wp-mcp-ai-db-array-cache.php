<?php
/**
 * Per-process cache, used by the portable adapter tests.
 *
 * Proposal 167. Deliberately not persistent: it proves the catalogue loader's
 * caching contract without assuming a WordPress object cache or options table.
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
 * In-memory cache with per-entry expiry.
 */
final class WP_MCP_AI_Db_Array_Cache implements WP_MCP_AI_Db_Cache_Interface {

	/**
	 * Entries keyed by cache key.
	 *
	 * @var array<string, array{value: array<string, mixed>, expires: int}>
	 */
	private array $entries = array();

	/**
	 * Current-time provider, injectable for expiry tests.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns a Unix timestamp; defaults to `time()`.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 */
	public function get( string $key ): ?array {
		if ( ! isset( $this->entries[ $key ] ) ) {
			return null;
		}

		$entry = $this->entries[ $key ];

		if ( $entry['expires'] <= (int) call_user_func( $this->clock ) ) {
			unset( $this->entries[ $key ] );

			return null;
		}

		return $entry['value'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $key   Cache key.
	 * @param array<string, mixed> $value Value.
	 * @param int                  $ttl   Time to live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void {
		$this->entries[ $key ] = array(
			'value'   => $value,
			'expires' => (int) call_user_func( $this->clock ) + max( 1, $ttl ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		unset( $this->entries[ $key ] );
	}
}
