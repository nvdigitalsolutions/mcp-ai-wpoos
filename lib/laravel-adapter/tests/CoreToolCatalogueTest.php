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
	 * The 090-D cluster-1 email tool arrives without a compiler.
	 *
	 * `generate_email_template` is the first ported tool with an *optional*
	 * collaborator: the catalogue contract is "constructs with the error factory
	 * alone", so this pins that the MJML compiler really is optional and that
	 * the tool still produces markup.
	 */
	public function testEmailTemplateRegistersWithoutACompiler(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'generate_email_template' ) );

		$tool = $registry->get( 'generate_email_template' );

		$result = $tool->execute(
			array(
				'components'    => array( array( 'type' => 'text', 'content' => 'Hello' ) ),
				'output_format' => 'mjml',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '<mj-text>Hello</mj-text>', $result['data']['mjml'] );
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
