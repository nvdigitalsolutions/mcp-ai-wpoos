<?php
/**
 * Thrown when a user lacks permission to perform an operation.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Error;

class AccessDeniedException extends \RuntimeException
{
    /**
     * @param string         $message
     * @param int            $userId     The user who was denied access.
     * @param string         $capability The required capability string.
     * @param int|null       $objectId   The object ID, if object-level check.
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = 'Access denied.',
        public readonly int $userId = 0,
        public readonly string $capability = '',
        public readonly ?int $objectId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 403, $previous);
    }
}
