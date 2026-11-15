<?php

/**
 * Harness profile — the canonical defaults and the sanitisation rules.
 *
 * The LLM-harness layers (prompt cues, reasoning, tool routing, retrieval,
 * self-refine, memory scoping, guardrails) are configured per assistant by a
 * profile document. This class owns the *shape* and the clamping rules; each
 * runtime owns only the storage (WordPress: post meta plus a site-wide option;
 * Laravel: the settings store).
 *
 * Ported from `includes/harness/class-wp-mcp-ai-harness-profile.php` so both
 * runtimes resolve an identical profile from identical input.
 *
 * ## Licence
 *
 * @license MIT
 * @since   1.6.0
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class HarnessProfile
{
	/** Hard upper bound on best-of-N samples regardless of the profile setting. */
	public const MAX_REASONING_SAMPLES = 8;

	/** Hard upper bound on synchronous self-refine iterations. */
	public const MAX_REFINE_ITERATIONS = 4;

	/** Default cost ceiling per request in USD. */
	public const DEFAULT_COST_CEILING_USD = 1.0;

	/** Retrieval fan-out bounds. */
	public const MIN_RETRIEVAL_K = 1;
	public const MAX_RETRIEVAL_K = 50;

	/** Preset-weight matrix bounds (Layer C routing). */
	public const MIN_PRESET_WEIGHT = -5.0;
	public const MAX_PRESET_WEIGHT = 5.0;

	/** Trace retention bounds, in runs. */
	public const MIN_RETENTION_RUNS = 10;
	public const MAX_RETENTION_RUNS = 200;

	/** Cost-ceiling bounds, in USD. */
	public const MAX_COST_CEILING_USD = 1000.0;

	/**
	 * The canonical "off" profile. Every layer disabled.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return array(
			'enabled' => false,
			'cues' => array(),
			'reasoning' => array(
				'enabled' => false,
				'n_samples' => 1,
				'max_iters' => 1,
			),
			'tools' => array(
				'router' => 'fixed',
				'preset_weights' => array(),
			),
			'retrieval' => array(
				'enabled' => false,
				'k' => 5,
				'require_citations' => false,
			),
			'refine' => array(
				'enabled' => false,
				'max_iters' => 1,
			),
			'memory' => array(
				'scoped' => false,
				'task_class' => 'general',
				'pii_filter' => true,
			),
			'guardrails' => array(
				'enabled' => false,
				'strictness' => 'medium',
				'mode' => 'warn',
				'allowed_topics' => array(),
			),
			'necessity_gate' => array(
				'enabled' => false,
				'strictness' => 'medium',
				'auto_skip' => true,
				'require_approval_for_irreversible' => true,
			),
			'output_guard' => array(
				'enabled' => false,
				'mode' => 'warn',
				'strictness' => 'medium',
			),
			'citation_verify' => array(
				'enabled' => false,
				'min_similarity' => 0.3,
				'annotate_unverified' => true,
			),
			'evals_enabled' => array(),
			'verifiers' => array(),
			'trace_capture' => array(
				'enabled' => false,
				'retention_runs' => 50,
			),
			'cost_ceiling_usd' => self::DEFAULT_COST_CEILING_USD,
		);
	}

	/**
	 * Sanitise and clamp an arbitrary input to a valid profile.
	 *
	 * A string is decoded as JSON. Anything that is not an array becomes the
	 * all-off default, so a malformed stored profile degrades to "harness off"
	 * rather than throwing.
	 *
	 * @param mixed $raw Raw input (array, JSON string, or anything else).
	 * @return array<string, mixed>
	 */
	public static function sanitize(mixed $raw): array
	{
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : array();
		}

		if (! is_array($raw)) {
			$raw = array();
		}

		$out = self::defaults();

		if (isset($raw['enabled'])) {
			$out['enabled'] = (bool) $raw['enabled'];
		}

		if (isset($raw['cues']) && is_array($raw['cues'])) {
			$cues = array();
			foreach ($raw['cues'] as $cue) {
				$slug = self::key((string) $cue);
				if ('' !== $slug) {
					$cues[] = $slug;
				}
			}
			$out['cues'] = array_values(array_unique($cues));
		}

		if (isset($raw['reasoning']) && is_array($raw['reasoning'])) {
			$r = $raw['reasoning'];
			$out['reasoning']['enabled'] = ! empty($r['enabled']);
			$out['reasoning']['n_samples'] = self::clampInt(
				$r['n_samples'] ?? 1,
				1,
				self::MAX_REASONING_SAMPLES
			);
			$out['reasoning']['max_iters'] = self::clampInt(
				$r['max_iters'] ?? 1,
				1,
				self::MAX_REFINE_ITERATIONS
			);
		}

		if (isset($raw['tools']) && is_array($raw['tools'])) {
			$router = isset($raw['tools']['router']) ? self::key((string) $raw['tools']['router']) : 'fixed';
			$out['tools']['router'] = in_array($router, array( 'fixed', 'scored' ), true) ? $router : 'fixed';

			// Preset slugs are taken at face value: the canonical list belongs to
			// the runtime's preset helper, but a profile may reference a preset
			// that is hidden or ships in an add-on. Unknown slugs simply have no
			// effect at scoring time.
			if (isset($raw['tools']['preset_weights']) && is_array($raw['tools']['preset_weights'])) {
				$weights = array();
				foreach ($raw['tools']['preset_weights'] as $preset_slug => $weight) {
					$slug = self::key((string) $preset_slug);
					if ('' === $slug) {
						continue;
					}

					$value = (float) $weight;
					$value = max(self::MIN_PRESET_WEIGHT, min(self::MAX_PRESET_WEIGHT, $value));

					// Drop zero entries to keep the stored profile compact.
					if (0.0 === $value) {
						continue;
					}

					$weights[ $slug ] = $value;
				}
				$out['tools']['preset_weights'] = $weights;
			}
		}

		if (isset($raw['retrieval']) && is_array($raw['retrieval'])) {
			$rt = $raw['retrieval'];
			$out['retrieval']['enabled'] = ! empty($rt['enabled']);
			$out['retrieval']['k'] = self::clampInt(
				$rt['k'] ?? 5,
				self::MIN_RETRIEVAL_K,
				self::MAX_RETRIEVAL_K
			);
			$out['retrieval']['require_citations'] = ! empty($rt['require_citations']);
		}

		if (isset($raw['refine']) && is_array($raw['refine'])) {
			$rf = $raw['refine'];
			$out['refine']['enabled'] = ! empty($rf['enabled']);
			$out['refine']['max_iters'] = self::clampInt(
				$rf['max_iters'] ?? 1,
				1,
				self::MAX_REFINE_ITERATIONS
			);
		}

		if (isset($raw['memory']) && is_array($raw['memory'])) {
			$m = $raw['memory'];
			$out['memory']['scoped'] = ! empty($m['scoped']);
			$out['memory']['task_class'] = isset($m['task_class']) ? self::key((string) $m['task_class']) : 'general';

			if ('' === $out['memory']['task_class']) {
				$out['memory']['task_class'] = 'general';
			}

			$out['memory']['pii_filter'] = isset($m['pii_filter']) ? (bool) $m['pii_filter'] : true;
		}

		if (isset($raw['guardrails']) && is_array($raw['guardrails'])) {
			$g = $raw['guardrails'];
			$out['guardrails']['enabled'] = ! empty($g['enabled']);
			$out['guardrails']['strictness'] = isset($g['strictness']) && in_array((string) $g['strictness'], array( 'low', 'medium', 'high' ), true)
				? (string) $g['strictness']
				: 'medium';
			$out['guardrails']['mode'] = isset($g['mode']) && in_array((string) $g['mode'], array( 'block', 'warn', 'log' ), true)
				? (string) $g['mode']
				: 'warn';

			if (isset($g['allowed_topics']) && is_array($g['allowed_topics'])) {
				$topics = array();
				foreach ($g['allowed_topics'] as $topic) {
					$topic = self::text((string) $topic);
					if ('' !== $topic) {
						$topics[] = $topic;
					}
				}
				$out['guardrails']['allowed_topics'] = array_values(array_unique($topics));
			}
		}

		if (isset($raw['output_guard']) && is_array($raw['output_guard'])) {
			$o = $raw['output_guard'];
			$out['output_guard']['enabled'] = ! empty($o['enabled']);
			$out['output_guard']['mode'] = isset($o['mode']) && in_array((string) $o['mode'], array( 'block', 'warn', 'log' ), true)
				? (string) $o['mode']
				: 'warn';
			$out['output_guard']['strictness'] = isset($o['strictness']) && in_array((string) $o['strictness'], array( 'low', 'medium', 'high' ), true)
				? (string) $o['strictness']
				: 'medium';
		}

		if (isset($raw['trace_capture']) && is_array($raw['trace_capture'])) {
			$tc = $raw['trace_capture'];
			$out['trace_capture']['enabled'] = ! empty($tc['enabled']);
			$out['trace_capture']['retention_runs'] = self::clampInt(
				$tc['retention_runs'] ?? 50,
				self::MIN_RETENTION_RUNS,
				self::MAX_RETENTION_RUNS
			);
		}

		if (isset($raw['evals_enabled']) && is_array($raw['evals_enabled'])) {
			$out['evals_enabled'] = self::uniqueKeys($raw['evals_enabled']);
		}

		if (isset($raw['verifiers']) && is_array($raw['verifiers'])) {
			$out['verifiers'] = self::uniqueKeys($raw['verifiers']);
		}

		if (isset($raw['cost_ceiling_usd'])) {
			$ceiling = (float) $raw['cost_ceiling_usd'];
			$out['cost_ceiling_usd'] = max(0.0, min(self::MAX_COST_CEILING_USD, $ceiling));
		}

		return $out;
	}

	/**
	 * Clamp an integer into an inclusive range.
	 */
	private static function clampInt(mixed $value, int $min, int $max): int
	{
		$value = (int) $value;

		if ($value < $min) {
			return $min;
		}

		if ($value > $max) {
			return $max;
		}

		return $value;
	}

	/**
	 * Sanitised slugs, de-duplicated, order preserved.
	 *
	 * @param array<int, mixed> $values Raw slugs.
	 * @return array<int, string>
	 */
	private static function uniqueKeys(array $values): array
	{
		$slugs = array();

		foreach ($values as $value) {
			$slug = self::key((string) $value);
			if ('' !== $slug) {
				$slugs[] = $slug;
			}
		}

		return array_values(array_unique($slugs));
	}

	/**
	 * Sanitise a key the way WordPress's sanitize_key() does.
	 *
	 * Lowercase, and only `a-z`, `0-9`, `_`, `-` survive.
	 */
	private static function key(string $value): string
	{
		return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
	}

	/**
	 * Sanitise a short text field the way WordPress's sanitize_text_field() does
	 * for the cases a profile can hit: strip tags, drop newlines and collapse
	 * runs of whitespace, then trim.
	 */
	private static function text(string $value): string
	{
		$value = (string) preg_replace('/<[^>]*>/', '', $value);
		$value = (string) preg_replace('/[\r\n\t ]+/', ' ', $value);

		return trim($value);
	}
}
