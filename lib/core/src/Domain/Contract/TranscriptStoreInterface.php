<?php
/**
 * Transcript Store — domain contract for chat transcript persistence.
 *
 * Abstracts chat transcript storage across backends (localStorage,
 * JetEngine CCT, database, Redis) so the ChatOrchestrator never
 * depends on a specific storage engine.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TranscriptStoreInterface
{
    /**
     * Save a chat transcript.
     *
     * @param array{assistant_id: int|string, session_id: string, messages: array, metadata?: array} $transcript
     *
     * @return array{success: bool, transcript_id: string, error?: string}
     */
    public function save(array $transcript): array;

    /**
     * Retrieve a transcript by ID.
     *
     * @return array{found: bool, transcript?: array}
     */
    public function get(string $transcriptId): array;

    /**
     * List transcripts for an assistant or session.
     *
     * @param array{assistant_id?: int|string, session_id?: string, limit?: int, offset?: int} $filters
     *
     * @return array{transcripts: array, total: int}
     */
    public function list(array $filters = []): array;

    /**
     * Delete a transcript.
     *
     * @return array{success: bool, error?: string}
     */
    public function delete(string $transcriptId): array;

    /**
     * Prune transcripts older than a given age.
     *
     * @param int $olderThanSeconds  Delete transcripts older than this many seconds.
     *
     * @return array{success: bool, deleted_count: int, error?: string}
     */
    public function prune(int $olderThanSeconds): array;

    /**
     * Get transcript count by assistant or session.
     *
     * @return array{total: int, by_assistant?: array<int|string, int>}
     */
    public function getCounts(array $filters = []): array;

    /**
     * Whether the store is available and writable.
     */
    public function isAvailable(): bool;
}
