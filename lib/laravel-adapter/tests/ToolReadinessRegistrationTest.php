<?php
/**
 * Readiness gates for the registered tool set (Proposal 090-M).
 *
 * The registry is now derived from the readiness manifest rather than a hand
 * catalogue, so these tests guard the two ways that derivation can go wrong:
 * a tool that should not be served being served, and a manifest that promises
 * more than the container can build.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

final class ToolReadinessRegistrationTest extends TestCase {

	/**
	 * Every registered slug must be servable according to the manifest.
	 *
	 * This is the gate that makes a hollow tool impossible to ship: promoting a
	 * prompt-builder to `ready` would register it, so the only way in is an
	 * explicit manifest change reviewed as such.
	 */
	public function testEveryRegisteredToolIsServable(): void {
		$manifest = $this->app->make( ToolReadiness::class );
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertGreaterThan( 0, count( $registry->all() ) );

		foreach ( array_keys( $registry->all() ) as $slug ) {
			$this->assertTrue(
				$manifest->isServable( $slug ),
				"Tool '{$slug}' is registered but the manifest does not declare it servable."
			);
		}
	}

	/**
	 * No quarantined tool may reach the registry, under any class.
	 */
	public function testNoQuarantinedToolIsRegistered(): void {
		$manifest = $this->app->make( ToolReadiness::class );
		$registry = $this->app->make( ToolRegistry::class );

		$quarantined = array();
		foreach ( $manifest->all() as $slug => $row ) {
			if ( ToolReadiness::QUARANTINED === ( $row['readiness'] ?? '' ) ) {
				$quarantined[] = $slug;
			}
		}

		$this->assertNotEmpty( $quarantined, 'The quarantine list should not silently empty.' );

		foreach ( $quarantined as $slug ) {
			$this->assertFalse( $registry->has( $slug ), "Quarantined tool '{$slug}' must not be registered." );
		}
	}

	/**
	 * The manifest may not promise a tool the container cannot build.
	 *
	 * A skip is silent at boot by design, so this is where it becomes loud.
	 */
	public function testTheContainerBuildsEveryServableTool(): void {
		$skipped = $this->app->bound( 'nvoos.tools.skipped' ) ? $this->app->make( 'nvoos.tools.skipped' ) : array();

		$this->assertSame(
			array(),
			$skipped,
			'Manifest rows marked servable that the container could not construct: ' . (string) json_encode( $skipped )
		);
	}

	/**
	 * The registered set and the servable set agree.
	 */
	public function testRegisteredSetMatchesTheManifest(): void {
		$manifest = $this->app->make( ToolReadiness::class );
		$registry = $this->app->make( ToolRegistry::class );

		$servable = array_keys( $manifest->servable() );
		$registered = array_keys( $registry->all() );

		sort( $servable );
		sort( $registered );

		$this->assertSame( $servable, $registered );
	}

	/**
	 * A renamed prompt-builder must not answer to the WordPress slug.
	 *
	 * The list is the WordPress slugs that still have **only** a prompt-builder
	 * stand-in, so it shrinks as the real tool lands. `analyze_image` left it when
	 * AnalyzeImageVisionTool shipped (Proposal 090-M §20); the rest are still
	 * unimplemented and must not be reachable by their canonical name.
	 */
	public function testPromptBuildersAreNotReachableUnderTheWordPressSlug(): void {
		$registry = $this->app->make( ToolRegistry::class );

		foreach ( array( 'analyze_video', 'extract_image_text', 'batch_embed_content', 'validate_reasoning_chain' ) as $slug ) {
			$this->assertFalse(
				$registry->has( $slug ),
				"Prompt-builder still answers to the WordPress slug '{$slug}'."
			);
		}

		$this->assertTrue( $registry->has( 'analyze_image_prompt' ) === false, 'Quarantined prompt builders stay unregistered.' );
	}
}
