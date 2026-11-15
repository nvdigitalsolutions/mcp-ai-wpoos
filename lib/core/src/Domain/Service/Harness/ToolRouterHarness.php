<?php

/**
 * Tool router harness — score and rank candidate tools for a task class.
 *
 * The score is a transparent weighted sum over three signals: capability flags
 * that tend to help with the task class, explicit per-slug assistant
 * preferences, and per-preset-family weights. Recent reliability is the fourth
 * signal the WordPress façade documents, but it is not read here — a runtime
 * that has measurement data injects it through the score filter rather than
 * making the router depend on a measurement subsystem.
 *
 * When semantic attention scores are available, the structural scores are fused
 * with them by Weighted Reciprocal Rank Fusion (Cormack et al. 2009), the
 * method Elasticsearch, OpenSearch and MongoDB use. RRF operates on ranks, not
 * raw scores, so the two scorers do not have to share a scale.
 *
 * Two runtime dependencies are injected rather than imported:
 *
 *   - the preset catalogue, because the WordPress one is a translated
 *     hard-coded array and core has no translations;
 *   - the two hook seams, because core has no `apply_filters`.
 *
 * Failure is not an exception here. A router that throws takes the chat turn
 * with it, so `rank()` degrades: a tool that cannot be scored is skipped, and a
 * wholesale failure returns an empty ranking, which callers read as "use the
 * fixed order".
 *
 * Ported from `includes/harness/class-wp-mcp-ai-tool-router-harness.php`.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Throwable;

final class ToolRouterHarness
{
    /**
     * RRF smoothing constant. 60 is the industry default (Elasticsearch,
     * OpenSearch, MongoDB) and stops a top-ranked item from dominating by
     * dividing by a near-zero rank.
     */
    public const RRF_K = 60;

    /** Read-leaning task classes, where a write tool is penalised. */
    private const READ_LEANING = array( 'qa', 'research', 'rag', 'math' );

    /** Penalty applied to a state-changing or write tool on a read-leaning task. */
    private const WRITE_PENALTY = 1.5;

    /** Every tool starts here, so a flagged tool always outranks an unflagged one. */
    private const BASE_SCORE = 1.0;

    /**
     * Per-stage RRF weights, by task class.
     *
     * Research and RAG lean on semantic matching ("which tool understands the
     * domain?"); math and code lean on structural matching ("which tool is
     * idempotent and safe?").
     *
     * @var array<string, array{attention: float, harness: float}>
     */
    private const STAGE_WEIGHTS = array(
        'research' => array( 'attention' => 1.5, 'harness' => 0.5 ),
        'rag' => array( 'attention' => 1.5, 'harness' => 0.5 ),
        'qa' => array( 'attention' => 1.2, 'harness' => 0.8 ),
        'math' => array( 'attention' => 0.5, 'harness' => 1.5 ),
        'code' => array( 'attention' => 0.8, 'harness' => 1.2 ),
        'agentic' => array( 'attention' => 1.0, 'harness' => 1.0 ),
        'general' => array( 'attention' => 1.0, 'harness' => 1.0 ),
    );

    /**
     * Coarse task class → capability flag weights.
     *
     * Deliberately conservative: missing a useful tool is cheaper than
     * recommending a write tool for a read-only task.
     *
     * @var array<string, array<string, float>>
     */
    private const TASK_FLAG_WEIGHTS = array(
        'general' => array( 'read-only' => 1.0 ),
        'qa' => array( 'read-only' => 1.5, 'cacheable' => 0.5 ),
        'research' => array( 'read-only' => 1.5, 'external-api' => 1.0, 'cacheable' => 0.5 ),
        'rag' => array( 'read-only' => 2.0 ),
        'math' => array( 'local-only' => 1.5, 'idempotent' => 1.0 ),
        'code' => array( 'read-only' => 1.0, 'idempotent' => 1.0 ),
        'agentic' => array( 'read-only' => 1.0, 'reversible' => 0.8, 'idempotent' => 0.5 ),
    );

    /**
     * Rank tools for a task class, highest score first.
     *
     * Returns an empty array when ranking fails wholesale; callers treat that
     * as "fall back to the fixed order", which is why this never throws.
     *
     * @param iterable                  $tools           Tool instances.
     * @param string                    $taskClass       Task class slug.
     * @param array<string, float>      $assistantPrefs  Per-slug preferences.
     * @param array<string, float>      $presetWeights   Per-preset-family weights.
     * @param array<string, float>      $attentionScores Semantic scores, slug → 0–1.
     * @param array{0?: array<string,float>,1?: array<string,float>} $options See below.
     *
     * @return array<string, float> Slug → score, descending. Empty on failure.
     *
     * Supported `$options` keys:
     *   - `presets`         array<string, array{tools?: array<int, string>}> The
     *                       preset catalogue used to resolve preset weights.
     *   - `preset_index`    array<string, array<int, string>> A prebuilt
     *                       `buildPresetIndex()` result, to avoid rebuilding it
     *                       per call.
     *   - `score_filter`    callable(float, mixed, string, array, array, ?float): float
     *                       Per-tool score override.
     *   - `stage_weight_filter` callable(float, string, string): float
     *                       Per-stage RRF weight override; the second argument is
     *                       `harness` or `attention`.
     */
    public static function rank(
        iterable $tools,
        string $taskClass,
        array $assistantPrefs = array(),
        array $presetWeights = array(),
        array $attentionScores = array(),
        array $options = array()
    ): array {
        try {
            // Scoring normalises the class; fusion does not, because the
            // WordPress fuser reads the caller's string verbatim. Keeping the
            // two paths apart is what makes the delegation indistinguishable.
            $scoringClass = self::taskClass($taskClass);
            $presetIndex = $options['preset_index'] ?? self::buildPresetIndex($options['presets'] ?? array());
            $scoreFilter = $options['score_filter'] ?? null;
            $stageWeightFilter = $options['stage_weight_filter'] ?? null;

            $scored = array();

            foreach ($tools as $tool) {
                $slug = self::slugOf($tool);

                if ('' === $slug) {
                    continue;
                }

                $attention = isset($attentionScores[ $slug ]) ? (float) $attentionScores[ $slug ] : null;

                try {
                    $scored[ $slug ] = self::score(
                        $tool,
                        $scoringClass,
                        $assistantPrefs,
                        $presetWeights,
                        $attention,
                        $presetIndex,
                        $scoreFilter
                    );
                } catch (Throwable) {
                    // One unscoreable tool must not cost the whole ranking.
                    continue;
                }
            }

            if (array() !== $attentionScores) {
                return self::fuseWithRrf($scored, $attentionScores, $taskClass, $stageWeightFilter);
            }

            arsort($scored, SORT_NUMERIC);

            return $scored;
        } catch (Throwable) {
            return array();
        }
    }

    /**
     * Score one tool for a task class.
     *
     * @param mixed                $tool           Tool instance; anything else scores the base.
     * @param string               $taskClass      Task class slug.
     * @param array<string, float> $assistantPrefs Per-slug preferences.
     * @param array<string, float> $presetWeights  Per-preset-family weights.
     * @param float|null           $attentionScore Semantic score, or null when unavailable.
     * @param array<string, array<int, string>> $presetIndex Prebuilt preset index.
     * @param callable|null        $scoreFilter    Per-tool score override.
     */
    public static function scoreTool(
        mixed $tool,
        string $taskClass,
        array $assistantPrefs = array(),
        array $presetWeights = array(),
        ?float $attentionScore = null,
        array $presetIndex = array(),
        ?callable $scoreFilter = null
    ): float {
        return self::score(
            $tool,
            self::taskClass($taskClass),
            $assistantPrefs,
            $presetWeights,
            $attentionScore,
            $presetIndex,
            $scoreFilter
        );
    }

    /**
     * Fuse structural and semantic rankings with Weighted Reciprocal Rank Fusion.
     *
     *   RRF(d) = Σ  w_stage × 1 / (k + rank_stage(d))
     *
     * A slug missing from one rank list is treated as ranked at `max_rank` in
     * that list, so an item found by only one scorer still scores, just lower.
     *
     * @param array<string, float> $harnessScores   Structural scores.
     * @param array<string, float> $attentionScores Semantic scores.
     * @param string               $taskClass       Task class for stage weights.
     * @param callable|null        $weightFilter    Per-stage weight override.
     * @return array<string, float> Fused scores, descending.
     */
    public static function fuseWithRrf(
        array $harnessScores,
        array $attentionScores,
        string $taskClass,
        ?callable $weightFilter = null
    ): array {
        arsort($harnessScores, SORT_NUMERIC);
        arsort($attentionScores, SORT_NUMERIC);

        // 0-based rank per slug.
        $harnessRanks = array_flip(array_keys($harnessScores));
        $attentionRanks = array_flip(array_keys($attentionScores));

        // Stage weights fall back to `general`, but the filter still sees the
        // class the caller named.
        $lookup = isset(self::STAGE_WEIGHTS[ $taskClass ]) ? $taskClass : 'general';
        $weights = self::STAGE_WEIGHTS[ $lookup ];
        $weightHarness = (float) $weights['harness'];
        $weightAttention = (float) $weights['attention'];

        if (is_callable($weightFilter)) {
            $weightHarness = (float) $weightFilter($weightHarness, 'harness', $taskClass);
            $weightAttention = (float) $weightFilter($weightAttention, 'attention', $taskClass);
        }

        $allSlugs = array_unique(
            array_merge(array_keys($harnessScores), array_keys($attentionScores))
        );
        $maxRank = max(count($harnessScores), count($attentionScores), 1);

        $fused = array();

        foreach ($allSlugs as $slug) {
            $harnessRank = $harnessRanks[ $slug ] ?? $maxRank;
            $attentionRank = $attentionRanks[ $slug ] ?? $maxRank;

            $fused[ $slug ] = ($weightHarness * (1.0 / (self::RRF_K + $harnessRank)))
                + ($weightAttention * (1.0 / (self::RRF_K + $attentionRank)));
        }

        arsort($fused, SORT_NUMERIC);

        return $fused;
    }

    /**
     * Resolve the routing inputs from a harness profile.
     *
     * Keys are returned verbatim for `preset_weights`, as the WordPress façade
     * does, and sanitised for `preferences`, which is also what WordPress does
     * — the asymmetry is in the original and is preserved here. Values are
     * clamped either way, because this is the last read before scoring and
     * `tools.preferences` has no upstream sanitiser.
     *
     * @param array $profile Sanitised harness profile.
     * @return array{0: array<string, float>, 1: array<string, float>} [slug prefs, preset weights]
     */
    public static function resolveAssistantInputs(array $profile): array
    {
        $presetWeights = array();

        if (isset($profile['tools']['preset_weights']) && is_array($profile['tools']['preset_weights'])) {
            foreach ($profile['tools']['preset_weights'] as $presetSlug => $weight) {
                $presetWeights[ $presetSlug ] = self::clampWeight($weight);
            }
        }

        $slugPrefs = array();

        if (isset($profile['tools']['preferences']) && is_array($profile['tools']['preferences'])) {
            foreach ($profile['tools']['preferences'] as $toolSlug => $weight) {
                $slug = self::key((string) $toolSlug);

                if ('' !== $slug) {
                    $slugPrefs[ $slug ] = self::clampWeight($weight);
                }
            }
        }

        return array( $slugPrefs, $presetWeights );
    }

    /**
     * Build a reverse index: tool slug → the preset slugs that contain it.
     *
     * @param array<string, array{tools?: array<int, string>}> $presets Preset catalogue.
     * @return array<string, array<int, string>>
     */
    public static function buildPresetIndex(array $presets): array
    {
        $index = array();

        foreach ($presets as $presetSlug => $preset) {
            $presetKey = self::key((string) $presetSlug);

            if ('' === $presetKey || !is_array($preset) || empty($preset['tools']) || !is_array($preset['tools'])) {
                continue;
            }

            foreach ($preset['tools'] as $toolSlug) {
                $toolKey = self::key((string) $toolSlug);

                if ('' === $toolKey) {
                    continue;
                }

                $index[ $toolKey ][] = $presetKey;
            }
        }

        foreach ($index as $toolKey => $slugs) {
            $index[ $toolKey ] = array_values(array_unique($slugs));
        }

        return $index;
    }

    /**
     * Clamp an assistant-supplied routing weight to ±5.
     *
     * `HarnessProfile` clamps `tools.preset_weights`, but it has no sanitiser
     * for `tools.preferences` at all, and both can also be passed straight to
     * `rank()`. The router is the last line, so it clamps what it reads.
     */
    public static function clampWeight(mixed $weight): float
    {
        $weight = (float) $weight;

        if ($weight < HarnessProfile::MIN_PRESET_WEIGHT) {
            return HarnessProfile::MIN_PRESET_WEIGHT;
        }

        if ($weight > HarnessProfile::MAX_PRESET_WEIGHT) {
            return HarnessProfile::MAX_PRESET_WEIGHT;
        }

        return $weight;
    }

    /**
     * The scoring rule.
     *
     * @param array<string, array<int, string>> $presetIndex Prebuilt preset index.
     */
    private static function score(
        mixed $tool,
        string $taskClass,
        array $assistantPrefs,
        array $presetWeights,
        ?float $attentionScore,
        array $presetIndex,
        ?callable $scoreFilter
    ): float {
        $score = self::BASE_SCORE;
        $flags = self::flagsOf($tool);

        $weights = self::TASK_FLAG_WEIGHTS[ $taskClass ] ?? self::TASK_FLAG_WEIGHTS['general'];

        foreach ($weights as $flag => $weight) {
            if (in_array($flag, $flags, true)) {
                $score += (float) $weight;
            }
        }

        if (
            in_array($taskClass, self::READ_LEANING, true)
            && (in_array('state-changing', $flags, true) || in_array('write', $flags, true))
        ) {
            $score -= self::WRITE_PENALTY;
        }

        $slug = self::slugOf($tool);

        if ('' !== $slug && isset($assistantPrefs[ $slug ])) {
            $score += self::clampWeight($assistantPrefs[ $slug ]);
        }

        // A tool in several weighted presets accumulates their weights, so a
        // broad family preference can be layered with a narrow override.
        if ('' !== $slug && array() !== $presetWeights && isset($presetIndex[ $slug ])) {
            foreach ($presetIndex[ $slug ] as $presetSlug) {
                if (isset($presetWeights[ $presetSlug ])) {
                    $score += self::clampWeight($presetWeights[ $presetSlug ]);
                }
            }
        }

        if (is_callable($scoreFilter)) {
            $score = (float) $scoreFilter(
                $score,
                $tool,
                $taskClass,
                $assistantPrefs,
                $presetWeights,
                $attentionScore
            );
        }

        return $score;
    }

    /**
     * Normalise a task class slug. An empty class becomes `general`; an unknown
     * one is kept, so a score filter sees what the caller asked for and the
     * weight tables fall back on their own.
     */
    private static function taskClass(string $taskClass): string
    {
        $taskClass = self::key($taskClass);

        return '' === $taskClass ? 'general' : $taskClass;
    }

    /**
     * Read a tool's slug, whichever casing its interface uses.
     *
     * Duck-typed because WordPress tools implement `get_slug()` and core tools
     * implement `getSlug()`; the router accepts either.
     */
    private static function slugOf(mixed $tool): string
    {
        if (!is_object($tool)) {
            return '';
        }

        if (method_exists($tool, 'getSlug')) {
            $raw = $tool->getSlug();
        } elseif (method_exists($tool, 'get_slug')) {
            $raw = $tool->get_slug();
        } else {
            return '';
        }

        return is_scalar($raw) ? self::key((string) $raw) : '';
    }

    /**
     * Read a tool's capability flags, whichever interface it exposes.
     *
     * @return array<int, string>
     */
    private static function flagsOf(mixed $tool): array
    {
        if (!is_object($tool)) {
            return array();
        }

        if ($tool instanceof ToolCapabilityFlagsInterface) {
            $raw = $tool->getCapabilityFlags();
        } elseif (method_exists($tool, 'getCapabilityFlags')) {
            $raw = $tool->getCapabilityFlags();
        } elseif (method_exists($tool, 'get_capability_flags')) {
            $raw = $tool->get_capability_flags();
        } else {
            return array();
        }

        return is_array($raw) ? $raw : array();
    }

    /** WordPress `sanitize_key()`: byte-wise lowercase, then a fixed charset. */
    private static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
    }
}
