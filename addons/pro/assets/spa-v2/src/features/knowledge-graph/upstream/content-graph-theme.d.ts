/**
 * Type declaration for the vendored theme engine (upstream/content-graph-theme.js).
 *
 * The theme engine is a side-effect module: it attaches its API to
 * `window.nvoosContentGraphTheme` at import time. It reads the `visual`
 * config object delivered in `window.nvoosContentGraphAdmin.visual`.
 */

export {};

declare global {
	interface Window {
		/** Theme engine API attached by upstream/content-graph-theme.js. */
		nvoosContentGraphTheme?: Record< string, ( ...args: unknown[] ) => unknown >;
		/** Icon glyph registry attached by upstream/content-graph-icons.js. */
		nvoosContentGraphIcons?: {
			catalog?: Record< string, { label?: string; d?: string } >;
		};
		/** Explorer config the page writes before initialising the factory. */
		nvoosContentGraphAdmin?: Record< string, unknown >;
		/** Cytoscape core factory (with the fcose extension registered). */
		cytoscape?: unknown;
	}
}
