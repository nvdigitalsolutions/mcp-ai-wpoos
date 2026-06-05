<?php
/**
 * Remote source driver contract.
 *
 * Every remote data source driver must implement this interface.
 * Drivers fetch nodes and edges from external data sources and
 * reconcile local entities with them.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Contracts;

/**
 * Interface that every remote-source driver must implement.
 *
 * @since 1.0.0
 */
interface RemoteSourceDriver
{
    /**
     * Get the unique driver identifier (e.g. 'wikidata', 'oos_federation').
     *
     * @since 1.0.0
     * @return string
     */
    public function getDriverId(): string;

    /**
     * Get a human-readable label for this driver.
     *
     * @since 1.0.0
     * @return string
     */
    public function getDriverLabel(): string;

    /**
     * Set the source-instance config (from DB row / admin form).
     *
     * @since 1.0.0
     * @param array<string,mixed> $config Configuration array.
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Get the current config array.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public function getConfig(): array;

    /**
     * Get capability flags as string array.
     *
     * @since 1.0.0
     * @return string[] e.g. ['reconcile', 'fetch_nodes', 'fetch_edges', 'webhooks']
     */
    public function getCapabilities(): array;

    /**
     * Get the JSON Schema describing this driver's configuration fields.
     *
     * Used by the admin UI to render driver-specific input forms.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public function getConfigSchema(): array;

    /**
     * Test connectivity.
     *
     * @since 1.0.0
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;

    /**
     * Discover what is available at the remote source.
     *
     * @since 1.0.0
     * @return array<string,mixed> Metadata array.
     */
    public function discover(): array;

    /**
     * Fetch nodes from the remote source.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args Optional arguments.
     * @return array<int,array<string,mixed>>|null Node arrays, or null on failure.
     */
    public function fetchNodes(array $args = array()): ?array;

    /**
     * Fetch edges from the remote source.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args Optional arguments.
     * @return array<int,array<string,mixed>> Edge arrays.
     */
    public function fetchEdges(array $args = array()): array;

    /**
     * Attempt to reconcile a local node with the remote source.
     *
     * @since 1.0.0
     * @param object $localNode Local node object.
     * @return array{external_id: string, confidence: float, matched: bool}
     */
    public function reconcile(object $localNode): array;
}
