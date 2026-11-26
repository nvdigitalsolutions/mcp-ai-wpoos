<?php

/**
 * An email transport that records what it was asked to send.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\EmailServiceInterface;

final class FakeEmailService implements EmailServiceInterface
{
    public bool $available = true;

    public bool $succeed = true;

    /** @var list<array<string, mixed>> */
    public array $sent = array();

    public function send(array $message): array
    {
        $this->sent[] = $message;

        return $this->succeed
            ? array('success' => true, 'message_id' => 'fake-' . count($this->sent))
            : array('success' => false, 'error' => 'Transport refused the message.');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function validateRecipient(string $email): bool
    {
        return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
