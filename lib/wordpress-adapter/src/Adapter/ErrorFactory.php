<?php
/**
 * WordPress adapter: ErrorFactoryInterface implementation.
 *
 * Wraps WP_Error behind the framework-agnostic ErrorFactoryInterface
 * so that core services can create and inspect errors without importing
 * WordPress functions directly.
 *
 * @package Oos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Oos\WordPress\Adapter;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;

class ErrorFactory implements ErrorFactoryInterface {

	/**
	 * Create a WordPress WP_Error instance.
	 *
	 * @return \WP_Error
	 */
	public function create( string $code, string $message, array $data = array() ): mixed {
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Check if a value is a WP_Error.
	 */
	public function isError( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}

	/**
	 * Normalize a WP_Error to a consistent array.
	 *
	 * @return array{code: string, message: string, data: array}
	 */
	public function normalize( mixed $error ): array {
		if ( ! $error instanceof \WP_Error ) {
			return array(
				'code'    => 'unknown_error',
				'message' => 'An unexpected error occurred.',
				'data'    => array(),
			);
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array( 'raw' => $data );
		}

		return array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $data,
		);
	}

	public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
		$data['status'] = 404;
		return new \WP_Error( 'not_found', $message, $data );
	}

	public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
		$data['status'] = 403;
		return new \WP_Error( 'forbidden', $message, $data );
	}

	public function validationFailed( string $message, array $errors = array() ): mixed {
		$data = array(
			'errors' => $errors,
			'status' => 422,
		);
		return new \WP_Error( 'validation_failed', $message, $data );
	}

	public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
		return new \WP_Error(
			'rate_limited',
			$message,
			array(
				'status'      => 429,
				'retry_after' => $retryAfterSeconds,
			)
		);
	}
}
