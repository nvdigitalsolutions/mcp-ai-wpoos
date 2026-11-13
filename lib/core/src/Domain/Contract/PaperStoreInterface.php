<?php
/**
 * Paper Store contract — flat-file document collections.
 *
 * The Paper Store is the runtime's curated-knowledge surface: a set of named
 * collections holding JSON documents (research records, prompts, workflows,
 * brand kits). WordPress stores them as one file per record under an uploads
 * directory; this contract states that behaviour without naming WordPress, so
 * the Laravel runtime serves the same records from the same shape.
 *
 * Records are plain arrays. There is no partial-update semantics: `save()`
 * writes the whole record, and the store owns `id`, `collection`,
 * `schemaVersion`, `created_at` and `updated_at`.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface PaperStoreInterface {

	/**
	 * Stable on-disk/API schema version for records written by this contract.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Collections that hold at least one record, sorted.
	 *
	 * @return list<string>
	 */
	public function collections(): array;

	/**
	 * Whether a record exists.
	 */
	public function has( string $collection, string $id ): bool;

	/**
	 * Read one record, or null when it is absent.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $collection, string $id ): ?array;

	/**
	 * Filter a collection.
	 *
	 * Supported criteria — all optional, all additive:
	 *
	 *   - `tags`      string  Records carrying this tag.
	 *   - `status`    string  Exact status match.
	 *   - `type`      string  Exact type match.
	 *   - `search`    string  Case-insensitive substring of title or description.
	 *   - `limit`     int     1–200, default 25.
	 *   - `offset`    int     Rows to skip, default 0.
	 *   - `order_by`  string  `updated_at` (default), `created_at`, `title`, `id`.
	 *   - `direction` string  `desc` (default) or `asc`.
	 *
	 * Returns the page plus the total matching count, because a flat-file store
	 * has to read the candidate set anyway — recomputing it for a separate
	 * count call would double the work for every page.
	 *
	 * @param array<string, mixed> $criteria Filter and paging options.
	 *
	 * @return array{records: list<array<string, mixed>>, total: int}
	 */
	public function query( string $collection, array $criteria = array() ): array;

	/**
	 * Create or replace a record.
	 *
	 * The store assigns `id` (from the record when given, otherwise a slug of
	 * the title), stamps `collection`, `schemaVersion` and the timestamps, and
	 * preserves `created_at` when the record already exists.
	 *
	 * @param array<string, mixed> $record Record fields.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @throws \InvalidArgumentException When the collection or id is unusable, or a title is missing.
	 */
	public function save( string $collection, array $record ): array;

	/**
	 * Remove a record. Returns false when it was already absent.
	 */
	public function delete( string $collection, string $id ): bool;
}
