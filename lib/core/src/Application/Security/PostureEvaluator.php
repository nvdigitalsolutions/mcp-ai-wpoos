<?php

/**
 * Builds the posture signal list for one runtime.
 *
 * The runtime supplies only evaluations (`id => passed/detail/label`); the
 * catalogue supplies identity, weight and remediation target. Three outcomes
 * are possible and all of them are explicit:
 *
 *  - evaluated and applicable   → pass or fail
 *  - not in the catalogue       → ignored (a runtime cannot invent weight)
 *  - applicable but unevaluated → fail with "Not evaluated by this runtime",
 *                                 so a missing check is visible rather than
 *                                 a silently free pass
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Security;

use Nvoos\Core\Domain\Security\SecuritySignalCatalogue;
use Nvoos\Core\Domain\Security\SecuritySignalDefinition;
use Nvoos\Core\Domain\ValueObject\PostureSignal;

final class PostureEvaluator
{
	public const UNEVALUATED_DETAIL = 'Not evaluated by this runtime.';

	/**
	 * @param string $runtime     One of SecuritySignalCatalogue::RUNTIME_*.
	 * @param array<string, array{passed?: bool, detail?: string, label?: string}> $evaluations
	 * @return array<int, PostureSignal>
	 */
	public static function build(string $runtime, array $evaluations): array
    {
		$signals = array();

		foreach (SecuritySignalCatalogue::all() as $definition) {
			$signals[] = self::signal($definition, $runtime, $evaluations);
		}

		return $signals;
	}

	/**
	 * @param array<string, array{passed?: bool, detail?: string, label?: string}> $evaluations
	 */
	private static function signal(
		SecuritySignalDefinition $definition,
		string $runtime,
		array $evaluations
	): PostureSignal {
		if (! $definition->appliesTo($runtime)) {
			return new PostureSignal(
				definition: $definition,
				passed: false,
				applicable: false,
				detail: SecuritySignalCatalogue::NOT_APPLICABLE_DETAIL,
			);
		}

		$evaluation = $evaluations[ $definition->id ] ?? null;

		if (null === $evaluation) {
			return new PostureSignal(
				definition: $definition,
				passed: false,
				applicable: true,
				detail: self::UNEVALUATED_DETAIL,
			);
		}

		return new PostureSignal(
			definition: $definition,
			passed: (bool) ( $evaluation['passed'] ?? false ),
			applicable: true,
			detail: (string) ( $evaluation['detail'] ?? '' ),
			label: (string) ( $evaluation['label'] ?? '' ),
		);
	}
}
