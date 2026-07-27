<?php
/**
 * Assistant Service — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Assistant management and configuration retrieval.
 *
 * @since 2.0.0
 */
interface AssistantServiceInterface
{
    /**
     * Validate that an assistant exists and the user can access it.
     *
     * @param int $assistantId Assistant post ID.
     * @param int $userId      User ID (0 = current user).
     *
     * @return array{valid: bool, assistant?: array, error?: string}
     */
    public function validate(int $assistantId, int $userId = 0): array;

    /**
     * Get the full configuration for an assistant.
     *
     * @param int $assistantId Assistant post ID.
     *
     * @return array{found: bool, config?: array}
     */
    public function getConfig(int $assistantId): array;

    /**
     * Get the default assistant for the current context.
     *
     * @return array{found: bool, assistant_id?: int, config?: array}
     */
    public function getDefault(): array;

    /**
     * List all available assistants for a user.
     *
     * @param int $userId User ID.
     *
     * @return array<int, array{id: int, title: string, status: string, capabilities: array}>
     */
    public function listForUser(int $userId = 0): array;
}
