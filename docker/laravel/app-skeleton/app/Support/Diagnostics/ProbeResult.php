<?php

namespace App\Support\Diagnostics;

/**
 * One rendered probe result — Proposal 149 §48.
 *
 * The contract's `platform-operations` projection says: "Probe surfaces return
 * a rendered result summary, not raw payloads or credentials." This object is
 * that rule in code. It has no place to put a raw provider body, a URL with a
 * query string, or a credential, so a probe cannot leak one by accident.
 *
 * `status` reuses the Site Health vocabulary on purpose (`good`, `recommended`,
 * `critical`), so an operator who has seen `/ops/site-health` does not have to
 * learn a second scale.
 */
final class ProbeResult
{
    public const GOOD = 'good';

    public const RECOMMENDED = 'recommended';

    public const CRITICAL = 'critical';

    /**
     * @param string $probe      The probe key that produced this result.
     * @param string $label      Human label, e.g. "Provider connectivity".
     * @param string $status     One of the three constants.
     * @param string $summary    One sentence an operator can act on.
     * @param array<int, array{label: string, value: string}> $rows Detail, already safe to render.
     * @param int    $durationMs Wall-clock time the probe took.
     */
    public function __construct(
        public readonly string $probe,
        public readonly string $label,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $rows,
        public readonly int $durationMs,
    ) {
    }
}
