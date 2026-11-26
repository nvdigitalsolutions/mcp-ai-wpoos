<?php

/**
 * Tests for the chat-profile guard.
 *
 * The guard is the per-principal half of the read-only boundary, so these
 * tests pin the four behaviours that matter: a restrictive profile denies a
 * gated tool, a permissive profile denies nothing, an undeclared tool is
 * denied under a restrictive profile (the MCP pessimistic default), and the
 * profile is resolved from the execution context rather than from anything the
 * caller could have invented.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\ChatProfileGuard;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Security\ChatProfile;
use PHPUnit\Framework\TestCase;

final class ChatProfileGuardTest extends TestCase
{
    public function test_a_gated_tool_is_denied_under_a_restrictive_profile(): void
    {
        $guard = new ChatProfileGuard(
            self::tools(array('update_post' => array('write', 'state-changing'))),
            self::profiles(ChatProfile::readOnly())
        );

        $reason = $guard->evaluate('update_post', array(), array('user_id' => 7));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('read-only profile', $reason);
        $this->assertStringContainsString('write', $reason);
    }

    public function test_a_read_only_tool_is_allowed_under_a_restrictive_profile(): void
    {
        $guard = new ChatProfileGuard(
            self::tools(array('get_post' => array('read', 'cacheable'))),
            self::profiles(ChatProfile::readOnly())
        );

        $this->assertNull($guard->evaluate('get_post', array(), array('user_id' => 7)));
    }

    public function test_a_permissive_profile_denies_nothing(): void
    {
        $guard = new ChatProfileGuard(
            self::tools(array('update_post' => array('write', 'destructive'))),
            self::profiles(ChatProfile::write())
        );

        $this->assertNull($guard->evaluate('update_post', array(), array('user_id' => 7)));
    }

    /**
     * A tool that declares nothing is assumed to write.
     *
     * The MCP specification makes the same assumption when annotations are
     * absent: "the spec assumes the worst until told otherwise."
     */
    public function test_an_undeclared_tool_is_denied_under_a_restrictive_profile(): void
    {
        $guard = new ChatProfileGuard(
            self::tools(array('legacy_tool' => null)),
            self::profiles(ChatProfile::readOnly())
        );

        $reason = $guard->evaluate('legacy_tool', array(), array('user_id' => 7));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('no capability flags', $reason);
    }

    public function test_the_operator_can_opt_out_of_blocking_undeclared_tools(): void
    {
        $guard = new ChatProfileGuard(
            self::tools(array('legacy_tool' => null)),
            self::profiles(ChatProfile::readOnly()),
            blockUndeclared: false
        );

        $this->assertNull($guard->evaluate('legacy_tool', array(), array('user_id' => 7)));
    }

    /**
     * The profile is resolved from the execution context the orchestrator built.
     *
     * This is the trust boundary in one assertion: the guard has no other
     * input, so a client cannot name its own profile.
     */
    public function test_the_profile_is_resolved_from_the_execution_context(): void
    {
        $resolver = self::recordingProfiles(ChatProfile::readOnly());

        $guard = new ChatProfileGuard(self::tools(array()), $resolver);
        $guard->evaluate('get_post', array(), array('user_id' => 42, 'assistant_id' => 9));

        $this->assertSame(array('user_id' => 42, 'assistant_id' => 9), $resolver->lastContext);
    }

    public function test_the_tool_resolver_is_not_consulted_for_a_permissive_profile(): void
    {
        $tools = new ChatProfileGuardResolver(array());

        $guard = new ChatProfileGuard($tools, self::profiles(ChatProfile::write()));
        $guard->evaluate('update_post', array(), array('user_id' => 7));

        $this->assertSame(0, $tools->getCalls);
    }

    /**
     * @param array<string, list<string>|null> $flagsBySlug Flag map.
     */
    private static function tools(array $flagsBySlug): ChatProfileGuardResolver
    {
        $tools = array();

        foreach ($flagsBySlug as $slug => $flags) {
            $tools[ $slug ] = null === $flags
                ? new ChatProfileGuardUndeclaredTool($slug)
                : new ChatProfileGuardFlaggedTool($slug, $flags);
        }

        return new ChatProfileGuardResolver($tools);
    }

    private static function profiles(ChatProfile $profile): ChatProfileResolverInterface
    {
        return new ChatProfileGuardStaticResolver($profile);
    }

    private static function recordingProfiles(ChatProfile $profile): ChatProfileGuardRecordingResolver
    {
        return new ChatProfileGuardRecordingResolver($profile);
    }
}

/**
 * Static profile resolver.
 *
 * @internal Test double.
 */
final class ChatProfileGuardStaticResolver implements ChatProfileResolverInterface
{
    public function __construct(private readonly ChatProfile $profile)
    {
    }

    public function resolve(array $context): ChatProfile
    {
        return $this->profile;
    }

    public function defaultProfile(): ChatProfile
    {
        return ChatProfile::write();
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

/**
 * Recording profile resolver.
 *
 * @internal Test double.
 */
final class ChatProfileGuardRecordingResolver implements ChatProfileResolverInterface
{
    /** @var array<string, mixed> */
    public array $lastContext = array();

    public function __construct(private readonly ChatProfile $profile)
    {
    }

    public function resolve(array $context): ChatProfile
    {
        $this->lastContext = $context;

        return $this->profile;
    }

    public function defaultProfile(): ChatProfile
    {
        return ChatProfile::write();
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

/**
 * Resolver stub that counts lookups.
 *
 * @internal Test double.
 */
final class ChatProfileGuardResolver implements ToolResolverInterface
{
    public int $getCalls = 0;

    /**
     * @param array<string, ToolInterface> $tools Tools by slug.
     */
    public function __construct(private readonly array $tools)
    {
    }

    public function get(string $slug): ?ToolInterface
    {
        ++$this->getCalls;

        return $this->tools[ $slug ] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->tools[ $slug ]);
    }
}

/**
 * Tool stub that declares capability flags.
 *
 * @internal Test double.
 */
final class ChatProfileGuardFlaggedTool implements ToolInterface, ToolCapabilityFlagsInterface
{
    /**
     * @param list<string> $flags Capability flags.
     */
    public function __construct(private readonly string $slug, private readonly array $flags)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->slug;
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
}

/**
 * Tool stub that declares nothing.
 *
 * @internal Test double.
 */
final class ChatProfileGuardUndeclaredTool implements ToolInterface
{
    public function __construct(private readonly string $slug)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->slug;
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
}
