<?php
/**
 * Chat Continuation — domain contract.
 *
 * Durable conversation state for async job → chat session resumption.
 * Mirrors the OpenAI Responses API `background=true` pattern.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ChatContinuationInterface
{
    /**
     * Save a conversation snapshot for later resumption.
     *
     * @return array{success: bool, continuation_id: string, error?: string}
     */
    public function save(string $jobId, string $sessionId, array $conversationState, array $metadata = []): array;

    /**
     * Load a saved conversation snapshot.
     *
     * @return array{found: bool, state?: array, metadata?: array}
     */
    public function load(string $continuationId): array;

    /**
     * Mark a continuation as completed (job finished, LLM re-engaged).
     *
     * @return array{success: bool, error?: string}
     */
    public function complete(string $continuationId, array $result = []): array;

    /**
     * List pending continuations for a chat session.
     *
     * @return array<int, array{continuation_id: string, job_id: string, status: string, created_at: int}>
     */
    public function listBySession(string $sessionId): array;

    /**
     * Delete a continuation snapshot.
     */
    public function delete(string $continuationId): void;

    /**
     * Get the count of pending continuations.
     *
     * @return array{total: int, pending: int, completed: int, failed: int}
     */
    public function getCounts(string $sessionId = ''): array;
}
