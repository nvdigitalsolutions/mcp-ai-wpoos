<?php
/**
 * Orchestration Control — domain contract.
 *
 * Budget enforcement, presets, health monitoring, and depth scheduling
 * for the agentic orchestration loop.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface OrchestrationControlInterface
{
    /**
     * Check whether budget management is enabled.
     */
    public function isBudgetEnabled(): bool;

    /**
     * Get an orchestration preset by name.
     *
     * @return array{found: bool, preset?: array<string, mixed>}
     */
    public function getPreset(string $name): array;

    /**
     * Save an orchestration preset.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, error?: string}
     */
    public function savePreset(string $name, array $config): array;

    /**
     * List all available presets.
     *
     * @return array<int, array{name: string, description: string, config: array<string, mixed>}>
     */
    public function listPresets(): array;

    /**
     * Get the current orchestration health status.
     *
     * @return array{healthy: bool, depth: int, active_jobs: int, alerts: array<int, mixed>}
     */
    public function healthCheck(): array;

    /**
     * Get the current orchestration depth (agentic loop iteration count).
     */
    public function getDepth(): int;

    /**
     * Set the maximum orchestration depth.
     */
    public function setMaxDepth(int $maxIterations): void;
}
