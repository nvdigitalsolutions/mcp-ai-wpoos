<?php
/**
 * Tests for the typed execution path.
 *
 * Covers FormatBytesTool as the reference typed tool and
 * ToolRegistry::executeResult() as the typed registry edge.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\ValueObject\ToolResult;
use Nvoos\Core\Tests\Unit\Support\InMemoryDispatcher;
use Nvoos\Core\Tool\FormatBytesTool;
use PHPUnit\Framework\TestCase;

final class TypedExecutionTest extends TestCase {

	private ErrorFactoryInterface $errors;

	protected function setUp(): void {
		$this->errors = new class() implements ErrorFactoryInterface {
			public function create( string $code, string $message, array $data = array() ): mixed {
				return array( 'kind' => 'error', 'code' => $code, 'message' => $message, 'data' => $data );
			}

			public function isError( mixed $value ): bool {
				return is_array( $value ) && 'error' === ( $value['kind'] ?? '' );
			}

			public function normalize( mixed $error ): array {
				return array(
					'code'    => (string) ( $error['code'] ?? 'unknown' ),
					'message' => (string) ( $error['message'] ?? '' ),
					'data'    => (array) ( $error['data'] ?? array() ),
				);
			}

			public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
				return $this->create( 'not_found', $message, $data );
			}

			public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
				return $this->create( 'forbidden', $message, $data );
			}

			public function validationFailed( string $message, array $errors = array() ): mixed {
				return $this->create( 'validation_failed', $message, array( 'errors' => $errors, 'status' => 422 ) );
			}

			public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
				return $this->create( 'rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ) );
			}
		};
	}

	// ─── FormatBytesTool (typed) ────────────────────────────────────

	public function testTypedToolReturnsToolResult(): void {
		$result = ( new FormatBytesTool( $this->errors ) )->executeResult( array( 'bytes' => 1536 ) );

		$this->assertInstanceOf( ToolResult::class, $result );
		$this->assertTrue( $result->isOk() );
		$this->assertSame( '1.5 KB', $result->unwrap()['formatted'] );
	}

	public function testTypedToolLegacySurfaceIsUnchanged(): void {
		$legacy = ( new FormatBytesTool( $this->errors ) )->execute( array( 'bytes' => 1536 ) );

		$this->assertTrue( $legacy['success'] );
		$this->assertSame( '1.5 KB', $legacy['data']['formatted'] );
	}

	/**
	 * The typed failure and the legacy failure must be interchangeable: the
	 * legacy surface still receives a platform error (not an envelope with
	 * success=false, which isError() would treat as success).
	 */
	public function testTypedFailureOnLegacySurfaceIsAPlatformError(): void {
		$legacy = ( new FormatBytesTool( $this->errors ) )->execute( array( 'bytes' => -1 ) );

		$this->assertTrue( $this->errors->isError( $legacy ) );
		$this->assertSame( 'validation_failed', $legacy['code'] );
		$this->assertSame( 422, $legacy['data']['status'] );
	}

	public function testTypedFailureOnTypedSurfaceIsAToolResult(): void {
		$result = ( new FormatBytesTool( $this->errors ) )->executeResult( array( 'bytes' => -1 ) );

		$this->assertTrue( $result->isFailure() );
		$this->assertSame( 'validation_failed', $result->error?->code );
	}

	public function testParsedRequestIsTotal(): void {
		$tool = new FormatBytesTool( $this->errors );

		// Missing/garbage arguments must not throw.
		$this->assertTrue( $tool->executeResult( array( 'bytes' => 'abc' ) )->isOk() );
		$this->assertTrue( $tool->executeResult( array() )->isOk() );
		$this->assertTrue( $tool->executeResult( array( 'bytes' => 1024, 'binary' => 'yes' ) )->unwrap()['binary'] );
	}

	// ─── ToolRegistry::executeResult (typed edge) ───────────────────

	public function testRegistryExecuteResultConvertsSuccess(): void {
		$registry = new ToolRegistry( new InMemoryDispatcher(), $this->errors );
		$registry->register( new FormatBytesTool( $this->errors ) );

		$result = $registry->executeResult( 'format_bytes', array( 'bytes' => 1000 ) );

		$this->assertTrue( $result->isOk() );
		$this->assertSame( '1.0 KB', $result->unwrap()['formatted'] );
	}

	public function testRegistryExecuteResultConvertsFailure(): void {
		$registry = new ToolRegistry( new InMemoryDispatcher(), $this->errors );
		$registry->register( new FormatBytesTool( $this->errors ) );

		$result = $registry->executeResult( 'format_bytes', array( 'bytes' => -5 ) );

		$this->assertTrue( $result->isFailure() );
		$this->assertSame( 'validation_failed', $result->error?->code );
	}

	public function testRegistryExecuteResultConvertsUnknownTool(): void {
		$registry = new ToolRegistry( new InMemoryDispatcher(), $this->errors );

		$result = $registry->executeResult( 'does_not_exist' );

		$this->assertTrue( $result->isFailure() );
		$this->assertSame( 'not_found', $result->error?->code );
	}

	/**
	 * A tool that throws AccessDeniedException (via authorize()) must be
	 * converted at the registry edge, not leak out of execute().
	 */
	public function testRegistryConvertsAccessDenied(): void {
		$errors   = $this->errors;
		$registry = new ToolRegistry( new InMemoryDispatcher(), $errors );

		$registry->register(
			new class( $errors ) extends \Nvoos\Core\Tool\AbstractResultTool {
				public function getSlug(): string {
					return 'needs_auth';
				}

				public function getName(): string {
					return 'Needs Auth';
				}

				public function getDescription(): string {
					return 'Requires a user.';
				}

				public function getParametersSchema(): array {
					return array( 'type' => 'object' );
				}

				public function getRequiredCapability(): string {
					return '';
				}

				public function executeResult( array $arguments = array(), array $context = array() ): ToolResult {
					$this->authorize( $context, 'edit_posts' );

					return ToolResult::success( 'ok' );
				}
			}
		);

		$legacy = $registry->execute( 'needs_auth' );
		$this->assertTrue( $errors->isError( $legacy ) );

		$typed = $registry->executeResult( 'needs_auth' );
		$this->assertTrue( $typed->isFailure() );
	}

	/**
	 * toResult() must accept all three shapes that can reach a caller.
	 */
	public function testToResultHandlesEveryShape(): void {
		$registry = new ToolRegistry( new InMemoryDispatcher(), $this->errors );

		$typed = ToolResult::success( 'pre-typed', 1 );
		$this->assertSame( $typed, $registry->toResult( $typed ) );

		$fromEnvelope = $registry->toResult( array( 'success' => true, 'message' => 'ok', 'data' => 2 ) );
		$this->assertTrue( $fromEnvelope->isOk() );
		$this->assertSame( 2, $fromEnvelope->unwrap() );

		$fromError = $registry->toResult( $this->errors->create( 'boom', 'Boom.', array( 'x' => 1 ) ) );
		$this->assertTrue( $fromError->isFailure() );
		$this->assertSame( 'boom', $fromError->error?->code );
	}
}
