<?php

/**
 * Trace policy — whether capture is on, and how many runs are kept.
 *
 * Ported from `WP_MCP_AI_Harness_Trace_Capture::is_capture_enabled()` and the
 * `MAX_RUNS_PER_ASSISTANT` constant on the WordPress trace store. Both runtimes
 * read the same `trace_capture` block, so a profile copied between them captures
 * the same way.
 *
 * `retentionRuns()` never returns a value outside `HarnessProfile`'s 10–200
 * window: the profile sanitiser clamps it on the way in, and this clamps it
 * again on the way out, because a profile can be written by a path that bypasses
 * the sanitiser (a direct meta write, a config file, a test).
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class TracePolicy
{
	/** Mirrors `WP_MCP_AI_Harness_Trace_Store::MAX_RUNS_PER_ASSISTANT`. */
	public const DEFAULT_RETENTION_RUNS = 50;

	public const MIN_RUNS = HarnessProfile::MIN_RETENTION_RUNS;
	public const MAX_RUNS = HarnessProfile::MAX_RETENTION_RUNS;

	/**
	 * Whether trace capture is enabled for a sanitised profile.
	 *
	 * Requires the profile itself to be enabled *and* an explicit
	 * `trace_capture.enabled`, matching WordPress: capture is opt-in per
	 * assistant, never a side effect of turning the harness on.
	 */
	public static function isEnabled(array $profile): bool
	{
		if (empty($profile['enabled'])) {
			return false;
		}

		if (!isset($profile['trace_capture']) || !is_array($profile['trace_capture'])) {
			return false;
		}

		return ! empty($profile['trace_capture']['enabled']);
	}

	/**
	 * The retention window for a profile, in runs.
	 */
	public static function retentionRuns(array $profile): int
	{
		$raw = $profile['trace_capture']['retention_runs'] ?? self::DEFAULT_RETENTION_RUNS;

		return self::clampRuns($raw);
	}

	/**
	 * Clamp a requested window to `MIN_RUNS..MAX_RUNS`.
	 */
	public static function clampRuns(mixed $runs): int
	{
		$runs = (int) $runs;

		if ($runs < self::MIN_RUNS) {
			return self::MIN_RUNS;
		}

		if ($runs > self::MAX_RUNS) {
			return self::MAX_RUNS;
		}

		return $runs;
	}
}
