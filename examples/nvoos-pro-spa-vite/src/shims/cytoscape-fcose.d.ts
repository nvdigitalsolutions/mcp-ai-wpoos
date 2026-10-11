/**
 * Type shim for cytoscape-fcose (no bundled types).
 *
 * The standalone app typechecks the spa-v2 sources through the import graph
 * (the addon's own d.ts is not part of this program), so the ambient module
 * declaration has to live here too. The package's default export is the
 * fcose layout extension, registered via `cytoscape.use( fcose )`.
 */

declare module 'cytoscape-fcose' {
	import type cytoscape from 'cytoscape';

	const fcose: cytoscape.Ext;
	export default fcose;
}
