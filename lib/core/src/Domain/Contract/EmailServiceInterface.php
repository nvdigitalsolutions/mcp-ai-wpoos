<?php

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface EmailServiceInterface {
    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function send(array $message): array;
    public function isAvailable(): bool;
    public function validateRecipient(string $email): bool;
}
