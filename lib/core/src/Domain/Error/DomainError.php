<?php
/**
 * Generic domain error — the standalone/oOS-native error type.
 *
 * Used when no framework-specific error type is available (standalone
 * PHP, testing). WordPress uses WP_Error via the adapter. Laravel
 * throws exceptions. This class provides a consistent, serializable
 * error when no framework is present.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Error;

final class DomainError implements \JsonSerializable
{
    /**
     * @param string $code    Machine-readable error code (snake_case).
     * @param string $message Human-readable error message.
     * @param array  $data    Additional structured error data.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'code'    => $this->code,
            'message' => $this->message,
            'data'    => $this->data,
        ];
    }
}
