<?php
/**
 * Tests for the typed helpers on AbstractTool.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\DomainError;
use Nvoos\Core\Domain\ValueObject\Option;
use Nvoos\Core\Domain\ValueObject\ToolResult;
use Nvoos\Core\Tool\AbstractTool;
use PHPUnit\Framework\TestCase;

final class AbstractToolHelpersTest extends TestCase {

	private ErrorFactoryInterface $errors;

	private AbstractTool $tool;

	protected function setUp(): void {
		$this->errors = new class() implements ErrorFactoryInterface {
			public function create( string $code, string $message, array $data = array() ): mixed {
				return array(
					'kind'    => 'error',
					'code'    => $code,
					'message' => $message,
					'data'    => $data,
				);
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

		$this->tool = new class( $this->errors ) extends AbstractTool {
			public function getSlug(): string {
				return 'probe';
			}

			public function getName(): string {
				return 'Probe';
			}

			public function getDescription(): string {
				return 'Probe tool.';
			}

			public function getParametersSchema(): array {
				return array( 'type' => 'object' );
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				return $this->success( 'ok' );
			}

			public function callOption( array $a, string $k ): Option {
				return $this->optionParam( $a, $k );
			}

			public function callAuthorize( array $context, string $capability ): void {
				$this->authorize( $context, $capability );
			}

			public function callValidationFailure( string $message, array $fields = array() ): ToolResult {
				return $this->validationFailure( $message, $fields );
			}

			public function callUnwrap( ToolResult $result ): mixed {
				return $this->unwrapResult( $result );
			}

			public function callObjectParam( array $a, string $k, array $default = array() ): array {
				return $this->objectParam( $a, $k, $default );
			}

			public function callStringListParam( array $a, string $k ): array {
				return $this->stringListParam( $a, $k );
			}
		};
	}

	// ─── optionParam ────────────────────────────────────────────────

	public function testOptionParamNoneWhenMissing(): void {
		$this->assertTrue( $this->tool->callOption( array(), 'x' )->isNone() );
	}

	public function testOptionParamNoneWhenNull(): void {
		$this->assertTrue( $this->tool->callOption( array( 'x' => null ), 'x' )->isNone() );
	}

	public function testOptionParamNoneWhenEmptyString(): void {
		$this->assertTrue( $this->tool->callOption( array( 'x' => '' ), 'x' )->isNone() );
	}

	public function testOptionParamSomeForFalsyButPresentValues(): void {
		$this->assertSame( 0, $this->tool->callOption( array( 'x' => 0 ), 'x' )->toNullable() );
		$this->assertSame( false, $this->tool->callOption( array( 'x' => false ), 'x' )->toNullable() );
		$this->assertSame( 'v', $this->tool->callOption( array( 'x' => 'v' ), 'x' )->toNullable() );
	}

	// ─── authorize ──────────────────────────────────────────────────

	public function testAuthorizePassesForLoggedInUser(): void {
		$this->expectNotToPerformAssertions();

		$this->tool->callAuthorize( array( 'user_id' => 7 ), 'edit_posts' );
	}

	public function testAuthorizeThrowsForAnonymous(): void {
		try {
			$this->tool->callAuthorize( array(), 'manage_options' );
			$this->fail( 'Expected AccessDeniedException.' );
		} catch ( AccessDeniedException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'manage_options', $e->capability );
			$this->assertSame( 0, $e->userId );
		}
	}

	// ─── validationFailure ──────────────────────────────────────────

	public function testValidationFailureMirrorsFactoryPayload(): void {
		$result = $this->tool->callValidationFailure( 'Bad.', array( 'x' => array( 'Required.' ) ) );

		$this->assertTrue( $result->isFailure() );
		$this->assertSame( 'validation_failed', $result->error?->code );
		$this->assertSame(
			array(
				'errors' => array( 'x' => array( 'Required.' ) ),
				'status' => 422,
			),
			$result->error?->data
		);
	}

	// ─── unwrapResult ───────────────────────────────────────────────

	public function testUnwrapResultSuccessProducesEnvelope(): void {
		$unwrapped = $this->tool->callUnwrap( ToolResult::success( 'ok', array( 'n' => 1 ) ) );

		$this->assertSame(
			array(
				'success' => true,
				'message' => 'ok',
				'data'    => array( 'n' => 1 ),
			),
			$unwrapped
		);
	}

	public function testUnwrapResultFailureProducesPlatformError(): void {
		$unwrapped = $this->tool->callUnwrap(
			ToolResult::failure( new DomainError( 'nope', 'It failed.', array( 'a' => 1 ) ) )
		);

		$this->assertTrue( $this->errors->isError( $unwrapped ) );
		$this->assertSame(
			array(
				'code'    => 'nope',
				'message' => 'It failed.',
				'data'    => array( 'a' => 1 ),
			),
			$this->errors->normalize( $unwrapped )
		);
	}

	// ─── objectParam ────────────────────────────────────────────────

	public function testObjectParamReturnsStringKeyedMap(): void {
		$this->assertSame(
			array(
				'a' => 1,
				'b' => 'two',
			),
			$this->tool->callObjectParam( array( 'x' => array( 'a' => 1, 'b' => 'two' ) ), 'x' )
		);
	}

	public function testObjectParamFallsBackForListValues(): void {
		$this->assertSame(
			array( 'default' => true ),
			$this->tool->callObjectParam( array( 'x' => array( 'a', 'b' ) ), 'x', array( 'default' => true ) )
		);
	}

	public function testObjectParamFallsBackForMissingKey(): void {
		$this->assertSame( array(), $this->tool->callObjectParam( array(), 'x' ) );
	}

	// ─── stringListParam ────────────────────────────────────────────

	public function testStringListParamCastsScalars(): void {
		$this->assertSame(
			array( 'a', '2', '1' ),
			$this->tool->callStringListParam( array( 'x' => array( 'a', 2, 1.0 ) ), 'x' )
		);
	}

	public function testStringListParamDropsNonScalars(): void {
		$this->assertSame(
			array( 'a', 'b' ),
			$this->tool->callStringListParam( array( 'x' => array( 'a', array( 'nested' ), 'b', null ) ), 'x' )
		);
	}

	public function testStringListParamEmptyWhenMissing(): void {
		$this->assertSame( array(), $this->tool->callStringListParam( array(), 'x' ) );
	}
}
