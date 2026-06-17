<?php
/**
 * Tests for the Craft CMS ErrorFactory adapter.
 *
 * Verifies the adapter correctly implements ErrorFactoryInterface,
 * maps domain error codes to HTTP statuses, and normalises exceptions
 * including Yii HTTP exceptions.
 *
 * @package Nvoos\Craft\Tests
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Nvoos\Craft\Tests\Adapter;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Craft\Adapter\ErrorFactory;
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
		$error = $this->factory->create( 'not_found', 'Element not found.' );

		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 404, $error->getCode() );
		$this->assertSame( 'Element not found.', $error->getMessage() );
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

	public function test_create_maps_unknown_code_to_500(): void {
		$error = $this->factory->create( 'custom_code', 'Custom error.' );

		$this->assertSame( 500, $error->getCode() );
	}

	public function test_is_error_detects_throwable(): void {
		$exception = new \RuntimeException( 'test' );

		$this->assertTrue( $this->factory->isError( $exception ) );
		$this->assertFalse( $this->factory->isError( 'not an error' ) );
	}

	public function test_is_error_detects_yii_http_exception(): void {
		// Yii HTTP exceptions are Throwable, so isError should detect them.
		if ( class_exists( \yii\web\HttpException::class ) ) {
			$yiiError = new \yii\web\NotFoundHttpException( 'Page not found.' );
			$this->assertTrue( $this->factory->isError( $yiiError ) );
		} else {
			$this->markTestSkipped( 'Yii framework not loaded.' );
		}
	}

	public function test_normalize_handles_throwable(): void {
		$exception = new \RuntimeException( 'Something broke.', 500 );
		$result    = $this->factory->normalize( $exception );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_normalize_handles_yii_http_exception(): void {
		if ( ! class_exists( \yii\web\HttpException::class ) ) {
			$this->markTestSkipped( 'Yii framework not loaded.' );
		}

		$error  = new \yii\web\NotFoundHttpException( 'Element not found.' );
		$result = $this->factory->normalize( $error );

		$this->assertArrayHasKey( 'data', $result );
		// Yii HttpException embeds the status code.
		$this->assertEquals( 404, $result['data']['status'] ?? 0 );
	}

	public function test_not_found_returns_exception_with_404(): void {
		$error = $this->factory->notFound( 'Missing.' );

		$this->assertSame( 404, $error->getCode() );
	}

	public function test_forbidden_returns_exception_with_403(): void {
		$error = $this->factory->forbidden();

		$this->assertSame( 403, $error->getCode() );
	}

	public function test_validation_failed_returns_exception_with_422(): void {
		$error = $this->factory->validationFailed( 'Bad data.' );

		$this->assertSame( 422, $error->getCode() );
	}

	public function test_rate_limited_returns_exception_with_429(): void {
		$error = $this->factory->rateLimited( 'Calm down.', 120 );

		$this->assertSame( 429, $error->getCode() );
	}
}
