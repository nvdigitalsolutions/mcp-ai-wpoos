<?php

/**
 * Raised when a bundle write is refused.
 *
 * Carries an `okf_*` code alongside the message so a caller can distinguish a
 * refusal it caused (a missing `type`, a traversal attempt) from a filesystem
 * failure, without matching on prose.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

final class OkfWriteException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
