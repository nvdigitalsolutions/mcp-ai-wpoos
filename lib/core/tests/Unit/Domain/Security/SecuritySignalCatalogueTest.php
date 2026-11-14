<?php

/**
 * Tests for the shared security-posture catalogue.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Security;

use Nvoos\Core\Domain\Security\SecuritySignalCatalogue;
use Nvoos\Core\Domain\Security\SecuritySignalDefinition;
use PHPUnit\Framework\TestCase;

final class SecuritySignalCatalogueTest extends TestCase
{
	public function test_every_definition_is_well_formed(): void
    {
		$ids = array();

		foreach (SecuritySignalCatalogue::all() as $definition) {
			$this->assertInstanceOf(SecuritySignalDefinition::class, $definition);
			$this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $definition->id);
			$this->assertNotSame('', $definition->label, "{$definition->id} has no label");
			$this->assertGreaterThanOrEqual(0, $definition->weight, "{$definition->id} has a negative weight");
			$this->assertContains($definition->subtab, array( 'network', 'access', 'audit', 'ai_safety' ));
			$this->assertNotEmpty($definition->runtimes, "{$definition->id} has no runtime");

			foreach ($definition->runtimes as $runtime) {
				$this->assertContains($runtime, array(
					SecuritySignalCatalogue::RUNTIME_WORDPRESS,
					SecuritySignalCatalogue::RUNTIME_LARAVEL,
				));
			}

			$this->assertArrayNotHasKey($definition->id, $ids, "duplicate id {$definition->id}");
			$ids[ $definition->id ] = true;
		}
	}

	public function test_the_catalogue_covers_both_runtimes(): void
    {
		$this->assertCount(27, SecuritySignalCatalogue::all());

		// WordPress keeps its 23 pre-catalogue signals.
		$this->assertCount(23, SecuritySignalCatalogue::forRuntime(SecuritySignalCatalogue::RUNTIME_WORDPRESS));

		// Laravel evaluates the 14 shared controls plus its 4 own ones.
		$this->assertCount(18, SecuritySignalCatalogue::forRuntime(SecuritySignalCatalogue::RUNTIME_LARAVEL));
	}

	public function test_runtime_specific_controls_are_declared_not_hidden(): void
    {
		$wordpress_only = array_map(
			static fn (SecuritySignalDefinition $definition): string => $definition->id,
			SecuritySignalCatalogue::onlyOn(SecuritySignalCatalogue::RUNTIME_WORDPRESS)
		);
		$laravel_only = array_map(
			static fn (SecuritySignalDefinition $definition): string => $definition->id,
			SecuritySignalCatalogue::onlyOn(SecuritySignalCatalogue::RUNTIME_LARAVEL)
		);

		$this->assertContains('root_key_set', $wordpress_only);
		$this->assertContains('prompt_injection_detector', $wordpress_only);
		$this->assertContains('secure_cookies', $laravel_only);
		$this->assertContains('csrf', $laravel_only);

		// The shared core is genuinely shared.
		foreach (array( 'https_active', 'hsts_enabled', 'audit_log_on', 'cors_restricted' ) as $id) {
			$definition = SecuritySignalCatalogue::get($id);
			$this->assertNotNull($definition, $id);
			$this->assertCount(2, $definition->runtimes, "{$id} should apply to both runtimes");
		}
	}

	public function test_lookup_and_applicability(): void
    {
		$definition = SecuritySignalCatalogue::get('hsts_enabled');

		$this->assertNotNull($definition);
		$this->assertSame('network', $definition->subtab);
		$this->assertSame('enable_hsts', $definition->anchor);
		$this->assertTrue($definition->appliesTo(SecuritySignalCatalogue::RUNTIME_LARAVEL));
		$this->assertNull(SecuritySignalCatalogue::get('nope'));
	}

	public function test_the_catalogue_cites_a_standard_for_most_controls(): void
    {
		$with_reference = array_filter(
			SecuritySignalCatalogue::all(),
			static fn (SecuritySignalDefinition $definition): bool => '' !== $definition->reference
		);

		// A handful of internal controls (restriction registry, SSL bypass,
		// licence-sourced provider keys, memory controls) are product-specific.
		$this->assertGreaterThanOrEqual(20, count($with_reference));
	}
}
