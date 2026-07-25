<?php
/**
 * Erlang C Queuing Theory — pure domain service.
 *
 * Stateless, zero-dependency implementation of the Erlang C formula for
 * M/M/c queue models. Uses log-sum-exp arithmetic to prevent floating-point
 * overflow for large agent counts.
 *
 * Formula reference:
 *   A. K. Erlang (1917) — teletraffic engineering model for M/M/c queues.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Optimization;

use Nvoos\Core\Domain\Contract\ErlangCInterface;

/**
 * Pure Erlang C calculator — no framework dependencies.
 *
 * @since 2.0.0
 */
final readonly class ErlangC implements ErlangCInterface
{
    /**
     * {@inheritDoc}
     */
    public function probabilityWait(float $trafficIntensity, int $agents): float
    {
        $a = $trafficIntensity;
        $n = $agents;

        if ($a <= 0.0) {
            return 0.0;
        }

        if ($n <= 0 || (float) $n <= $a) {
            return 1.0; // Unstable queue.
        }

        // log( A^N / N! * N/(N-A) )
        $logNum = $n * \log($a) - self::logFactorial($n) + \log((float) $n) - \log((float) $n - $a);

        // Sum of log( A^k / k! ) for k = 0 ... N-1.
        $logTerms   = [];
        $logKTerm   = 0.0; // k=0: A^0/0! = 1 -> log = 0
        $logTerms[] = $logKTerm;

        for ($k = 1; $k < $n; $k++) {
            $logKTerm  += \log($a) - \log((float) $k);
            $logTerms[] = $logKTerm;
        }

        // Log-sum-exp over denominator terms.
        $logTerms[] = $logNum;
        $maxLog     = \max($logTerms);

        $sumExp = 0.0;
        foreach ($logTerms as $lt) {
            $sumExp += \exp($lt - $maxLog);
        }

        $logDenom = $maxLog + \log($sumExp);
        $logC     = $logNum - $logDenom;

        return \min(1.0, \max(0.0, \exp($logC)));
    }

    /**
     * {@inheritDoc}
     */
    public function serviceLevel(
        float $trafficIntensity,
        int $agents,
        float $ahtSeconds,
        float $targetSeconds
    ): float {
        $a = $trafficIntensity;
        $n = $agents;
        $h = $ahtSeconds;
        $t = $targetSeconds;

        if ($a <= 0.0 || $h <= 0.0 || $t < 0.0) {
            return 0.0;
        }

        if ((float) $n <= $a) {
            return 0.0; // Unstable.
        }

        $c = $this->probabilityWait($a, $n);

        return 1.0 - $c * \exp(-((float) $n - $a) * ($t / $h));
    }

    /**
     * {@inheritDoc}
     */
    public function averageWaitTime(float $trafficIntensity, int $agents, float $ahtSeconds): float
    {
        $a = $trafficIntensity;
        $n = $agents;
        $h = $ahtSeconds;

        if ($h <= 0.0 || $a <= 0.0) {
            return 0.0;
        }

        if ((float) $n <= $a) {
            return \PHP_FLOAT_MAX; // Unstable.
        }

        $c = $this->probabilityWait($a, $n);

        return ($c * $h) / ((float) $n - $a);
    }

    /**
     * {@inheritDoc}
     */
    public function minAgentsForServiceLevel(
        float $trafficIntensity,
        float $ahtSeconds,
        float $targetServiceLevel,
        float $targetSeconds
    ): int {
        $a         = $trafficIntensity;
        $targetPct = $targetServiceLevel;
        $n         = \max(1, (int) \ceil($a) + 1);
        $maxN      = $n + self::MAX_AGENTS_CAP;

        while ($n <= $maxN) {
            if ($this->serviceLevel($a, $n, $ahtSeconds, $targetSeconds) >= $targetPct) {
                return $n;
            }
            ++$n;
        }

        return $n; // Capped result.
    }

    /**
     * {@inheritDoc}
     */
    public function toErlangs(float $arrivalRatePerHour, float $ahtSeconds): float
    {
        return $arrivalRatePerHour * $ahtSeconds / 3600.0;
    }

    /**
     * {@inheritDoc}
     */
    public function utilisation(float $trafficIntensity, int $agents): float
    {
        $n = $agents;

        if ($n <= 0) {
            return 0.0;
        }

        return \min(1.0, $trafficIntensity / (float) $n);
    }

    // ── Private Helpers ──────────────────────────────────────────────

    /**
     * Compute the natural log of N! using log-sum increments.
     *
     * @param int $n Non-negative integer.
     *
     * @return float log(N!)
     */
    private static function logFactorial(int $n): float
    {
        $result = 0.0;

        for ($i = 2; $i <= $n; $i++) {
            $result += \log((float) $i);
        }

        return $result;
    }
}
