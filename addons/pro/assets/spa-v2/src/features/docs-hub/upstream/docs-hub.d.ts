/**
 * Type declaration for the vendored NV oOS Docs Hub island bundle.
 *
 * `docs-hub.js` is byte-identical to `addons/docs-hub/assets/dist/docs-hub.js`
 * (see the sync discipline in the knowledge-graph skill). It is a
 * self-contained IIFE — its own React/Router/markdown pipeline — with no
 * module exports. Importing it executes it; the entry point then assigns the
 * imperative island API to `window.NVoosDocsHub`, which DocsPage calls once
 * its container is mounted.
 */

declare global {
	interface Window {
		NVoosDocsHub?: {
			/** Mount the Docs Hub SPA into `container`; returns teardown. */
			mountDocsHub( container: HTMLElement ): { unmount(): void };
		};
		/** Config injected by DocsPage before the island mounts. */
		NVOOS_DOCS_HUB?: {
			apiUrl: string;
			nonce: string;
			config: Record< string, unknown >;
		};
	}
}

export {};
