<?php

/**
 * Approval store double that records how the guard used it.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\ValueObject\ApprovalRequest;

/**
 * @internal Test double.
 */
final class RecordingApprovalStore implements ApprovalTicketInterface
{
    public int $requested = 0;

    public int $consumed = 0;

    public ?ApprovalRequest $lastRequest = null;

    public function __construct(
        private readonly bool $consumable,
        private readonly string $id = '1',
    ) {
    }

    public function request(ApprovalRequest $request): string
    {
        ++$this->requested;
        $this->lastRequest = $request;

        return $this->id;
    }

    public function consume(ApprovalRequest $request): bool
    {
        ++$this->consumed;
        $this->lastRequest = $request;

        return $this->consumable;
    }
}
