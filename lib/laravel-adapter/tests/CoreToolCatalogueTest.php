<?php
/**
 * Tests for the self-contained core tool catalogue.
 *
 * Guards the two failure modes this catalogue exists to avoid: listing a tool
 * that actually needs a service (which would fatal at boot) and registering a
 * duplicate slug.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Tool\CoreToolCatalogue;

final class CoreToolCatalogueTest extends TestCase {

	/**
	 * Every listed class must instantiate with the error factory alone.
	 */
	public function testEveryListedToolIsSelfContained(): void {
		$tools = CoreToolCatalogue::build( new ErrorFactory() );

		$this->assertGreaterThanOrEqual( 20, count( $tools ), 'The self-contained set should not silently shrink.' );

		$slugs = array_map(
			static fn ( $tool ): string => $tool->getSlug(),
			$tools
		);

		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ), 'Tool slugs must be unique.' );

		foreach ( array( 'base64', 'math_eval', 'parse_csv', 'validate_json', 'format_date', 'color_convert' ) as $expected ) {
			$this->assertContains( $expected, $slugs );
		}
	}

	/**
	 * Every listed tool must expose the minimum surface the registry needs.
	 */
	public function testEachToolDeclaresItsSurface(): void {
		foreach ( CoreToolCatalogue::build( new ErrorFactory() ) as $tool ) {
			$this->assertNotSame( '', $tool->getSlug() );
			$this->assertNotSame( '', $tool->getName() );
			$this->assertNotSame( '', $tool->getDescription() );
			$this->assertIsArray( $tool->getParametersSchema() );
		}
	}

	/**
	 * The service provider seeds the registry from the catalogue.
	 */
	public function testProviderRegistersTheCatalogue(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'base64' ) );
		$this->assertTrue( $registry->has( 'color_convert' ) );
		$this->assertGreaterThanOrEqual( 20, count( $registry->all() ) );

		// Re-booting must not double-register.
		$this->assertSame( count( $registry->all() ), count( array_unique( array_keys( $registry->all() ) ) ) );
	}
}
