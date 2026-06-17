<?php
/**
 * Tests for the Laravel ErrorFactory adapter.
 *
 * Verifies the adapter correctly implements ErrorFactoryInterface,
 * maps domain error codes to HTTP statuses, and normalises exceptions.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests\Adapter;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Laravel\Adapter\ErrorFactory;
use PHPUnit\Framework\TestCase;

class ErrorFactoryTest extends TestCase {

	private ErrorFactoryInterface $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->factory = new ErrorFactory();
	}

	public function test_implements_interface(): void {
		$this->assertInstanceOf( ErrorFactoryInterface::class, $this->factory );
	}

	public function test_create_returns_runtime_exception_with_http_code(): void {
		$error = $this->factory->create( 'not_found', 'Item not found.' );

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 404, $error->getCode() );
		$this->assertSame( 'Item not found.', $error->getMessage() );
	}

	public function test_create_maps_forbidden_to_403(): void {
		$error = $this->factory->create( 'forbidden', 'Access denied.' );

		$this->assertSame( 403, $error->getCode() );
	}

	public function test_create_maps_validation_to_422(): void {
		$error = $this->factory->create( 'validation_failed', 'Invalid input.' );

		$this->assertSame( 422, $error->getCode() );
	}

	public function test_create_maps_rate_limited_to_429(): void {
		$error = $this->factory->create( 'rate_limited', 'Too many requests.' );

		$this->assertSame( 429, $error->getCode() );
	}

	public function test_create_maps_authentication_to_401(): void {
		$error = $this->factory->create( 'authentication', 'Invalid token.' );

		$this->assertSame( 401, $error->getCode() );
	}

	public function test_create_unknown_code_defaults_to_500(): void {
		$error = $this->factory->create( 'custom_error', 'Something broke.' );

		$this->assertSame( 500, $error->getCode() );
	}

	public function test_is_error_detects_throwable(): void {
		$exception = new \RuntimeException( 'test' );
		$value     = 'not an error';

		$this->assertTrue( $this->factory->isError( $exception ) );
		$this->assertFalse( $this->factory->isError( $value ) );
	}

	public function test_normalize_handles_throwable(): void {
		$exception = new \RuntimeException( 'Something went wrong.', 500 );
		$result    = $this->factory->normalize( $exception );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertSame( 'Something went wrong.', $result['message'] );
	}

	public function test_normalize_handles_plain_string(): void {
		$result = $this->factory->normalize( 'A string error' );

		$this->assertSame( 'unknown_error', $result['code'] );
		$this->assertSame( 'A string error', $result['message'] );
	}

	public function test_normalize_handles_non_throwable_non_string(): void {
		$result = $this->factory->normalize( array( 'weird' ) );

		$this->assertSame( 'unknown_error', $result['code'] );
		$this->assertSame( 'An unexpected error occurred.', $result['message'] );
	}

	public function test_not_found_returns_exception_with_404(): void {
		$error = $this->factory->notFound( 'Post not found.' );

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 404, $error->getCode() );
	}

	public function test_forbidden_returns_exception_with_403(): void {
		$error = $this->factory->forbidden();

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 403, $error->getCode() );
		$this->assertSame( 'Access denied.', $error->getMessage() );
	}

	public function test_validation_failed_returns_exception_with_422(): void {
		$error = $this->factory->validationFailed(
			'Invalid fields.',
			array( 'email' => array( 'Required.' ) ),
		);

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 422, $error->getCode() );
	}

	public function test_rate_limited_returns_exception_with_429(): void {
		$error = $this->factory->rateLimited( 'Slow down.', 30 );

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 429, $error->getCode() );
	}
}
