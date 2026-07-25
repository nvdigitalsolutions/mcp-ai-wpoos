<?php
/**
 * Error Tracking Service — domain contract.
 *
 * Centralized error tracking with rate calculation and retention.
 * Implementations decide storage (options, database, external service).
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Tracks, stores, and analyzes errors across the application.
 *
 * @since 2.0.0
 */
interface ErrorTrackingServiceInterface
{
    /**
     * Maximum errors to retain.
     */
    public const MAX_STORED_ERRORS = 1000;

    /**
     * Default retention period in seconds (7 days).
     */
    public const RETENTION_PERIOD = 604800;

    /**
     * Track an error occurrence.
     *
     * @param string $component Component where the error occurred.
     * @param string $message   Human-readable error message.
     * @param array  $context   Additional structured context.
     *
     * @return string Unique error ID.
     */
    public function track(string $component, string $message, array $context = []): string;

    /**
     * Retrieve recent errors, newest first.
     *
     * @param int $limit Maximum number of errors to return.
     *
     * @return array<int, array{id: string, component: string, message: string, context: array, timestamp: int}>
     */
    public function getRecent(int $limit = 50): array;

    /**
     * Calculate error rate for a component over a time window.
     *
     * @param string $component  Component slug (empty = all components).
     * @param int    $windowSeconds Time window in seconds.
     *
     * @return float Errors per second.
     */
    public function getRate(string $component = '', int $windowSeconds = 3600): float;

    /**
     * Clear all stored errors.
     */
    public function clear(): void;

    /**
     * Whether error tracking is currently enabled.
     */
    public function isEnabled(): bool;
}
