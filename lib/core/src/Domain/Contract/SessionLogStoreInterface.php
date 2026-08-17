<?php
/**
 * SessionLogStoreInterface — persistence contract for session logs.
 *
 * Platform adapters implement JSONL files, SQLite, options, or a remote
 * sink. The core only depends on append/load; replay and resume rebuild
 * the log via SessionLog::fromExported().
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface SessionLogStoreInterface {

	/**
	 * Persist one entry for the given session.
	 */
	public function append( string $sessionId, array $entry ): void;

	/**
	 * Load all persisted entries (exported arrays) for a session.
	 *
	 * @return array<int, array>  Entry arrays in append order.
	 */
	public function load( string $sessionId ): array;
}
