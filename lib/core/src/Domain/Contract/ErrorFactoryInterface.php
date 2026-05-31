<?php
/**
 * Error factory contract for the oOS AI orchestration core.
 *
 * Abstracts framework-specific error creation so that the core engine
 * never depends on WP_Error, Laravel exceptions, or any other error type.
 * Each platform adapter implements this interface with its native error type.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

interface ErrorFactoryInterface {

	/**
	 * Create an error instance.
	 *
	 * The concrete return type is platform-specific:
	 *  - WordPress: \WP_Error
	 *  - Laravel:   throws a domain exception
	 *  - Standalone: Oos\Core\Domain\Error\DomainError
	 *
	 * @return mixed  Framework-specific error object.
	 */
	public function create( string $code, string $message, array $data = array() ): mixed;

	/**
	 * Check if a value represents an error.
	 *
	 * Replaces `is_wp_error()` for framework-agnostic code.
	 */
	public function isError( mixed $value ): bool;

	/**
	 * Normalize any error to a consistent array shape.
	 *
	 * This is the universal escape hatch — callers that need to serialize
	 * or log errors use this to get a predictable array regardless of
	 * the underlying error type.
	 *
	 * @return array{code: string, message: string, data: array}
	 */
	public function normalize( mixed $error ): array;

	/**
	 * Create a "not found" error.
	 */
	public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed;

	/**
	 * Create a "forbidden / access denied" error.
	 */
	public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed;

	/**
	 * Create a "validation failed" error with field-level error details.
	 *
	 * @param array<string, string[]> $errors  Field name → [error messages].
	 */
	public function validationFailed( string $message, array $errors = array() ): mixed;

	/**
	 * Create a "rate limit exceeded" error.
	 *
	 * @param int $retryAfterSeconds  Seconds until the client may retry.
	 */
	public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed;
}
