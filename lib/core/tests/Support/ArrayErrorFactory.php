<?php

/**
 * An error factory that returns plain arrays.
 *
 * The canonical failure surface of a tool is whatever the host's
 * `ErrorFactoryInterface` builds. A test that needs to read the code and payload
 * back — rather than assert an exception — uses this, so it inspects exactly the
 * shape WordPress would produce rather than a stand-in defined per test.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

final class ArrayErrorFactory implements ErrorFactoryInterface
{
    public function create(string $code, string $message, array $data = array()): mixed
    {
        return array('error' => array('code' => $code, 'message' => $message, 'data' => $data));
    }

    public function isError(mixed $value): bool
    {
        return is_array($value) && isset($value['error']);
    }

    public function normalize(mixed $error): array
    {
        return is_array($error) ? (array) ($error['error'] ?? $error) : array();
    }

    public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
    {
        return $this->create('not_found', $message, $data);
    }

    public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
    {
        return $this->create('forbidden', $message, $data);
    }

    public function validationFailed(string $message, array $errors = array()): mixed
    {
        return $this->create('validation_failed', $message, array('errors' => $errors));
    }

    public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
    {
        return $this->create('rate_limited', $message, array('retry_after' => $retryAfterSeconds));
    }
}
