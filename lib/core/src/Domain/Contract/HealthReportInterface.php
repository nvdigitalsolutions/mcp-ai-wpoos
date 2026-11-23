<?php

/**
 * A host's health report, in the vocabulary WordPress Site Health uses.
 *
 * Core cannot probe a host it knows nothing about, and the tool that answers
 * `get_site_health` must not depend on `App\`. This contract is the seam: the
 * package host reports the two questions it can answer (tool registration and
 * the settings store), and the Laravel app replaces the binding with its full
 * Site Health probe set.
 *
 * ## The shape, and why it is fixed
 *
 * Each probe is `id`, `label`, `status`, `summary`, `detail`. `status` is one of
 * `good`, `recommended`, `critical` — the WordPress vocabulary, so an operator
 * who has seen Site Health does not have to learn a second one.
 *
 * ## Rules a probe must follow
 *
 * - **Never throw.** A health check that crashes the report is worse than the
 *   problem it reports.
 * - **Never return a secret.** Probes read configuration *flags* (is debug on,
 *   is a key set) and never a credential value.
 * - **An absent optional table is not a failure.** Report what is missing; let
 *   the caller decide whether it is critical.
 *
 * The tool aggregates the statuses and does not reinterpret them, so a host
 * that reports a probe as `critical` gets a `critical` report.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface HealthReportInterface
{
    /** A probe that passed. */
    public const GOOD = 'good';

    /** A probe that passed but has a recommendation attached. */
    public const RECOMMENDED = 'recommended';

    /** A probe that failed and needs attention. */
    public const CRITICAL = 'critical';

    /**
     * Run every probe this host offers.
     *
     * @return list<array{id: string, label: string, status: string, summary: string, detail: string}>
     */
    public function probes(): array;
}
