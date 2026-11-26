<?php

/**
 * Declared-and-not-ported decisions for the Pro toolkits.
 *
 * Proposal 164 §164.6. `lib/core/resources/tool-readiness.php` answers for every
 * class this runtime *has*; it cannot answer for a WordPress tool this runtime
 * has no class for. Those tools are counted by `bin/toolkit-parity.php` from the
 * Pro tree, so before this file the only way to shrink that count was to port
 * them — a decision not to port had nowhere to live, and the number could not
 * tell "not built yet" from "will never be built".
 *
 * ## Two words, and they mean different things to the number
 *
 * - **declined** — not ported, and not intended. Subtracted from `remaining`,
 *   because it is not work. `extract_video_metadata` is the model case: the
 *   served `get_video_metadata` already answers it, so a port would be a second
 *   slug for one answer.
 * - **deferred** — not ported, blocked on a named prerequisite. Stays in
 *   `remaining`, because it *is* work once the prerequisite lands.
 *
 * Collapsing the two would either hide work or launder it: counting a deferral
 * as declined makes the backlog look smaller than it is, and counting a decline
 * as deferral keeps a decided question open forever.
 *
 * ## Not the same thing as the manifest's `parity: declined`
 *
 * The readiness manifest records `parity` for the classes it lists — including
 * two declined stand-ins that *do* have a core class. This file is the other
 * case: a WordPress tool with no class here at all. A reader who finds both
 * should treat the manifest as the per-class opinion and this as the per-tool
 * one; a slug may not be served while either says otherwise, which is what
 * {@see violations()} enforces.
 *
 * ## A register is only worth having if it is checked
 *
 * Three ways a decision can rot, all caught: an unknown decision word, a reason
 * that was never written, a slug no toolkit declares (stale or a typo), and a
 * slug that is recorded as not-ported but is in fact served. The last is the one
 * that matters most — it means the register was not updated after a port, and a
 * stale register is worse than none because it is quoted.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

final class ToolkitParityDecisions
{
    /** Not ported, and not intended. Subtracted from `remaining`. */
    public const DECLINED = 'declined';

    /** Not ported, blocked on a named prerequisite. Stays in `remaining`. */
    public const DEFERRED = 'deferred';

    /**
     * The closed vocabulary.
     *
     * @var string[]
     */
    public const VOCABULARY = array(
        self::DECLINED,
        self::DEFERRED,
    );

    /**
     * Parsed registers, keyed by resolved path.
     *
     * @var array<string, array<string, array{decision: string, reason: string}>>
     */
    private static array $cache = array();

    private readonly string $decisionsPath;

    /**
     * @param string|null $decisionsPath Override for a host that ships a copy.
     *                                   Defaults to the packaged register.
     */
    public function __construct(?string $decisionsPath = null)
    {
        $this->decisionsPath = $decisionsPath ?? dirname(__DIR__, 3) . '/resources/toolkit-parity-decisions.json';
    }

    /**
     * Absolute path of the register this instance reads.
     */
    public function path(): string
    {
        return $this->decisionsPath;
    }

    /**
     * Forget every parsed register.
     *
     * The cache is process-wide for the same reason `ToolReadiness`'s is: a
     * host reads the file once, and a gate reads it once. This exists so a test
     * can change the file under a live instance.
     */
    public static function flush(): void
    {
        self::$cache = array();
    }

    /**
     * Every decision, keyed by slug and sorted.
     *
     * A missing file is an empty register rather than an error: the file is
     * additive, and a checkout that predates it should still run the gate.
     *
     * @return array<string, array{decision: string, reason: string}>
     *
     * @throws \RuntimeException When the file exists but is not the expected shape.
     */
    public function all(): array
    {
        return self::load($this->decisionsPath);
    }

    /**
     * The decision word for a slug, or null when there is no opinion.
     */
    public function decisionFor(string $slug): ?string
    {
        $decision = $this->all()[$slug]['decision'] ?? null;

        return is_string($decision) && '' !== $decision ? $decision : null;
    }

    /**
     * Whether this slug is recorded as never-to-be-ported.
     */
    public function isDeclined(string $slug): bool
    {
        return self::DECLINED === $this->decisionFor($slug);
    }

    /**
     * Whether this slug is recorded as blocked on a named prerequisite.
     */
    public function isDeferred(string $slug): bool
    {
        return self::DEFERRED === $this->decisionFor($slug);
    }

    /**
     * Everything wrong with the register, given what the tree actually says.
     *
     * The two arguments are sets rather than lists because the caller already
     * has them in that shape, and because membership is the only question asked.
     *
     * @param array<string, bool> $declared Every slug the toolkit tree declares.
     * @param array<string, bool> $servable Every slug the readiness manifest serves.
     *
     * @return list<string> Human-readable violations; empty means consistent.
     */
    public function violations(array $declared, array $servable): array
    {
        $violations = array();

        foreach ($this->all() as $slug => $row) {
            $decision = $row['decision'];
            $known    = in_array($decision, self::VOCABULARY, true);

            if (!$known) {
                $violations[] = sprintf(
                    '`%s` records an unknown decision `%s`; expected one of: %s.',
                    $slug,
                    '' === $decision ? '(empty)' : $decision,
                    implode(', ', self::VOCABULARY)
                );
            }

            // The reason is independent of the word, so it is reported either
            // way: a row with both faults should name both in one pass.
            if ('' === $row['reason']) {
                $violations[] = sprintf(
                    '`%s` records no reason; a decision without a reason is a guess.',
                    $slug
                );
            }

            // Past this point the word is known, so the tree can be compared
            // against it. An unknown word means there is nothing to compare.
            if (!$known) {
                continue;
            }

            if (!isset($declared[$slug])) {
                $violations[] = sprintf(
                    '`%s` is recorded as %s, but no toolkit declares it. Stale decision, or a typo.',
                    $slug,
                    $decision
                );

                continue;
            }

            // The contradiction that matters: a decision not to port cannot
            // stand next to a tool that is being served under the same slug.
            if (isset($servable[$slug])) {
                $violations[] = sprintf(
                    '`%s` is recorded as %s but is served. Remove the decision.',
                    $slug,
                    $decision
                );
            }
        }

        return $violations;
    }

    /**
     * Read and normalise one register.
     *
     * The decision word is lower-cased and the reason trimmed, so the vocabulary
     * check reports real typos rather than a capital letter. An empty decision
     * is deliberately left empty rather than defaulted, because defaulting is
     * how a malformed row becomes a silent `declined`.
     *
     * @return array<string, array{decision: string, reason: string}>
     *
     * @throws \RuntimeException When the file is not the expected shape.
     */
    private static function load(string $path): array
    {
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        if (!is_file($path)) {
            return self::$cache[$path] = array();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !is_array($decoded['decisions'] ?? null)) {
            throw new \RuntimeException(
                'The toolkit parity decisions file is not JSON with a "decisions" map: ' . $path
            );
        }

        $decisions = array();

        foreach ($decoded['decisions'] as $slug => $row) {
            $decisions[(string) $slug] = array(
                'decision' => is_array($row) ? strtolower(trim((string) ($row['decision'] ?? ''))) : '',
                'reason'   => is_array($row) ? trim((string) ($row['reason'] ?? '')) : '',
            );
        }

        ksort($decisions);

        return self::$cache[$path] = $decisions;
    }
}
