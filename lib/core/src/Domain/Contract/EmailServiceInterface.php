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

interface EmailServiceInterface
{
    /**
     * Send one message.
     *
     * The keys every implementation reads are `to`, `subject` and `body`;
     * `is_html` opts into an HTML body. `attachments` is a list of
     * `array{name: string, mime: string, content: string}` where `content` is
     * the raw bytes — a caller that needs an invite, an invoice or a report
     * delivered with the message supplies it here rather than hoping the
     * transport guesses.
     *
     * A transport that cannot carry a file must refuse the message
     * (`success => false`) rather than silently dropping it: a receipt the
     * client cannot open is worse than a send that failed loudly.
     *
     * @param array<string, mixed> $message Message fields.
     * @return array<string, mixed> `success` plus `message_id`, or `error`.
     */
    public function send(array $message): array;
    public function isAvailable(): bool;
    public function validateRecipient(string $email): bool;
}
