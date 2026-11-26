<?php

/**
 * Memory Store — domain contract.
 *
 * Store, retrieve, and manage agent memories with tiered storage,
 * semantic search, and privacy controls.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Persistent agent memory storage with tiered access.
 *
 * @since 2.0.0
 */
interface MemoryStoreInterface
{
    /** Memory tiers ordered weakest → strongest. */
    public const TIER_ARCHIVAL = 'archival';
    public const TIER_RECALL   = 'recall';
    public const TIER_CORE     = 'core';

    /**
     * Byte ceiling for one record's `metadata` map, after JSON encoding.
     *
     * `metadata` exists for provenance — a source reference, a validity window,
     * the ids of files that support the claim — not for payloads. An unbounded
     * JSON column is how a memory store quietly becomes a file store, so the
     * ceiling is part of the contract rather than an adapter detail: bytes
     * belong in the file registry, referenced from here by id.
     */
    public const MAX_METADATA_BYTES = 65536;

    /**
     * Store an agent memory record.
     *
     * ## `metadata`
     *
     * A small free-form map for whatever the caller knows about the record that
     * the columns do not express: provenance (`subject_refs`, `attachments`),
     * temporal validity (`recorded_at`, `valid_from`, `valid_until`) and the
     * capture policy a tool applied (`verbatim`, `sensitivity`,
     * `consent_basis`). Keys are the caller's; the store does not interpret,
     * order or filter on them, and an adapter that cannot persist a key must
     * drop the whole record rather than store a partial map.
     *
     * Omit it, or pass nothing, when there is no provenance to carry.
     *
     * @param array{agent_id: string, content: string, title?: string, tags?: array<int, string>, tier?: string, importance?: float, wing?: string, room?: string, ttl?: int, metadata?: array<string, mixed>} $record
     *
     * @return array{success: bool, memory_id?: string, error?: string}
     */
    public function store(array $record): array;

    /**
     * Retrieve a memory by ID.
     *
     * The returned `record` carries the same keys `store()` accepted, so
     * `metadata` is present when it was written and is an empty array when it was
     * not: a caller should never have to distinguish "absent" from "empty" to
     * read a provenance field.
     *
     * @param string $memoryId Memory identifier.
     *
     * @return array{found: bool, record?: array<string, mixed>}
     */
    public function get(string $memoryId): array;

    /**
     * Update an existing memory record.
     *
     * ## `metadata` is merged, not replaced
     *
     * Every other field is replaced outright. `metadata` is treated as a JSON
     * Merge Patch (RFC 7386): keys in the patch are applied over the stored map,
     * a nested object is merged recursively, an array replaces wholesale, and a
     * `null` value **removes** that key.
     *
     * Merge rather than replace because the reason to patch metadata is to add
     * provenance after the fact — set `valid_until` when a record is superseded,
     * add a `subject_ref` when a document is attached — and a replace would force
     * every caller into a read-modify-write race to keep the keys it did not mean
     * to touch.
     *
     * A patch that changes nothing still succeeds: "nothing to do" is not
     * "no such record".
     *
     * @param string $memoryId Memory identifier.
     * @param array<string, mixed> $patch Fields to update.
     *
     * @return array{success: bool, error?: string}
     */
    public function update(string $memoryId, array $patch): array;

    /**
     * Delete a memory record.
     *
     * @param string $memoryId Memory identifier.
     *
     * @return array{success: bool, error?: string}
     */
    public function delete(string $memoryId): array;

    /**
     * Search memories by semantic query.
     *
     * `metadata` in each result is the map that was stored, which is where a
     * caller finds the provenance and validity it wrote.
     *
     * @param string $query    Search query text.
     * @param array<string, mixed>  $filters  Optional filters (agent_id, tier, tags, wing, room).
     * @param int    $limit    Maximum results.
     *
     * @return array<int, array{memory_id: string, content: string, title?: string, score?: float, metadata: array<string, mixed>}>
     */
    public function search(string $query, array $filters = [], int $limit = 10): array;

    /**
     * List memories for an agent with optional filters.
     *
     * @param string $agentId Agent identifier.
     * @param array<string, mixed>  $filters Optional filters (tier, tags, wing).
     * @param int    $limit   Maximum results.
     * @param int    $offset  Pagination offset.
     *
     * @return array{memories: array<int, array<string, mixed>>, total: int}
     */
    public function listByAgent(string $agentId, array $filters = [], int $limit = 50, int $offset = 0): array;
}
