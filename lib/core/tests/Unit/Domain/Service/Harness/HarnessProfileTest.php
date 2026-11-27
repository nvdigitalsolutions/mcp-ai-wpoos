<?php

/**
 * Tests for HarnessProfile — the canonical defaults and sanitiser.
 *
 * These expectations were derived from the WordPress implementation and then
 * confirmed by running both side by side over the same corpus, so a change here
 * that disagrees with `WP_MCP_AI_Harness_Profile` is a real divergence.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessProfileTest extends TestCase
{
    public function test_the_default_profile_turns_every_layer_off(): void
    {
        $defaults = HarnessProfile::defaults();

        $this->assertFalse($defaults['enabled']);
        $this->assertFalse($defaults['reasoning']['enabled']);
        $this->assertFalse($defaults['retrieval']['enabled']);
        $this->assertFalse($defaults['refine']['enabled']);
        $this->assertFalse($defaults['guardrails']['enabled']);
        $this->assertFalse($defaults['trace_capture']['enabled']);
        $this->assertSame('fixed', $defaults['tools']['router']);
        $this->assertSame('general', $defaults['memory']['task_class']);
        $this->assertSame(1.0, $defaults['cost_ceiling_usd']);
    }

    public function test_the_pii_filter_defaults_on(): void
    {
        // Memory scoping is off, but the redaction guard is on — that asymmetry
        // is deliberate upstream and consumers depend on it.
        $this->assertFalse(HarnessProfile::defaults()['memory']['scoped']);
        $this->assertTrue(HarnessProfile::defaults()['memory']['pii_filter']);
    }

    #[DataProvider('junk')]
    public function test_junk_degrades_to_the_default_profile(mixed $raw): void
    {
        $this->assertSame(HarnessProfile::defaults(), HarnessProfile::sanitize($raw));
    }

    public static function junk(): array
    {
        return array(
            'null' => array( null ),
            'scalar' => array( 42 ),
            'empty string' => array( '' ),
            'malformed json' => array( '{not json' ),
            'empty array' => array( array() ),
        );
    }

    public function test_a_json_string_is_decoded(): void
    {
        $profile = HarnessProfile::sanitize('{"enabled":true,"retrieval":{"k":7}}');

        $this->assertTrue($profile['enabled']);
        $this->assertSame(7, $profile['retrieval']['k']);
    }

    #[DataProvider('clamped')]
    public function test_values_are_clamped_to_their_documented_ranges(array $raw, string $path, mixed $expected): void
    {
        $profile = HarnessProfile::sanitize($raw);

        $actual = $profile;
        foreach (explode('.', $path) as $segment) {
            $actual = $actual[ $segment ];
        }

        $this->assertSame($expected, $actual);
    }

    public static function clamped(): array
    {
        return array(
            'reasoning samples high' => array( array( 'reasoning' => array( 'n_samples' => 99 ) ), 'reasoning.n_samples', 8 ),
            'reasoning samples low' => array( array( 'reasoning' => array( 'n_samples' => 0 ) ), 'reasoning.n_samples', 1 ),
            'refine iters high' => array( array( 'refine' => array( 'max_iters' => 99 ) ), 'refine.max_iters', 4 ),
            'retrieval k high' => array( array( 'retrieval' => array( 'k' => 999 ) ), 'retrieval.k', 50 ),
            'retrieval k low' => array( array( 'retrieval' => array( 'k' => -4 ) ), 'retrieval.k', 1 ),
            'retention low' => array( array( 'trace_capture' => array( 'retention_runs' => 1 ) ), 'trace_capture.retention_runs', 10 ),
            'retention high' => array( array( 'trace_capture' => array( 'retention_runs' => 9999 ) ), 'trace_capture.retention_runs', 200 ),
            'cost negative' => array( array( 'cost_ceiling_usd' => -5 ), 'cost_ceiling_usd', 0.0 ),
            'cost huge' => array( array( 'cost_ceiling_usd' => 5000 ), 'cost_ceiling_usd', 1000.0 ),
            'preset weight high' => array( array( 'tools' => array( 'preset_weights' => array( 'commerce' => 12.5 ) ) ), 'tools.preset_weights', array( 'commerce' => 5.0 ) ),
            'preset weight low' => array( array( 'tools' => array( 'preset_weights' => array( 'commerce' => -9 ) ) ), 'tools.preset_weights', array( 'commerce' => -5.0 ) ),
            'preset zero dropped' => array( array( 'tools' => array( 'preset_weights' => array( 'commerce' => 0 ) ) ), 'tools.preset_weights', array() ),
        );
    }

    public function test_enums_fall_back_when_out_of_range(): void
    {
        $profile = HarnessProfile::sanitize(
            array(
                'tools' => array( 'router' => 'random' ),
                'guardrails' => array( 'strictness' => 'paranoid', 'mode' => 'nuke' ),
            )
        );

        $this->assertSame('fixed', $profile['tools']['router']);
        $this->assertSame('medium', $profile['guardrails']['strictness']);
        $this->assertSame('warn', $profile['guardrails']['mode']);
    }

    /**
     * `output_guard` was in the default shape but not in the sanitiser, so
     * every consumer saw `enabled => false` no matter what was stored, and the
     * WordPress guardrail read raw post meta to work around it. A guardrail the
     * profile cannot express is a guardrail a second runtime cannot turn on.
     */
    public function test_the_output_guard_block_survives_the_sanitiser(): void
    {
        $profile = HarnessProfile::sanitize(
            array(
                'output_guard' => array(
                    'enabled' => true,
                    'mode' => 'block',
                    'strictness' => 'high',
                ),
            )
        );

        $this->assertTrue($profile['output_guard']['enabled']);
        $this->assertSame('block', $profile['output_guard']['mode']);
        $this->assertSame('high', $profile['output_guard']['strictness']);
    }

    public function test_output_guard_enums_fall_back_when_out_of_range(): void
    {
        $profile = HarnessProfile::sanitize(
            array(
                'output_guard' => array(
                    'enabled' => true,
                    'mode' => 'nuke',
                    'strictness' => 'paranoid',
                ),
            )
        );

        $this->assertSame('warn', $profile['output_guard']['mode']);
        $this->assertSame('medium', $profile['output_guard']['strictness']);
    }

    public function test_the_output_guard_defaults_off(): void
    {
        $profile = HarnessProfile::sanitize(array( 'enabled' => true ));

        $this->assertFalse($profile['output_guard']['enabled']);
    }

    public function test_slugs_are_lowercased_deduplicated_and_emptied_out(): void
    {
        $profile = HarnessProfile::sanitize(
            array(
                'cues' => array( 'Chain Of Thought', 'failure_modes_first', 'Chain Of Thought', '' ),
                'evals_enabled' => array( 'Smoke', 'smoke', '' ),
                'verifiers' => array( 'Citation Verifier' ),
            )
        );

        $this->assertSame(array( 'chainofthought', 'failure_modes_first' ), $profile['cues']);
        $this->assertSame(array( 'smoke' ), $profile['evals_enabled']);
        $this->assertSame(array( 'citationverifier' ), $profile['verifiers']);
    }

    public function test_allowed_topics_are_tag_stripped_and_deduplicated(): void
    {
        $profile = HarnessProfile::sanitize(
            array( 'guardrails' => array( 'allowed_topics' => array( '  Billing ', 'billing', '<b>Abuse</b>' ) ) )
        );

        $this->assertSame(array( 'Billing', 'billing', 'Abuse' ), $profile['guardrails']['allowed_topics']);
    }

    public function test_an_empty_task_class_falls_back_to_general(): void
    {
        $profile = HarnessProfile::sanitize(array( 'memory' => array( 'task_class' => '' ) ));

        $this->assertSame('general', $profile['memory']['task_class']);
    }

    public function test_the_pii_filter_can_be_turned_off_explicitly(): void
    {
        $profile = HarnessProfile::sanitize(array( 'memory' => array( 'pii_filter' => false ) ));

        $this->assertFalse($profile['memory']['pii_filter']);
    }

    public function test_a_partial_block_keeps_the_other_keys_at_their_defaults(): void
    {
        $profile = HarnessProfile::sanitize(array( 'reasoning' => array( 'n_samples' => 3 ) ));

        $this->assertSame(3, $profile['reasoning']['n_samples']);
        $this->assertFalse($profile['reasoning']['enabled']);
        $this->assertSame(1, $profile['reasoning']['max_iters']);
        $this->assertSame(HarnessProfile::defaults()['retrieval'], $profile['retrieval']);
    }
}
