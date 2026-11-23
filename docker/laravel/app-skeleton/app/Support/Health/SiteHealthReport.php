<?php

namespace App\Support\Health;

use Nvoos\Core\Domain\Contract\HealthReportInterface;

/**
 * The app's health probes, behind the core contract — Proposal 090-M §12.
 *
 * `SiteHealth::probes()` already produces the shape the contract specifies:
 * `id`, `label`, `status`, `summary`, `detail`, in the WordPress vocabulary.
 * This adapter exists so `Nvoos\Core\Tool\GetSiteHealthTool` can depend on the
 * contract without knowing that `App\Support\Health` exists, and so the page and
 * the tool render one set of probes by construction.
 */
final class SiteHealthReport implements HealthReportInterface
{
    /**
     * {@inheritDoc}
     */
    public function probes(): array
    {
        return SiteHealth::probes();
    }
}
