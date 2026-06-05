<?php
/**
 * Remote source contract interface.
 *
 * Every remote data source driver must implement this interface.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Contracts;

/**
 * Defines the contract for a remote source driver.
 *
 * @since 1.0.0
 */
interface RemoteSource
{
    /**
     * Get the unique slug for this driver.
     *
     * @since 1.0.0
     * @return string
     */
    public function getSlug(): string;

    /**
     * Get the human-readable label.
     *
     * @since 1.0.0
     * @return string
     */
    public function getLabel(): string;

    /**
     * Get the configuration schema (JSON Schema).
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public function getConfigSchema(): array;

    /**
     * Fetch nodes from the remote source.
     *
     * @since 1.0.0
     * @param array<string,mixed> $config Driver configuration.
     * @return array<int,array<string,mixed>>|null Nodes array, or null on failure.
     */
    public function fetchNodes( array $config ): ?array;

    /**
     * Fetch edges from the remote source (optional).
     *
     * @since 1.0.0
     * @param array<string,mixed>            $config Driver configuration.
     * @param array<int,array<string,mixed>> $nodes  Previously fetched nodes.
     * @return array<int,array<string,mixed>> Edges array.
     */
    public function fetchEdges( array $config, array $nodes ): array;

    /**
     * Test the connection to the remote source.
     *
     * @since 1.0.0
     * @param array<string,mixed> $config Driver configuration.
     * @return bool True on successful connection.
     */
    public function testConnection( array $config ): bool;
}
