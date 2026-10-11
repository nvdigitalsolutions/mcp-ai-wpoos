/**
 * Type shim for cytoscape-fcose (no bundled types).
 *
 * The package's default export is the fcose layout extension, registered
 * onto a cytoscape core via `cytoscape.use( fcose )`.
 */

declare module 'cytoscape-fcose' {
	import type cytoscape from 'cytoscape';

	const fcose: cytoscape.Ext;
	export default fcose;
}
