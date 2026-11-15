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
	 * The 090-D cluster-1 harness tool registers without a profile provider.
	 *
	 * `scope_memory` is the second ported tool with an optional collaborator.
	 * Laravel has no harness-profile storage, so the tool must still compute
	 * tags and report the documented default for the PII filter.
	 */
	public function testScopeMemoryRegistersWithoutAProfileProvider(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'scope_memory' ) );

		$result = $registry->get( 'scope_memory' )->execute(
			array(
				'assistant_id' => 7,
				'task_class'   => 'math',
				'wing'         => 'client-acme',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			array( 'task_class:math', 'assistant:7', 'wing:client-acme' ),
			$result['data']['tags']
		);
		$this->assertTrue( $result['data']['reserved'] );
		$this->assertTrue( $result['data']['pii_filter'] );
	}

	/**
	 * The 090-D cluster-1 cue catalogue registers with its own library.
	 *
	 * `list_prompt_cues` is the first ported tool that carries its data with
	 * it, so the point here is that Laravel serves the real catalogue, not an
	 * empty one.
	 */
	public function testListPromptCuesServesTheSeededCatalogue(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'list_prompt_cues' ) );

		$result = $registry->get( 'list_prompt_cues' )->execute( array( 'task_class' => 'math' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'chain_of_thought', $result['data']['cues'][0]['slug'] );
	}

	/**
	 * The 090-D cluster-1 reasoning tool registers with pure scoring.
	 */
	public function testEnableReasoningModeScoresWithoutAService(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'enable_reasoning_mode' ) );

		$result = $registry->get( 'enable_reasoning_mode' )->execute(
			array( 'task' => 'What is the capital of France?' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['reasoning_recommended'] );
		$this->assertFalse( $result['data']['reasoning_activated'] );
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
