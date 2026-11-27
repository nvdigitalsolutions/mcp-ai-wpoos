<?php

/**
 * Tests for the capability-flag vocabulary.
 *
 * These pin the three things the vocabulary exists to guarantee: alternate
 * spellings resolve to one word, a read-only remote lookup is not treated as an
 * outbound mutation, and the read-only gate list matches the WordPress chat
 * profile rather than drifting again.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityFlagVocabularyTest extends TestCase
{
    /**
     * @param array<int, mixed>  $flags
     * @param list<string>       $expected
     */
    #[DataProvider('normaliseCases')]
    public function test_flags_are_normalised(array $flags, array $expected): void
    {
        $this->assertSame($expected, CapabilityFlagVocabulary::normalise($flags));
    }

    public static function normaliseCases(): array
    {
        return array(
            'case and whitespace'      => array(array(' Write ', 'READ-ONLY'), array('write', 'read-only')),
            'underscore state-changing' => array(array('state_changing'), array('state-changing')),
            'underscore side effect'   => array(array('external_side_effect'), array('external-communication')),
            'underscore external api'  => array(array('external_api'), array('external-api')),
            'read means read-only'      => array(array('read'), array('read-only')),
            'database-read means read-only' => array(array('database-read'), array('read-only')),
            'duplicates collapse'      => array(array('write', 'Write', 'WRITE'), array('write')),
            'non-strings are dropped'  => array(array('write', null, 7, array('read-only')), array('write')),
            'empty values are dropped' => array(array('', '  ', 'write'), array('write')),
            'order is preserved'       => array(array('write', 'state_changing', 'read-only'), array('write', 'state-changing', 'read-only')),
            'already canonical'        => array(array('read-only', 'cacheable'), array('read-only', 'cacheable')),
        );
    }

    public function test_has_resolves_aliases_and_case(): void
    {
        $this->assertTrue(CapabilityFlagVocabulary::has(array('state_changing'), 'state-changing'));
        $this->assertTrue(CapabilityFlagVocabulary::has(array('State-Changing'), 'state-changing'));
        $this->assertTrue(CapabilityFlagVocabulary::has(array('external_side_effect'), 'external-communication'));
        $this->assertFalse(CapabilityFlagVocabulary::has(array('write'), 'read-only'));
    }

    public function test_has_any_matches_either_vocabulary(): void
    {
        $this->assertTrue(
            CapabilityFlagVocabulary::hasAny(array('state_changing'), array('write', 'state-changing'))
        );
        $this->assertFalse(
            CapabilityFlagVocabulary::hasAny(array('cacheable'), array('write', 'state-changing'))
        );
    }

    /**
     * @param array<int, string> $flags
     */
    #[DataProvider('outboundMutationCases')]
    public function test_outbound_mutation_is_narrower_than_outbound(array $flags, bool $expected): void
    {
        $this->assertSame($expected, CapabilityFlagVocabulary::hasOutboundMutation($flags));
    }

    public static function outboundMutationCases(): array
    {
        return array(
            'nothing declared'                     => array(array(), false),
            'read-only'                            => array(array('read-only'), false),
            // 186 corpus tools sit here: a remote lookup is a read.
            'read-only external lookup'            => array(array('read-only', 'external-api'), false),
            'read-only network lookup'             => array(array('read-only', 'network-dependent'), false),
            'write alone'                          => array(array('write'), false),
            'external api write'                   => array(array('external-api', 'write'), true),
            'network dependent state change'       => array(array('network-dependent', 'state-changing'), true),
            'underscore state change over network' => array(array('external_api', 'state_changing'), true),
            'explicit external communication'      => array(array('external-communication'), true),
            'underscore side effect alias'         => array(array('external_side_effect'), true),
        );
    }

    public function test_the_read_only_gate_list_mirrors_wordpress(): void
    {
        $this->assertSame(
            array(
                'destructive',
                'data-destruction',
                'irreversible',
                'state-changing',
                'write',
                'financial-impact',
                'access-control-change',
                'mass-email',
            ),
            CapabilityFlagVocabulary::READ_ONLY_GATED
        );

        $this->assertSame(CapabilityFlagVocabulary::READ_ONLY_GATED, CapabilityFlagVocabulary::readOnlyGated());
    }
}
