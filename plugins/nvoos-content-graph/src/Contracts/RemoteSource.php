<?php
declare(strict_types=1);

namespace NvoosContentGraph\Contracts;

/**
 * Contract for every remote-source driver.
 *
 * Drivers fetch nodes and edges from external data sources
 * and reconcile local entities with them.
 *
 * @since 1.0.0
 */
interface RemoteSource {

	/**
	 * Returns the unique driver identifier string (e.g. 'wikidata').
	 *
	 * @return string
	 */
	public function getDriverId(): string;

	/**
	 * Returns a human-readable label for this driver.
	 *
	 * @return string
	 */
	public function getDriverLabel(): string;

	/**
	 * Set the source-instance config (from DB row / admin form).
	 *
	 * @param array<string,mixed> $config Configuration array.
	 * @return void
	 */
	public function setConfig( array $config ): void;

	/**
	 * Get the current config array.
	 *
	 * @return array<string,mixed>
	 */
	public function getConfig(): array;

	/**
	 * Returns string[] of capability flags.
	 *
	 * Typical values: 'reconcile', 'fetch_nodes', 'fetch_edges', 'webhooks'.
	 *
	 * @return string[]
	 */
	public function getCapabilities(): array;

	/**
	 * Returns the JSON Schema array describing this driver's configuration fields.
	 *
	 * Used by the admin UI to render driver-specific input forms.
	 *
	 * @return array<string,mixed>
	 */
	public function getConfigSchema(): array;

	/**
	 * Test connectivity; returns ['success'=>bool,'message'=>string].
	 *
	 * @return array<string,mixed>
	 */
	public function testConnection(): array;

	/**
	 * Discover what is available at the remote (returns metadata array).
	 *
	 * @return array<string,mixed>
	 */
	public function discover(): array;

	/**
	 * Fetch nodes from the remote.
	 *
	 * Returns an array of node arrays compatible with the graph DB layer.
	 *
	 * @param array<string,mixed> $args Optional arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchNodes( array $args = array() ): array;

	/**
	 * Fetch edges from the remote.
	 *
	 * Returns an array of edge arrays compatible with the graph DB layer.
	 *
	 * @param array<string,mixed> $args Optional arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchEdges( array $args = array() ): array;

	/**
	 * Given a local node object, attempt reconciliation.
	 *
	 * @param object $localNode Local node object.
	 * @return array{external_id: string, confidence: float, matched: bool}
	 */
	public function reconcile( $localNode ): array;
}
