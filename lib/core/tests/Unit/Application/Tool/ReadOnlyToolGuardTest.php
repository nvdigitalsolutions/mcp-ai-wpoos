<?php
/**
 * Tests for the read-only tool guard.
 *
 * The guard is the last pre-dispatch policy, so these tests pin the three
 * behaviours that matter: an allowlisted slug always runs, a flagged tool is
 * denied, and an undeclared tool is denied unless the operator opts out.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\ReadOnlyToolGuard;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use PHPUnit\Framework\TestCase;

final class ReadOnlyToolGuardTest extends TestCase {

	public function test_a_flagged_tool_is_denied(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver(
				array(
					'update_post' => array( 'write', 'async' ),
				)
			)
		);

		$reason = $guard->evaluate( 'update_post', array(), array() );

		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'read-only profile', $reason );
		$this->assertStringContainsString( 'write', $reason );
	}

	public function test_a_read_only_tool_is_allowed(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver(
				array(
					'get_post' => array( 'read', 'cacheable' ),
				)
			)
		);

		$this->assertNull( $guard->evaluate( 'get_post', array(), array() ) );
	}

	public function test_an_allowlisted_slug_is_allowed_whatever_its_flags(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver(
				array(
					'update_post' => array( 'destructive' ),
				)
			),
			allowedSlugs: array( 'update_post' ),
		);

		$this->assertNull( $guard->evaluate( 'update_post', array(), array() ) );
	}

	public function test_an_undeclared_tool_is_denied_by_default(): void {
		$guard = new ReadOnlyToolGuard( self::resolver( array( 'legacy_tool' => null ) ) );

		$reason = $guard->evaluate( 'legacy_tool', array(), array() );

		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'declares no capability flags', $reason );
	}

	public function test_an_undeclared_tool_can_be_allowed_explicitly(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver( array( 'legacy_tool' => null ) ),
			blockUndeclared: false,
		);

		$this->assertNull( $guard->evaluate( 'legacy_tool', array(), array() ) );
	}

	public function test_an_unknown_slug_is_left_to_the_registry(): void {
		$guard = new ReadOnlyToolGuard( self::resolver( array() ) );

		$this->assertNull( $guard->evaluate( 'does_not_exist', array(), array() ) );
	}

	public function test_flags_are_matched_case_insensitively(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver(
				array(
					'update_post' => array( 'Write' ),
				)
			)
		);

		$this->assertNotNull( $guard->evaluate( 'update_post', array(), array() ) );
	}

	public function test_a_custom_gated_flag_set_replaces_the_default(): void {
		$guard = new ReadOnlyToolGuard(
			self::resolver(
				array(
					'update_post' => array( 'write' ),
					'run_shell'   => array( 'destructive' ),
				)
			),
			gatedFlags: array( 'destructive' ),
		);

		$this->assertNull( $guard->evaluate( 'update_post', array(), array() ) );
		$this->assertNotNull( $guard->evaluate( 'run_shell', array(), array() ) );
	}

	/**
	 * Build a resolver over slug → flags (null = tool without the flags interface).
	 *
	 * @param array<string, list<string>|null> $flagsBySlug Flag map.
	 */
	private static function resolver( array $flagsBySlug ): ToolResolverInterface {
		$tools = array();

		foreach ( $flagsBySlug as $slug => $flags ) {
			$tools[ $slug ] = null === $flags
				? new ReadOnlyGuardUndeclaredTool( $slug )
				: new ReadOnlyGuardFlaggedTool( $slug, $flags );
		}

		return new ReadOnlyGuardResolver( $tools );
	}
}

/**
 * Resolver stub.
 *
 * @internal Test double.
 */
final class ReadOnlyGuardResolver implements ToolResolverInterface {

	/**
	 * @param array<string, ToolInterface> $tools Tools by slug.
	 */
	public function __construct( private readonly array $tools ) {}

	public function get( string $slug ): ?ToolInterface {
		return $this->tools[ $slug ] ?? null;
	}

	public function has( string $slug ): bool {
		return isset( $this->tools[ $slug ] );
	}
}

/**
 * Tool stub that declares capability flags.
 *
 * @internal Test double.
 */
final class ReadOnlyGuardFlaggedTool implements ToolInterface, ToolCapabilityFlagsInterface {

	/**
	 * @param list<string> $flags Capability flags.
	 */
	public function __construct( private readonly string $slug, private readonly array $flags ) {}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getName(): string {
		return $this->slug;
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
		return $this->flags;
	}
}

/**
 * Tool stub without the flags interface.
 *
 * @internal Test double.
 */
final class ReadOnlyGuardUndeclaredTool implements ToolInterface {

	public function __construct( private readonly string $slug ) {}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getName(): string {
		return $this->slug;
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
