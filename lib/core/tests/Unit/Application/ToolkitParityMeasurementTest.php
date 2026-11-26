<?php

/**
 * Guards the arithmetic behind the parity report.
 *
 * Proposal 164 sections 164.6 and 164.89. `bin/toolkit-parity.php` publishes
 * `remaining` and the programme reads it as the backlog. The register documents
 * three times that a deferred tool *stays* in that count — a deferral is work
 * waiting on a named prerequisite, not a decision to leave it undone — but the
 * measurer subtracted it, so registering nine deferred tools would have reported
 * the calendar-booking toolkit as closed while nine tools remained unported.
 *
 * The script is a CLI rather than a class, so this reads its `--json` output and
 * checks the relation the documentation promises instead of duplicating the
 * measurement.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

final class ToolkitParityMeasurementTest extends TestCase
{
    public function test_remaining_is_the_unported_work_and_includes_the_deferred_tools(): void
    {
        $report = $this->report();

        $this->assertNotSame(array(), $report['toolkits']);

        foreach ($report['toolkits'] as $toolkit => $row) {
            $this->assertSame(
                $row['wp'] - $row['ported'] - $row['declined'],
                $row['remaining'],
                sprintf(
                    '%s: remaining must be the unported work (wp minus ported and declined), with deferred included.',
                    $toolkit
                )
            );

            $this->assertLessThanOrEqual(
                $row['remaining'],
                $row['deferred'],
                sprintf('%s: deferred must be a subset of remaining.', $toolkit)
            );

            $this->assertLessThanOrEqual(
                $row['wp'],
                $row['ported'] + $row['declined'] + $row['remaining'],
                sprintf('%s: the buckets cannot exceed the declared total.', $toolkit)
            );
        }
    }

    public function test_a_deferred_slug_is_counted_in_the_backlog(): void
    {
        $report = $this->report();

        $deferred = 0;

        foreach ($report['toolkits'] as $row) {
            $deferred += $row['deferred'];
        }

        $this->assertGreaterThan(0, $deferred, 'The register records no deferred tools; the guard cannot prove the rule.');
        $this->assertGreaterThanOrEqual(
            $deferred,
            $report['totals']['remaining'],
            'A deferral that is subtracted from remaining would hide work that is still owed.'
        );
    }

    /**
     * The measured report, or a skip when this checkout has no Pro tree to measure.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $root   = dirname(__DIR__, 5);
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'toolkit-parity.php';
        $tools  = $root . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'pro'
            . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tools';

        if (! is_file($script) || ! is_dir($tools)) {
            $this->markTestSkipped('This checkout has no Pro toolkit tree to measure.');
        }

        if (! function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, so the parity script cannot be run here.');
        }

        $output = array();
        $status = 0;

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --json',
            $output,
            $status
        );

        $this->assertSame(0, $status, 'toolkit-parity.php --json did not exit cleanly.');

        $decoded = json_decode(implode("\n", $output), true);

        $this->assertIsArray($decoded, 'toolkit-parity.php --json did not return JSON.');
        $this->assertArrayHasKey('toolkits', $decoded);
        $this->assertArrayHasKey('totals', $decoded);

        return $decoded;
    }
}
