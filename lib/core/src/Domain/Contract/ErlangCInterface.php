<?php
/**
 * Erlang C Queuing Theory — domain contract.
 *
 * Pure mathematical computation of queue wait probabilities, service levels,
 * and staffing requirements using the Erlang C formula (M/M/c queue model).
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Erlang C queuing-theory calculator.
 *
 * All methods are stateless. Inputs use consistent SI units: arrival rate
 * in contacts per second, service time in seconds. Higher-level callers
 * convert from natural units before calling.
 *
 * @since 2.0.0
 */
interface ErlangCInterface
{
    /**
     * Maximum agents cap for min-agent search.
     */
    public const MAX_AGENTS_CAP = 500;

    /**
     * Compute the probability that an arriving contact must wait.
     *
     * Uses log-sum-exp arithmetic throughout to avoid floating-point overflow
     * for large agent counts.
     *
     * @param float $trafficIntensity Erlangs (A = lambda * AHT, dimensionless). Must be > 0.
     * @param int   $agents           Number of agents (N). Must be > A for a stable queue.
     *
     * @return float Probability of waiting [0.0, 1.0]. Returns 1.0 when N <= A (unstable).
     */
    public function probabilityWait(float $trafficIntensity, int $agents): float;

    /**
     * Compute the probability a contact is answered within target seconds.
     *
     * Service level = 1 - C(N,A) * exp(-(N - A) * target_s / AHT_s)
     *
     * @param float $trafficIntensity Erlangs.
     * @param int   $agents           Number of agents.
     * @param float $ahtSeconds       Average handle time in seconds.
     * @param float $targetSeconds    Target answer time threshold in seconds.
     *
     * @return float Service level [0.0, 1.0].
     */
    public function serviceLevel(
        float $trafficIntensity,
        int $agents,
        float $ahtSeconds,
        float $targetSeconds
    ): float;

    /**
     * Compute average queue wait time in seconds.
     *
     * W = C(N,A) * AHT / (N - A)
     *
     * @param float $trafficIntensity Erlangs.
     * @param int   $agents           Number of agents.
     * @param float $ahtSeconds       Average handle time in seconds.
     *
     * @return float Average wait time in seconds, or PHP_FLOAT_MAX when unstable.
     */
    public function averageWaitTime(float $trafficIntensity, int $agents, float $ahtSeconds): float;

    /**
     * Find minimum agents for a target service level.
     *
     * @param float $trafficIntensity Erlangs.
     * @param float $ahtSeconds       Average handle time in seconds.
     * @param float $targetServiceLevel Required service level fraction [0.0, 1.0].
     * @param float $targetSeconds    Answer-time threshold in seconds.
     *
     * @return int Minimum number of agents required.
     */
    public function minAgentsForServiceLevel(
        float $trafficIntensity,
        float $ahtSeconds,
        float $targetServiceLevel,
        float $targetSeconds
    ): int;

    /**
     * Convert arrival rate and AHT to Erlangs.
     *
     * A = lambda_per_hour * AHT_seconds / 3600
     *
     * @param float $arrivalRatePerHour Contacts arriving per hour.
     * @param float $ahtSeconds         Average handle time in seconds.
     *
     * @return float Traffic intensity in Erlangs.
     */
    public function toErlangs(float $arrivalRatePerHour, float $ahtSeconds): float;

    /**
     * Compute agent utilisation.
     *
     * @param float $trafficIntensity Erlangs.
     * @param int   $agents           Number of agents.
     *
     * @return float Utilisation fraction [0.0, 1.0].
     */
    public function utilisation(float $trafficIntensity, int $agents): float;
}
