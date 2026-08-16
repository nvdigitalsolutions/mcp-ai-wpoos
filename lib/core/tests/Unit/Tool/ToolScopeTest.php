<?php
/**
 * Tests for ToolScope — scoped visibility with shadowing and
 * restriction intersection over the global registry.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Tool\ToolScope;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\ValueObject\ToolRestriction;
use Nvoos\Core\Tests\Unit\Support\InMemoryDispatcher;
use PHPUnit\Framework\TestCase;

final class ToolScopeTest extends TestCase {

	private ToolRegistry $registry;

	protected function setUp(): void {
		$errors         = $this->createMock( ErrorFactoryInterface::class );
		$this->registry = new ToolRegistry( new InMemoryDispatcher(), $errors );

		$this->registry->register( $this->tool( 'read_one' ) );
		$this->registry->register( $this->tool( 'write_one' ) );
		$this->registry->register( $this->tool( 'read_two' ) );
	}

	private function tool( string $slug ): ToolInterface {
		return new class( $slug ) implements ToolInterface {
			public function __construct( private readonly string $slug ) {}

			public function getSlug(): string {
				return $this->slug;
			}

			public function getName(): string {
				return $this->slug;
			}

			public function getDescription(): string {
				return $this->slug . ' description.';
			}

			public function getParametersSchema(): array {
				return array( 'type' => 'object', 'properties' => array() );
			}

			public function getRequiredCapability(): string {
				return '';
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				return array( 'success' => true, 'message' => $this->slug, 'data' => array() );
			}
		};
	}

	public function testScopeSeesAllInheritedToolsByDefault(): void {
		$scope = $this->registry->createScope();

		$this->assertNotNull( $scope->get( 'read_one' ) );
		$this->assertTrue( $scope->has( 'write_one' ) );
		$this->assertCount( 3, $scope->schemas() );
	}

	public function testDenyListHidesInheritedTool(): void {
		$scope = $this->registry->createScope();
		$scope->restrict( ToolRestriction::denyList( array( 'write_one' ) ) );

		$this->assertNull( $scope->get( 'write_one' ), 'A denied tool reads as absent.' );
		$this->assertFalse( $scope->has( 'write_one' ) );
		$this->assertNotNull( $scope->get( 'read_one' ) );
	}

	public function testAllowListKeepsOnlyListedTools(): void {
		$scope = $this->registry->createScope();
		$scope->restrict( ToolRestriction::allowList( array( 'read_one' ) ) );

		$this->assertNotNull( $scope->get( 'read_one' ) );
		$this->assertNull( $scope->get( 'read_two' ) );
		$this->assertNull( $scope->get( 'write_one' ) );
	}

	public function testRestrictionsIntersectAcrossScopeChain(): void {
		$parent = $this->registry->createScope();
		$parent->restrict( ToolRestriction::denyList( array( 'write_one' ) ) );

		$child = new ToolScope( $parent );
		$child->restrict( ToolRestriction::allowList( array( 'read_one', 'write_one' ) ) );

		// Both restrictions apply: write_one passes the allow-list but
		// fails the parent's deny-list.
		$this->assertNotNull( $child->get( 'read_one' ) );
		$this->assertNull( $child->get( 'write_one' ) );
		$this->assertNull( $child->get( 'read_two' ) );
	}

	public function testScopeLocalRegistrationShadowsInheritedTool(): void {
		$scope = $this->registry->createScope();
		$scope->restrict( ToolRestriction::denyList( array( 'read_one' ) ) );

		$scope->register( $this->tool( 'read_one' ) );

		// Scope-local tools are restriction-exempt and shadow the global.
		$this->assertNotNull( $scope->get( 'read_one' ) );
		$this->assertTrue( $scope->has( 'read_one' ) );
	}

	public function testSchemasProjectVisibleToolsOnly(): void {
		$scope = $this->registry->createScope();
		$scope->restrict( ToolRestriction::allowList( array( 'read_one' ) ) );
		$scope->register( $this->tool( 'scoped_extra' ) );

		$schemas = $scope->schemas();

		$names = array_map(
			static fn( array $definition ): string => $definition['function']['name'],
			$schemas,
		);

		$this->assertSame( array( 'read_one', 'scoped_extra' ), $names );
	}

	public function testGenericResolverParentUsesSeedUniverse(): void {
		// A non-enumerable resolver stands in for the Pro legacy-tool
		// resolver: it can resolve slugs but cannot enumerate them.
		$registry = $this->registry;

		$resolver = new class( $registry, array( 'read_one', 'write_one' ) ) implements ToolResolverInterface {
			/**
			 * @param string[] $slugs
			 */
			public function __construct(
				private readonly ToolRegistry $registry,
				private readonly array $slugs,
			) {}

			public function get( string $slug ): ?ToolInterface {
				return $this->registry->get( $slug );
			}

			public function has( string $slug ): bool {
				return \in_array( $slug, $this->slugs, true );
			}
		};

		$scope = new ToolScope( $resolver, array( 'read_one', 'write_one', 'read_two' ) );
		$scope->restrict( ToolRestriction::denyList( array( 'write_one' ) ) );

		$this->assertNotNull( $scope->get( 'read_one' ) );
		$this->assertNull( $scope->get( 'write_one' ), 'Deny restriction applies to seeded slugs.' );
		$this->assertNotNull( $scope->get( 'read_two' ), 'Seeded slugs outside the resolver still resolve when admitted.' );

		$names = array_map(
			static fn( array $definition ): string => $definition['function']['name'],
			$scope->schemas(),
		);

		$this->assertSame( array( 'read_one', 'read_two' ), $names );
	}
}
