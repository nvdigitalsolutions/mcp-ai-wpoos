<?php
/**
 * Laravel adapter: ErrorFactoryInterface implementation.
 *
 * Wraps Laravel's exception system behind the framework-agnostic
 * ErrorFactoryInterface. Unlike WordPress's WP_Error (which is a
 * return-value error pattern), Laravel uses exceptions for error
 * signalling. This adapter bridges both worlds:
 *  - create() returns a throwable exception object (not thrown yet)
 *  - isError() checks for Throwable instances
 *  - normalize() extracts code/message/data from any exception
 *
 * The caller decides whether to throw or return the error.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class ErrorFactory implements ErrorFactoryInterface {

	/**
	 * Create a framework-agnostic error object (a Throwable, not thrown).
	 *
	 * Returns a RuntimeException carrying the domain code, message, and
	 * contextual data. Callers may throw it, log it, or convert it further.
	 *
	 * @param string               $code    Domain error code (e.g. 'not_found').
	 * @param string               $message Human-readable error message.
	 * @param array<string, mixed> $data    Contextual data (field errors, IDs, etc.).
	 *
	 * @return \RuntimeException
	 */
	public function create( string $code, string $message, array $data = array() ): mixed {
		return new \RuntimeException(
			message: $message,
			code: $this->codeToInt( $code ),
		);
	}

	/**
	 * Check if a value represents an error.
	 *
	 * Replaces is_wp_error() — any Throwable is an error.
	 *
	 * @param mixed $value  Value to test.
	 * @return bool
	 */
	public function isError( mixed $value ): bool {
		return $value instanceof \Throwable;
	}

	/**
	 * Normalize any error to a consistent array shape.
	 *
	 * Handles Throwable, HTTP exceptions (extracting status codes),
	 * and plain strings for maximum compatibility.
	 *
	 * @param mixed $error  A Throwable or error string.
	 *
	 * @return array{code: string, message: string, data: array}
	 */
	public function normalize( mixed $error ): array {
		if ( ! $error instanceof \Throwable ) {
			return array(
				'code'    => 'unknown_error',
				'message' => is_string( $error ) ? $error : 'An unexpected error occurred.',
				'data'    => array(),
			);
		}

		$data = array(
			'file'  => $error->getFile(),
			'line'  => $error->getLine(),
			'trace' => $error->getTraceAsString(),
		);

		// If it's an HTTP exception, include the status code.
		if ( $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ) {
			$data['status']  = $error->getStatusCode();
			$data['headers'] = $error->getHeaders();
		}

		return array(
			'code'    => $this->exceptionToCode( $error ),
			'message' => $error->getMessage(),
			'data'    => $data,
		);
	}

	/**
	 * Create a "not found" error (HTTP 404).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $data    Contextual data.
	 *
	 * @return \RuntimeException
	 */
	public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
		return new \RuntimeException(
			message: $message,
			code: 404,
		);
	}

	/**
	 * Create a "forbidden / access denied" error (HTTP 403).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $data    Contextual data.
	 *
	 * @return \RuntimeException
	 */
	public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
		return new \RuntimeException(
			message: $message,
			code: 403,
		);
	}

	/**
	 * Create a "validation failed" error with field-level error details (HTTP 422).
	 *
	 * @param string                  $message Human-readable summary.
	 * @param array<string, string[]> $errors  Field name → [error messages].
	 *
	 * @return \RuntimeException
	 */
	public function validationFailed( string $message, array $errors = array() ): mixed {
		return new \RuntimeException(
			message: $message,
			code: 422,
		);
	}

	/**
	 * Create a "rate limit exceeded" error (HTTP 429).
	 *
	 * @param string $message            Human-readable message.
	 * @param int    $retryAfterSeconds  Seconds until the client may retry.
	 *
	 * @return \RuntimeException
	 */
	public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
		return new \RuntimeException(
			message: $message,
			code: 429,
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Convert a domain error code to an integer HTTP status for exception codes.
	 */
	private function codeToInt( string $code ): int {
		return match ( $code ) {
			'not_found'         => 404,
			'forbidden'         => 403,
			'validation_failed' => 422,
			'rate_limited'      => 429,
			'authentication'    => 401,
			default             => 500,
		};
	}

	/**
	 * Extract a machine-readable code string from an exception.
	 *
	 * Maps well-known Laravel/Symfony exception classes to domain error codes,
	 * falling back to the exception's numeric code or class basename.
	 */
	private function exceptionToCode( \Throwable $e ): string {
		$class = get_class( $e );

		$map = array(
			\Illuminate\Database\Eloquent\ModelNotFoundException::class     => 'not_found',
			\Illuminate\Auth\Access\AuthorizationException::class           => 'forbidden',
			\Illuminate\Auth\AuthenticationException::class                 => 'authentication',
			\Illuminate\Validation\ValidationException::class               => 'validation_failed',
			\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class   => 'not_found',
			\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class => 'forbidden',
		);

		if ( isset( $map[ $class ] ) ) {
			return $map[ $class ];
		}

		$code = (int) $e->getCode();

		return 0 !== $code ? (string) $code : class_basename( $class );
	}
}
