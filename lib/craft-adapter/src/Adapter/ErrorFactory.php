<?php
/**
 * Craft adapter: ErrorFactoryInterface implementation.
 *
 * Wraps Yii/Craft exceptions behind the framework-agnostic
 * ErrorFactoryInterface. Like the Laravel adapter, uses exceptions
 * for error signalling rather than return-value error objects.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class ErrorFactory implements ErrorFactoryInterface {

	/**
	 * Create a framework-agnostic error object (a Throwable, not thrown).
	 *
	 * @param string               $code    Domain error code.
	 * @param string               $message Human-readable error message.
	 * @param array<string, mixed> $data    Contextual data.
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
	 */
	public function isError( mixed $value ): bool {
		return $value instanceof \Throwable;
	}

	/**
	 * Normalize any error to a consistent array shape.
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

		// Map HTTP exceptions to status codes.
		if ( $error instanceof \yii\web\HttpException ) {
			$data['status'] = $error->statusCode;
		}

		return array(
			'code'    => $this->exceptionToCode( $error ),
			'message' => $error->getMessage(),
			'data'    => $data,
		);
	}

	public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
		return new \RuntimeException( message: $message, code: 404 );
	}

	public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
		return new \RuntimeException( message: $message, code: 403 );
	}

	public function validationFailed( string $message, array $errors = array() ): mixed {
		return new \RuntimeException( message: $message, code: 422 );
	}

	public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
		return new \RuntimeException( message: $message, code: 429 );
	}

	// ─── Private helpers ──────────────────────────────────────────────

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

	private function exceptionToCode( \Throwable $e ): string {
		$class = get_class( $e );

		$map = array(
			\yii\web\NotFoundHttpException::class    => 'not_found',
			\yii\web\ForbiddenHttpException::class   => 'forbidden',
			\yii\web\UnauthorizedHttpException::class => 'authentication',
		);

		if ( isset( $map[ $class ] ) ) {
			return $map[ $class ];
		}

		$code = (int) $e->getCode();

		return 0 !== $code ? (string) $code : basename( str_replace( '\\', '/', $class ) );
	}
}
