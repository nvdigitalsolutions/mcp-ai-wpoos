<?php

/**
 * Trace store that stores nothing.
 *
 * The default binding: a runtime with no trace storage configured still runs
 * the harness, it just cannot replay it afterwards. `append()` reports `false`
 * so a caller can tell "not captured" from "captured", and `prune()` reports
 * `0` removed rather than pretending it swept something.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

use Nvoos\Core\Domain\Contract\TraceStoreInterface;

final class NullTraceStore implements TraceStoreInterface
{
	public function append(int $assistantId, string $runId, string $artifact, array $record): bool
	{
		return false;
	}

	public function prune(int $assistantId, int $keepRuns): int
	{
		return 0;
	}
}
