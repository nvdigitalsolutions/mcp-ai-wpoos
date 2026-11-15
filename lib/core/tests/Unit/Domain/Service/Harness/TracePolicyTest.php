<?php

/**
 * Tests for the trace policy.
 *
 * The load-bearing property is that the retention window cannot leave
 * 10–200 regardless of what a profile or a caller asks for: the profile
 * sanitiser clamps on the way in, but a direct meta write or a config file
 * bypasses it, and an unbounded window is an unbounded disk.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use Nvoos\Core\Domain\Service\Harness\NullTraceStore;
use Nvoos\Core\Domain\Service\Harness\TracePolicy;
use PHPUnit\Framework\TestCase;

final class TracePolicyTest extends TestCase
{
    public function test_capture_requires_a_disabled_profile_to_stay_off(): void
    {
        $profile = array(
            'enabled' => false,
            'trace_capture' => array( 'enabled' => true ),
        );

        $this->assertFalse(TracePolicy::isEnabled($profile));
    }

    public function test_capture_needs_its_own_switch(): void
    {
        $profile = array(
            'enabled' => true,
            'trace_capture' => array( 'enabled' => false ),
        );

        $this->assertFalse(TracePolicy::isEnabled($profile));
    }

    public function test_a_profile_without_a_trace_block_captures_nothing(): void
    {
        $this->assertFalse(TracePolicy::isEnabled(array( 'enabled' => true )));
    }

    public function test_capture_is_on_only_when_both_switches_are_on(): void
    {
        $profile = array(
            'enabled' => true,
            'trace_capture' => array( 'enabled' => true ),
        );

        $this->assertTrue(TracePolicy::isEnabled($profile));
    }

    public function test_the_default_window_matches_the_wordpress_store(): void
    {
        $this->assertSame(50, TracePolicy::DEFAULT_RETENTION_RUNS);
        $this->assertSame(50, TracePolicy::retentionRuns(array( 'trace_capture' => array() )));
    }

    public function test_an_explicit_window_is_honoured_inside_the_bounds(): void
    {
        $profile = array( 'trace_capture' => array( 'retention_runs' => 25 ) );

        $this->assertSame(25, TracePolicy::retentionRuns($profile));
    }

    /**
     * @dataProvider outOfBoundsWindows
     */
    public function test_a_window_outside_the_bounds_is_clamped(int $requested, int $expected): void
    {
        $this->assertSame($expected, TracePolicy::clampRuns($requested));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function outOfBoundsWindows(): array
    {
        return array(
            'unbounded' => array( PHP_INT_MAX, HarnessProfile::MAX_RETENTION_RUNS ),
            'zero' => array( 0, HarnessProfile::MIN_RETENTION_RUNS ),
            'negative' => array( -10, HarnessProfile::MIN_RETENTION_RUNS ),
            'just below' => array( 9, HarnessProfile::MIN_RETENTION_RUNS ),
            'just above' => array( 201, HarnessProfile::MAX_RETENTION_RUNS ),
            'at the floor' => array( 10, 10 ),
            'at the ceiling' => array( 200, 200 ),
        );
    }

    public function test_a_profile_window_is_clamped_through_the_policy_too(): void
    {
        $this->assertSame(
            HarnessProfile::MAX_RETENTION_RUNS,
            TracePolicy::retentionRuns(array( 'trace_capture' => array( 'retention_runs' => 100000 ) ))
        );
    }

    public function test_the_null_store_reports_that_it_stored_nothing(): void
    {
        $store = new NullTraceStore();

        $this->assertFalse($store->append(7, 'run-1', 'refine.jsonl', array( 'iteration' => 1 )));
        $this->assertSame(0, $store->prune(7, 10));
    }
}
