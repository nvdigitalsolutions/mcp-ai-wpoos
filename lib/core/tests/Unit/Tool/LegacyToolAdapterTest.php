<?php
/**
 * Tests for LegacyToolAdapter — the anti-corruption layer exposing
 * WP_MCP_AI-style tools to the framework-agnostic OOS engine.
 *
 * The adapter is duck-typed (the legacy interface is ABSPATH-guarded), so
 * these tests use a fake legacy tool with the same method surface. The
 * adapter file is required directly because the wordpress-adapter package
 * has no standalone test harness yet.
 *
 * A minimal global WP_Error stub is defined here so the WP_Error
 * normalization path can be exercised without WordPress.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

// A minimal global WP_Error stand-in for standalone (non-WordPress) runs.
// The adapter checks `instanceof \WP_Error`, which resolves to this stub.
namespace {
	if ( ! \class_exists( 'WP_Error' ) ) {
		/**
		 * Minimal WP_Error stand-in for standalone test runs.
		 */
		class WP_Error {
			public function __construct(
				private string $code = '',
				private string $message = '',
				private mixed $data = null,
			) {}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}
}

namespace Nvoos\Core\Tests\Unit\Tool {

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use PHPUnit\Framework\TestCase;

// The adapter lives in a separate package without its own test suite yet.
require_once dirname( __DIR__, 4 ) . '/wordpress-adapter/src/Tool/LegacyToolAdapter.php';

final class LegacyToolAdapterTest extends TestCase {

	private ErrorFactoryInterface $errors;

	/** @var \stdClass */
	private object $legacyState;

	protected function setUp(): void {
		$this->errors = $this->createMock( ErrorFactoryInterface::class );

		$this->legacyState        = new \stdClass();
		$this->legacyState->runs  = 0;
		$this->legacyState->context = array();
	}

	/**
	 * A duck-typed fake with the WP_MCP_AI_Tool_Interface method surface.
	 */
	private function legacyTool( ?array $schema = null, mixed $executeResult = null ): object {
		$state  = $this->legacyState;
		$errors = $this->errors;

		return new class( $state, $errors, $schema, $executeResult ) {
			public function __construct(
				private readonly \stdClass $state,
				private readonly ErrorFactoryInterface $errors,
				private readonly ?array $schema,
				private readonly mixed $executeResult,
			) {}

			public function get_slug(): string {
				return 'legacy_probe';
			}

			public function get_name(): string {
				return 'Legacy Probe';
			}

			public function get_description(): string {
				return 'A legacy tool wrapped for the OOS engine.';
			}

			public function get_parameters_schema(): array {
				return $this->schema ?? array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
				);
			}

			public function get_required_capability(): string {
				return 'edit_posts';
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				++$this->state->runs;
				$this->state->context = $context;

				if ( null !== $this->executeResult ) {
					return $this->executeResult;
				}

				return array( 'success' => true, 'message' => 'legacy ran', 'data' => array() );
			}
		};
	}

	private function adapter( object $legacy ): \Nvoos\WordPress\Tool\LegacyToolAdapter {
		return new \Nvoos\WordPress\Tool\LegacyToolAdapter( $legacy, $this->errors );
	}

	public function testMapsIdentityMethods(): void {
		$adapter = $this->adapter( $this->legacyTool() );

		$this->assertSame( 'legacy_probe', $adapter->getSlug() );
		$this->assertSame( 'Legacy Probe', $adapter->getName() );
		$this->assertSame( 'A legacy tool wrapped for the OOS engine.', $adapter->getDescription() );
		$this->assertSame( 'edit_posts', $adapter->getRequiredCapability() );
	}

	public function testSchemaDefaultsToOpenObject(): void {
		// Empty schemas must not leak a type-less root to providers.
		$adapter = $this->adapter( $this->legacyTool( array() ) );

		$schema = $adapter->getParametersSchema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array(), $schema['properties'] );
	}

	public function testExecutePassesThroughSuccessEnvelope(): void {
		$adapter = $this->adapter( $this->legacyTool() );

		$result = $adapter->execute( array( 'text' => 'hi' ) );

		$this->assertSame( 'legacy ran', $result['message'] );
		$this->assertSame( 1, $this->legacyState->runs );
	}

	public function testExecuteEnrichesContextWithEndpointDefaults(): void {
		$adapter = $this->adapter( $this->legacyTool() );

		$adapter->execute( array(), array( 'user_id' => 7 ) );

		$this->assertSame( 7, $this->legacyState->context['user_id'] );
		$this->assertSame( 'chat', $this->legacyState->context['endpoint'] );
		$this->assertSame( 'oos_engine', $this->legacyState->context['source'] );
	}

	public function testWpErrorNormalizesToFrameworkError(): void {
		$this->errors->method( 'create' )
			->with( 'legacy_failed', 'Legacy tool failed.' )
			->willReturn( array( 'success' => false, 'error' => array( 'code' => 'legacy_failed', 'message' => 'Legacy tool failed.', 'data' => array() ) ) );

		$adapter = $this->adapter(
			$this->legacyTool( null, new \WP_Error( 'legacy_failed', 'Legacy tool failed.' ) )
		);

		$result = $adapter->execute();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'legacy_failed', $result['error']['code'] );
	}

	// ─── Write-class classification (shadow suppression) ───────────

	public function testWriteFlagsMakeWriteClass(): void {
		$legacy = new class() {
			public function get_slug(): string {
				return 'flagged_write';
			}

			public function get_required_capability(): string {
				return 'read';
			}

			public function get_capability_flags(): array {
				return array( 'read-only', 'state-changing' );
			}
		};

		$this->assertTrue( $this->adapter( $legacy )->isWriteClass() );
	}

	public function testReadOnlyFlagsMakeReadClass(): void {
		$legacy = new class() {
			public function get_slug(): string {
				return 'flagged_read';
			}

			public function get_required_capability(): string {
				return 'manage_options';
			}

			public function get_capability_flags(): array {
				return array( 'read-only', 'cacheable' );
			}
		};

		$this->assertFalse( $this->adapter( $legacy )->isWriteClass() );
	}

	public function testCapabilityFallbackClassifiesWriteCapabilitiesAsWrite(): void {
		// No capability flags → the required capability decides.
		$this->assertTrue( $this->adapter( $this->legacyTool() )->isWriteClass() );
	}

	public function testCapabilityFallbackClassifiesReadAsRead(): void {
		$legacy = new class() {
			public function get_slug(): string {
				return 'read_only_cap';
			}

			public function get_required_capability(): string {
				return 'read';
			}
		};

		$this->assertFalse( $this->adapter( $legacy )->isWriteClass() );
	}
}

}
