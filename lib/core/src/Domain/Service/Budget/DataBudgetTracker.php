<?php
/**
 * Data Budget Tracker — pure domain service.
 *
 * Contains the core accounting logic for tracking byte consumption against
 * budgets. Zero framework dependencies — callers inject budget values
 * directly rather than relying on platform-specific resolution.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Budget;

use Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface;

/**
 * Pure domain implementation of the data budget tracker.
 *
 * Per-request scoped. Construct with budget values or use defaults.
 *
 * @since 2.0.0
 */
final class DataBudgetTracker implements DataBudgetTrackerInterface
{
    /**
     * Overall request budget in bytes.
     */
    private int $requestBudget;

    /**
     * Per-message budget in bytes.
     */
    private int $perMessageBudget;

    /**
     * Bytes consumed in the current request.
     */
    private int $consumedBytes = 0;

    /**
     * Optional request identifier for diagnostics.
     */
    private string $requestId = '';

    /**
     * Number of spills observed.
     */
    private int $spillCounter = 0;

    /**
     * Constructor.
     *
     * @param int    $requestBudget    Overall request budget (bytes). Default 1 MiB.
     * @param int    $perMessageBudget Per-message budget (bytes). Default 64 KiB.
     * @param string $requestId        Optional request identifier.
     */
    public function __construct(
        int $requestBudget = self::DEFAULT_REQUEST_BUDGET_BYTES,
        int $perMessageBudget = self::DEFAULT_PER_MESSAGE_BUDGET_BYTES,
        string $requestId = ''
    ) {
        $this->requestBudget    = \max(1024, $requestBudget);
        $this->perMessageBudget = \max(512, $perMessageBudget);
        $this->requestId        = $requestId;
    }

    /**
     * {@inheritDoc}
     */
    public function getRequestBudget(): int
    {
        return $this->requestBudget;
    }

    /**
     * {@inheritDoc}
     */
    public function getPerMessageBudget(): int
    {
        return $this->perMessageBudget;
    }

    /**
     * {@inheritDoc}
     */
    public function record(int $bytes): void
    {
        $this->consumedBytes += \max(0, $bytes);
    }

    /**
     * {@inheritDoc}
     */
    public function consumed(): int
    {
        return $this->consumedBytes;
    }

    /**
     * {@inheritDoc}
     */
    public function remaining(): int
    {
        return \max(0, $this->requestBudget - $this->consumedBytes);
    }

    /**
     * {@inheritDoc}
     */
    public function isExhausted(): bool
    {
        return $this->consumedBytes >= $this->requestBudget;
    }

    /**
     * {@inheritDoc}
     */
    public function shouldSpill(int $bytes): bool
    {
        $bytes = \max(0, $bytes);

        if ($bytes > $this->perMessageBudget) {
            return true;
        }

        if (($this->consumedBytes + $bytes) > $this->requestBudget) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function noteSpill(): void
    {
        ++$this->spillCounter;
    }

    /**
     * {@inheritDoc}
     */
    public function spillCount(): int
    {
        return $this->spillCounter;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $requestId = ''): void
    {
        $this->consumedBytes = 0;
        $this->spillCounter  = 0;
        $this->requestId     = $requestId;
    }
}
