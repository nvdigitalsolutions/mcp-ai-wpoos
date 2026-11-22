<?php

/**
 * Tests for the chat-profile value object.
 *
 * Two behaviours carry the security weight and are pinned explicitly: the
 * total reader falls back to `write` (a typo must not lock a deployment out),
 * and the validating reader refuses an unknown slug (a typo must not widen a
 * grant).
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Security;

use Nvoos\Core\Domain\Security\ChatProfile;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use PHPUnit\Framework\TestCase;

final class ChatProfileTest extends TestCase
{
    public function test_the_write_profile_gates_nothing(): void
    {
        $profile = ChatProfile::write();

        $this->assertFalse($profile->isRestrictive());
        $this->assertSame(array(), $profile->gatedFlags());
        $this->assertSame(ChatProfile::WRITE, $profile->slug());
    }

    /**
     * The gated set is the shared vocabulary, not a second copy of it.
     *
     * `ReadOnlyToolGuard` already defaults to the same constant, so a profile
     * and the deployment-wide guard cannot disagree about what "read-only"
     * means — which is the drift this assertion exists to catch.
     */
    public function test_the_read_only_profile_uses_the_shared_flag_vocabulary(): void
    {
        $profile = ChatProfile::readOnly();

        $this->assertTrue($profile->isRestrictive());
        $this->assertSame(CapabilityFlagVocabulary::READ_ONLY_GATED, $profile->gatedFlags());
        $this->assertContains('write', $profile->gatedFlags());
        $this->assertContains('destructive', $profile->gatedFlags());
    }

    public function test_the_total_reader_falls_back_to_write(): void
    {
        $this->assertSame(ChatProfile::WRITE, ChatProfile::fromSlug('not_a_profile')->slug());
        $this->assertSame(ChatProfile::WRITE, ChatProfile::fromSlug('')->slug());
        $this->assertSame(ChatProfile::WRITE, ChatProfile::fromSlug(null)->slug());
    }

    public function test_the_validating_reader_refuses_an_unknown_slug(): void
    {
        $this->assertNull(ChatProfile::tryFromSlug('not_a_profile'));
        $this->assertNull(ChatProfile::tryFromSlug(''));
        $this->assertNull(ChatProfile::tryFromSlug(null));
        $this->assertNull(ChatProfile::tryFromSlug('   '));
    }

    public function test_slug_matching_is_case_and_space_tolerant(): void
    {
        $this->assertSame(ChatProfile::READ_ONLY, ChatProfile::fromSlug('  Read-Only ')->slug());
        $this->assertSame(ChatProfile::WRITE, ChatProfile::fromSlug('WRITE')->slug());
    }

    /**
     * Leaving a restrictive profile is the privileged direction.
     *
     * The asymmetry is what stops a downgraded user from calling the same
     * setter to elevate themselves back.
     */
    public function test_only_the_non_restrictive_profile_needs_privilege(): void
    {
        $this->assertTrue(ChatProfile::write()->requiresPrivilegeToSelect());
        $this->assertFalse(ChatProfile::readOnly()->requiresPrivilegeToSelect());
    }

    public function test_the_permissive_profile_has_no_prompt_hint(): void
    {
        $this->assertSame('', ChatProfile::write()->promptHint());
    }

    /**
     * The hint states the desired behaviour, not just the forbidden one.
     *
     * Anthropic's and OpenAI's prompting guidance both say a negation makes the
     * model hold the forbidden action in mind; the positive form is stronger.
     * This is the shape WordPress's filter does not use, and the difference is
     * deliberate.
     */
    public function test_the_restrictive_hint_states_the_mode_and_the_alternative(): void
    {
        $hint = ChatProfile::readOnly()->promptHint();

        $this->assertStringContainsString('Read-only mode', $hint);
        $this->assertStringContainsString('blocked at the execution layer', $hint);
        $this->assertStringContainsString('describe the change', $hint);
        $this->assertStringContainsString('A blocked call is final', $hint);
        $this->assertStringNotContainsString('Do not', $hint);
    }

    public function test_the_catalogue_lists_both_profiles_by_slug(): void
    {
        $catalogue = ChatProfile::catalogue();

        $this->assertSame(array(ChatProfile::WRITE, ChatProfile::READ_ONLY), array_keys($catalogue));
        $this->assertSame($catalogue, ChatProfile::catalogue(), 'The catalogue is built once.');
    }

    public function test_serialisation_matches_the_wordpress_shape(): void
    {
        $this->assertSame(
            array(
                'slug'        => ChatProfile::READ_ONLY,
                'label'       => 'Read-only',
                'description' => 'Reads allowed. Writes, state changes and destructive actions are blocked.',
                'selectable'  => false,
                'is_default'  => false,
            ),
            ChatProfile::readOnly()->toArray(false)
        );

        $this->assertTrue(ChatProfile::write()->toArray()['is_default']);
    }
}
