<?php
/**
 * Tests for domain error classes.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Error;

use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Error\AuthenticationException;
use Nvoos\Core\Domain\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class DomainErrorTest extends TestCase {

	public function testAccessDeniedException(): void {
		$error = new AccessDeniedException(
			'User cannot edit this post.',
			userId: 5,
			capability: 'edit_posts',
			objectId: 42,
		);

		$this->assertSame( 'User cannot edit this post.', $error->getMessage() );
		$this->assertSame( 403, $error->getCode() );
		$this->assertSame( 5, $error->userId );
		$this->assertSame( 'edit_posts', $error->capability );
		$this->assertSame( 42, $error->objectId );
	}

	public function testNotFoundException(): void {
		$error = new NotFoundException(
			'Post not found.',
			resourceType: 'post',
			resourceId: 999,
		);

		$this->assertSame( 'Post not found.', $error->getMessage() );
		$this->assertSame( 404, $error->getCode() );
		$this->assertSame( 'post', $error->resourceType );
		$this->assertSame( 999, $error->resourceId );
	}

	public function testNotFoundExceptionDefaultMessage(): void {
		$error = new NotFoundException();

		$this->assertSame( 'Resource not found.', $error->getMessage() );
		$this->assertSame( 404, $error->getCode() );
		$this->assertSame( '', $error->resourceType );
	}

	public function testValidationException(): void {
		$error = new ValidationException(
			'Title is required.',
			errors: array( 'title' => array( 'Title is required.' ) ),
		);

		$this->assertSame( 'Title is required.', $error->getMessage() );
		$this->assertSame( 422, $error->getCode() );
		$this->assertSame(
			array( 'title' => array( 'Title is required.' ) ),
			$error->errors,
		);
	}

	public function testValidationExceptionHasFieldErrors(): void {
		$withErrors = new ValidationException(
			'Invalid.',
			errors: array( 'email' => array( 'Required.' ) ),
		);
		$this->assertTrue( $withErrors->hasFieldErrors() );

		$noErrors = new ValidationException( 'Invalid.' );
		$this->assertFalse( $noErrors->hasFieldErrors() );
	}

	public function testAuthenticationException(): void {
		$error = new AuthenticationException(
			'Token has expired.',
			reason: 'expired',
		);

		$this->assertSame( 'Token has expired.', $error->getMessage() );
		$this->assertSame( 401, $error->getCode() );
		$this->assertSame( 'expired', $error->reason );
	}

	public function testAuthenticationExceptionDefaultReason(): void {
		$error = new AuthenticationException( 'Bad token.' );

		$this->assertSame( 'invalid', $error->reason );
	}

	public function testDomainError(): void {
		$error = new DomainError(
			code: 'rate_limited',
			message: 'Too many requests.',
			data: array( 'retry_after' => 60 ),
		);

		$this->assertSame( 'rate_limited', $error->code );
		$this->assertSame( 'Too many requests.', $error->message );
		$this->assertSame( array( 'retry_after' => 60 ), $error->data );
	}

	public function testDomainErrorJsonSerialize(): void {
		$error = new DomainError(
			code: 'not_found',
			message: 'Post 42 not found.',
			data: array( 'post_id' => 42 ),
		);

		$json = $error->jsonSerialize();

		$this->assertSame( 'not_found', $json['code'] );
		$this->assertSame( 'Post 42 not found.', $json['message'] );
		$this->assertSame( 42, $json['data']['post_id'] );
	}

	public function testExceptionsExtendRuntimeException(): void {
		$this->assertInstanceOf( \RuntimeException::class, new AccessDeniedException() );
		$this->assertInstanceOf( \RuntimeException::class, new NotFoundException() );
		$this->assertInstanceOf( \RuntimeException::class, new ValidationException( 'x' ) );
		$this->assertInstanceOf( \RuntimeException::class, new AuthenticationException() );
	}
}
