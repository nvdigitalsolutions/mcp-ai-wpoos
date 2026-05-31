<?php
/**
 * Thrown when a requested resource cannot be found.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Error;

class NotFoundException extends \RuntimeException
{
    /**
     * @param string          $message
     * @param string          $resourceType  e.g., 'post', 'user', 'assistant'.
     * @param int|string|null $resourceId    The ID that was looked up.
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = 'Resource not found.',
        public readonly string $resourceType = '',
        public readonly int|string|null $resourceId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 404, $previous);
    }
}
