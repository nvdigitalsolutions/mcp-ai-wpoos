<?php
/**
 * Memory Store — domain contract.
 *
 * Store, retrieve, and manage agent memories with tiered storage,
 * semantic search, and privacy controls.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
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
     * Store an agent memory record.
     *
     * @param array{agent_id: string, content: string, title?: string, tags?: array<int, string>, tier?: string, importance?: float, wing?: string, room?: string, ttl?: int} $record
     *
     * @return array{success: bool, memory_id?: string, error?: string}
     */
    public function store(array $record): array;

    /**
     * Retrieve a memory by ID.
     *
     * @param string $memoryId Memory identifier.
     *
     * @return array{found: bool, record?: array}
     */
    public function get(string $memoryId): array;

    /**
     * Update an existing memory record.
     *
     * @param string $memoryId Memory identifier.
     * @param array  $patch    Fields to update.
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
     * @param string $query    Search query text.
     * @param array  $filters  Optional filters (agent_id, tier, tags, wing, room).
     * @param int    $limit    Maximum results.
     *
     * @return array<int, array{memory_id: string, content: string, title?: string, score?: float, metadata: array}>
     */
    public function search(string $query, array $filters = [], int $limit = 10): array;

    /**
     * List memories for an agent with optional filters.
     *
     * @param string $agentId Agent identifier.
     * @param array  $filters Optional filters (tier, tags, wing).
     * @param int    $limit   Maximum results.
     * @param int    $offset  Pagination offset.
     *
     * @return array{memories: array, total: int}
     */
    public function listByAgent(string $agentId, array $filters = [], int $limit = 50, int $offset = 0): array;
}
