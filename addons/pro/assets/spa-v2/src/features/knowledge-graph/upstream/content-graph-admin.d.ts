/**
 * Type declaration for the vendored explorer factory
 * (upstream/content-graph-admin.js).
 *
 * The upstream file is a jQuery + Cytoscape implementation ported verbatim
 * from the NV oOS Content Graph plugin; the SPA only touches it through the
 * exported factory and the returned teardown handle.
 */

/** Handle returned by the explorer factory — the only teardown entry point. */
export interface NvoosGraphExplorerHandle {
	/** Destroy the Cytoscape instance, timers, and document listeners. */
	destroy: () => void;
}

/**
 * Initialise the explorer on the markup rendered by KnowledgeGraphPage.
 *
 * Reads `window.nvoosContentGraphAdmin` (config), `window.nvoosContentGraphTheme`
 * and `window.nvoosContentGraphIcons` (set by the side-effect imports), and
 * `window.cytoscape` (set by the page with the fcose extension registered).
 *
 * @param $ jQuery instance (the page imports jquery itself and passes it in).
 */
export function initNvoosGraphExplorer( $: unknown ): NvoosGraphExplorerHandle;
