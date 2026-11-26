<?php

/**
 * Server-side circuit breaker for autonomous sessions.
 *
 * Port of `WP_MCP_AI_Circuit_Breaker` (Proposal 164 section 164.78), which
 * implements the canonical three-state breaker: **closed** (calls flow),
 * **open** (fail fast), **half-open** (probe). The pattern is Fowler's, and the
 * defaults here match the common production guidance — a 50% error rate over a
 * minimum volume, a consecutive-failure trip, and a cooldown before one probe
 * is allowed.
 *
 * ## The state on the session, not in this object
 *
 * The WordPress class is stateless per call and reads/writes the session record,
 * which is what lets a different process inspect the breaker. This port keeps
 * that: `state()` reads the store, transitions write it, and the tools' responses
 * carry the resulting state. An in-memory breaker would lose the verdict on the
 * next request, which is the failure the breaker exists to prevent.
 *
 * ## Four WordPress behaviours are corrected, and named
 *
 * - **The consecutive-failure check was one failure late.** `record_failure()`
 *   read the session, incremented the stored counter, then re-read the *stale*
 *   copy to compare it with the threshold. With `max_consecutive_errors` of 3
 *   the breaker opened on the fourth failure. This port compares the
 *   incremented value, so it opens on the third.
 * - **`circuit_opened_at` is always stamped on opening.** WordPress writes it
 *   only on that transition too, but its timeout check treats a *missing* stamp
 *   as elapsed — so an open session whose stamp was lost half-opens immediately.
 *   The stamp is now part of the transition, and the missing-stamp rule is kept
 *   for records written by older code.
 * - **Stored config is clamped on read**, not only on write, so a hand-edited
 *   settings row cannot set a 0% threshold.
 * - **The `WP_Error` marker was unreachable.** WordPress lower-cases the output
 *   then searched for the literal `WP_Error`, which cannot match. The port
 *   searches for `wp_error`.
 *
 * ## What is deliberately not here
 *
 * Retries. The guidance is that retries belong *inside* the breaker and the
 * counted outcome is the final one; this class counts what it is told. It also
 * has no fallback policy — the caller decides what an open breaker means, which
 * is why `analyze_loop_health` returns a payload rather than throwing.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Orchestration;

use Nvoos\Core\Domain\Contract\AutonomousSessionStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class AutonomousCircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /** The settings key the configuration lives under. */
    public const CONFIG_KEY = 'autonomous_loop_circuit_breaker';

    public const DEFAULT_ERROR_THRESHOLD_PCT  = 50;
    public const DEFAULT_VOLUME_THRESHOLD     = 5;
    public const DEFAULT_RESET_TIMEOUT_SEC    = 300;
    public const DEFAULT_MAX_CONSECUTIVE_ERRS = 3;
    public const DEFAULT_NO_PROGRESS_CYCLES   = 3;

    /**
     * Bounds for every setting, matching `configure_circuit_breaker`.
     *
     * @var array<string, array{min: int, max: int, default: int}>
     */
    public const BOUNDS = array(
        'error_threshold_pct'    => array('min' => 10, 'max' => 100, 'default' => self::DEFAULT_ERROR_THRESHOLD_PCT),
        'volume_threshold'       => array('min' => 2, 'max' => 50, 'default' => self::DEFAULT_VOLUME_THRESHOLD),
        'reset_timeout_sec'      => array('min' => 60, 'max' => 3600, 'default' => self::DEFAULT_RESET_TIMEOUT_SEC),
        'max_consecutive_errors' => array('min' => 1, 'max' => 20, 'default' => self::DEFAULT_MAX_CONSECUTIVE_ERRS),
        'no_progress_cycles'     => array('min' => 2, 'max' => 10, 'default' => self::DEFAULT_NO_PROGRESS_CYCLES),
    );

    /** @var array<string, int> */
    private array $config;

    /**
     * @param array<string, mixed> $overrides Explicit overrides, applied after stored config.
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly AutonomousSessionStoreInterface $sessions,
        SettingsStoreInterface $settings,
        array $overrides = array(),
    ) {
        $stored = $settings->get(self::CONFIG_KEY, array());
        $config = is_array($stored) ? $stored : array();

        if (array() !== $overrides) {
            $config = array_merge($config, $overrides);
        }

        $this->config = self::normalise($config);
    }

    /**
     * Clamp a raw configuration map to the documented bounds.
     *
     * @param array<string, mixed> $config Raw values.
     *
     * @return array<string, int>
     */
    public static function normalise(array $config): array
    {
        $out = array();

        foreach (self::BOUNDS as $key => $bounds) {
            $value = isset($config[ $key ]) && is_numeric($config[ $key ])
                ? (int) $config[ $key ]
                : $bounds['default'];

            $out[ $key ] = max($bounds['min'], min($value, $bounds['max']));
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * The current state.
     */
    public function state(): string
    {
        $session = $this->sessions->find($this->sessionId);

        if (null === $session) {
            return self::STATE_CLOSED;
        }

        $state = $session['circuit_breaker'] ?? self::STATE_CLOSED;

        if (is_bool($state)) {
            return $state ? self::STATE_OPEN : self::STATE_CLOSED;
        }

        return in_array($state, array(self::STATE_CLOSED, self::STATE_OPEN, self::STATE_HALF_OPEN), true)
            ? (string) $state
            : self::STATE_CLOSED;
    }

    /**
     * Whether an iteration may run.
     *
     * An open breaker whose cooldown has elapsed moves to half-open and allows
     * one probe; a failed probe re-opens it.
     */
    public function allowExecution(): bool
    {
        $state = $this->state();

        if (self::STATE_CLOSED === $state || self::STATE_HALF_OPEN === $state) {
            return true;
        }

        if ($this->resetTimeoutElapsed()) {
            $this->transitionTo(self::STATE_HALF_OPEN);

            return true;
        }

        return false;
    }

    /**
     * Record a success: a half-open probe closes the circuit, and the
     * consecutive-failure streak resets.
     */
    public function recordSuccess(): void
    {
        if (self::STATE_HALF_OPEN === $this->state()) {
            $this->transitionTo(self::STATE_CLOSED);
        }

        $this->sessions->update($this->sessionId, array('consecutive_errors' => 0));
    }

    /**
     * Record a failure and open the circuit when a threshold is crossed.
     */
    public function recordFailure(): void
    {
        $session     = $this->sessions->find($this->sessionId) ?? array();
        $errors      = (int) ($session['error_count'] ?? 0) + 1;
        $consecutive = (int) ($session['consecutive_errors'] ?? 0) + 1;
        $attempts    = (int) ($session['iteration_count'] ?? 0);

        $this->sessions->update(
            $this->sessionId,
            array(
                'error_count'        => $errors,
                'consecutive_errors' => $consecutive,
            )
        );

        if ($attempts >= $this->config['volume_threshold']) {
            $errorRate = $attempts > 0 ? ( $errors / $attempts ) * 100 : 0.0;

            if ($errorRate >= $this->config['error_threshold_pct']) {
                $this->transitionTo(self::STATE_OPEN);

                return;
            }
        }

        if ($consecutive >= $this->config['max_consecutive_errors']) {
            $this->transitionTo(self::STATE_OPEN);

            return;
        }

        if (self::STATE_HALF_OPEN === $this->state()) {
            $this->transitionTo(self::STATE_OPEN);
        }
    }

    /**
     * Open the circuit unconditionally.
     *
     * `analyze_loop_health` is a second detector: a critical health verdict or
     * a stuck loop with a long error run is conclusive on its own, without
     * waiting for the error-rate or consecutive-failure thresholds to be
     * crossed. Routing that through the same transition keeps the state, the
     * open flag and the cooldown stamp in one place.
     */
    public function forceOpen(): void
    {
        if (self::STATE_OPEN !== $this->state()) {
            $this->transitionTo(self::STATE_OPEN);
        }
    }

    /**
     * Whether the last N outputs show no progress.
     *
     * Identical outputs are stagnation; so are N outputs that all read as
     * errors. Fewer than N outputs is never "no progress" — there is not enough
     * evidence yet.
     *
     * @param list<string> $outputs Recent output summaries, oldest first.
     */
    public function detectNoProgress(array $outputs): bool
    {
        $cycles = $this->config['no_progress_cycles'];

        if (count($outputs) < $cycles) {
            return false;
        }

        $recent = array_slice($outputs, -$cycles);

        if (1 === count(array_unique($recent))) {
            return true;
        }

        foreach ($recent as $output) {
            if (! $this->looksLikeError($output)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persist a transition.
     */
    private function transitionTo(string $state): void
    {
        $this->sessions->update(
            $this->sessionId,
            array(
                'circuit_breaker'      => $state,
                'circuit_breaker_open' => self::STATE_CLOSED !== $state,
                'circuit_opened_at'    => self::STATE_OPEN === $state ? time() : null,
            )
        );
    }

    /**
     * Whether the cooldown has elapsed since the circuit opened.
     *
     * A missing stamp answers "elapsed", which is the WordPress rule and keeps
     * a record written before this class existed from blocking forever.
     */
    private function resetTimeoutElapsed(): bool
    {
        $session = $this->sessions->find($this->sessionId);

        if (null === $session || empty($session['circuit_opened_at'])) {
            return true;
        }

        return ( time() - (int) $session['circuit_opened_at'] ) >= $this->config['reset_timeout_sec'];
    }

    /**
     * Whether a summary reads as a failure.
     */
    private function looksLikeError(string $output): bool
    {
        if ('' === $output) {
            return false;
        }

        $patterns = array(
            'error',
            'failed',
            'exception',
            'wp_error',
            'circuit breaker',
            'max iterations',
            'token budget',
            'not found',
            'permission denied',
            'unauthorized',
        );

        $lower = strtolower($output);

        foreach ($patterns as $pattern) {
            if (false !== strpos($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
