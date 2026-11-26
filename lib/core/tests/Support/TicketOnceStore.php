<?php

/**
 * Reusable test doubles for the core suite.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\ValueObject\ApprovalRequest;

/**
 * A ticket store that can be approved once, then refuses.
 *
 * `RecordingApprovalStore` answers the same way forever, which cannot express
 * "spend exactly one" — the property that makes an approval an approval rather
 * than a standing permit.
 *
 * @internal Test double.
 */
final class TicketOnceStore implements ApprovalTicketInterface
{
    public bool $approved = false;

    public int $requested = 0;

    public int $spent = 0;

    public ?ApprovalRequest $lastRequest = null;

    public function request(ApprovalRequest $request): string
    {
        ++$this->requested;
        $this->lastRequest = $request;

        return '9';
    }

    public function consume(ApprovalRequest $request): bool
    {
        $this->lastRequest = $request;

        if (! $this->approved) {
            return false;
        }

        // Single use: the next consume refuses until a human approves again.
        $this->approved = false;
        ++$this->spent;

        return true;
    }
}
