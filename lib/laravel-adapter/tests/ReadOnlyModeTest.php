<?php
/**
 * Read-only deployment profile wiring tests.
 *
 * Proves the guard is registered through the container (not just constructible)
 * and that a blocked call surfaces the registry's `tool_guarded` envelope.
 * Proposal 090-B, Phase 5.4.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;

final class ReadOnlyModeTest extends TestCase {

	/**
	 * Configure the profile and rebuild the registry so the guard re-registers.
	 *
	 * @param array<string, mixed> $overrides Profile config overrides.
	 */
	private function configure( array $overrides ): void {
		$this->app['config']->set(
			'oos.read_only',
			array_merge(
				array(
					'enabled'          => true,
					'allowed_slugs'    => array(),
					'block_undeclared' => true,
				),
				$overrides
			)
		);

		$this->app->forgetInstance( ToolRegistry::class );
	}

	private function registry(): ToolRegistry {
		$registry = $this->app->make( ToolRegistry::class );
		$registry->register( new ReadOnlyModeWriteTool() );
		$registry->register( new ReadOnlyModeReadTool() );
		$registry->register( new ReadOnlyModeUndeclaredTool() );

		return $registry;
	}

	public function test_a_write_tool_is_blocked_when_the_profile_is_on(): void {
		$this->configure( array( 'enabled' => true ) );

		$result = $this->registry()->execute( 'write_post', array(), array() );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'read-only profile', $result->getMessage() );
	}

	public function test_a_read_tool_still_runs(): void {
		$this->configure( array( 'enabled' => true ) );

		$result = $this->registry()->execute( 'read_post', array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_an_undeclared_tool_is_blocked_by_default(): void {
		$this->configure( array( 'enabled' => true ) );

		$result = $this->registry()->execute( 'legacy_tool', array(), array() );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'declares no capability flags', $result->getMessage() );
	}

	public function test_an_allowlisted_tool_runs_despite_its_flags(): void {
		$this->configure(
			array(
				'enabled'       => true,
				'allowed_slugs' => array( 'write_post' ),
			)
		);

		$result = $this->registry()->execute( 'write_post', array(), array() );

		$this->assertIsArray( $result );
	}

	public function test_nothing_is_blocked_when_the_profile_is_off(): void {
		$this->configure( array( 'enabled' => false ) );

		$registry = $this->registry();

		$this->assertIsArray( $registry->execute( 'write_post', array(), array() ) );
		$this->assertIsArray( $registry->execute( 'legacy_tool', array(), array() ) );
	}
}

/**
 * A write-capable tool stub.
 *
 * @internal Test double.
 */
final class ReadOnlyModeWriteTool implements ToolInterface, ToolCapabilityFlagsInterface {

	public function getSlug(): string {
		return 'write_post';
	}

	public function getName(): string {
		return 'Write post';
	}

	public function getDescription(): string {
		return '';
	}

	public function getParametersSchema(): array {
		return array();
	}

	public function getRequiredCapability(): string {
		return '';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return array( 'success' => true );
	}

	public function getCapabilityFlags(): array {
		return array( 'write', 'async' );
	}
}

/**
 * A read-only tool stub.
 *
 * @internal Test double.
 */
final class ReadOnlyModeReadTool implements ToolInterface, ToolCapabilityFlagsInterface {

	public function getSlug(): string {
		return 'read_post';
	}

	public function getName(): string {
		return 'Read post';
	}

	public function getDescription(): string {
		return '';
	}

	public function getParametersSchema(): array {
		return array();
	}

	public function getRequiredCapability(): string {
		return '';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return array( 'success' => true );
	}

	public function getCapabilityFlags(): array {
		return array( 'read' );
	}
}

/**
 * A tool stub that declares no flags.
 *
 * @internal Test double.
 */
final class ReadOnlyModeUndeclaredTool implements ToolInterface {

	public function getSlug(): string {
		return 'legacy_tool';
	}

	public function getName(): string {
		return 'Legacy tool';
	}

	public function getDescription(): string {
		return '';
	}

	public function getParametersSchema(): array {
		return array();
	}

	public function getRequiredCapability(): string {
		return '';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return array( 'success' => true );
	}
}
