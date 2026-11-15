<?php

/**
 * Tests for the necessity guard.
 *
 * The guard is the enforcement half of the necessity gate, so these tests pin
 * the mapping rather than the matrix: which verdicts become a denial, and
 * which are deliberately let through.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\NecessityGuard;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use PHPUnit\Framework\TestCase;

final class NecessityGuardTest extends TestCase
{
    /**
     * @param list<string> $flags
     */
    private function guard(array $flags, bool $enabled = true, bool $known = true): NecessityGuard
    {
        $tool = new class ($flags) implements ToolInterface, ToolCapabilityFlagsInterface {
            /**
             * @param list<string> $flags
             */
            public function __construct(private readonly array $flags)
            {
            }

            public function getSlug(): string
            {
                return 'stub';
            }

            public function getName(): string
            {
                return 'stub';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getParametersSchema(): array
            {
                return array();
            }

            public function getRequiredCapability(): string
            {
                return '';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return array( 'success' => true );
            }

            public function getCapabilityFlags(): array
            {
                return $this->flags;
            }
        };

        $resolver = new class ($tool, $known) implements ToolResolverInterface {
            public function __construct(private readonly ToolInterface $tool, private readonly bool $known)
            {
            }

            public function get(string $slug): ?ToolInterface
            {
                return $this->known ? $this->tool : null;
            }

            public function has(string $slug): bool
            {
                return $this->known;
            }
        };

        return new NecessityGuard($resolver, $enabled);
    }

    public function test_a_safe_tool_is_allowed(): void
    {
        $this->assertNull($this->guard(array('read-only'))->evaluate('stub', array(), array()));
    }

    public function test_a_helpful_write_is_allowed(): void
    {
        $guard = $this->guard(array('write', 'state-changing'));

        $this->assertNull($guard->evaluate('create_post', array('title' => 'x'), array()));
    }

    public function test_an_overeager_permanent_action_is_denied(): void
    {
        $guard = $this->guard(array('irreversible'));

        $reason = $guard->evaluate('delete_post', array(), array());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('necessity gate', $reason);
        $this->assertStringContainsString('unnecessary and irreversible', $reason);
    }

    /**
     * No approval service is wired on this runtime, so an approval verdict has
     * to fail closed — the same thing the registry's own `ask` path does.
     */
    public function test_an_approval_verdict_fails_closed(): void
    {
        $guard = $this->guard(array('external-communication'));

        $reason = $guard->evaluate('send_email', array('to' => 'a@b.c'), array());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('requires human approval', $reason);
    }

    /**
     * `skip` means "unnecessary but harmless". Denying it would hand the model
     * an error to recover from, which is worse than letting a read-only call
     * run, so the guard allows it.
     */
    public function test_a_skip_verdict_is_allowed_through(): void
    {
        $guard = $this->guard(array('write', 'state-changing', 'reversible'));

        $this->assertNull($guard->evaluate('delete_post', array(), array()));
    }

    public function test_a_disabled_guard_allows_everything(): void
    {
        $guard = $this->guard(array('irreversible'), false);

        $this->assertNull($guard->evaluate('delete_post', array(), array()));
    }

    public function test_an_unknown_slug_is_left_to_the_registry(): void
    {
        $guard = $this->guard(array('irreversible'), true, false);

        $this->assertNull($guard->evaluate('not_registered', array(), array()));
    }
}
