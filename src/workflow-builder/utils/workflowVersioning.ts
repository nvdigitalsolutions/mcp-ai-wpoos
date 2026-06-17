/**
 * Workflow Versioning Manager — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import type { WorkflowNode, WorkflowEdge } from '../../shared/types';

interface WorkflowVersion {
	id: string; timestamp: number; name?: string; description?: string;
	nodes: WorkflowNode[]; edges: WorkflowEdge[]; note: string; nodeCount: number; edgeCount: number;
}

interface VersionChanges { nodesAdded: number; nodesRemoved: number; nodesModified: number; edgesAdded: number; edgesRemoved: number; }

export const createVersion = ( data: { name?: string; description?: string; nodes: WorkflowNode[]; edges: WorkflowEdge[] }, note = '' ): WorkflowVersion => {
	return { id: `v-${ Date.now() }`, timestamp: Date.now(), name: data.name, description: data.description, nodes: JSON.parse( JSON.stringify( data.nodes ) ), edges: JSON.parse( JSON.stringify( data.edges ) ), note, nodeCount: data.nodes.length, edgeCount: data.edges.length };
};

export const compareVersions = ( v1: WorkflowVersion, v2: WorkflowVersion ): VersionChanges => {
	const ch: VersionChanges = { nodesAdded: 0, nodesRemoved: 0, nodesModified: 0, edgesAdded: 0, edgesRemoved: 0 };
	const v1Ids = new Set( v1.nodes.map( ( n ) => n.id ) );
	const v2Ids = new Set( v2.nodes.map( ( n ) => n.id ) );
	for ( const n of v2.nodes ) { if ( ! v1Ids.has( n.id ) ) { ch.nodesAdded++; } }
	for ( const n of v1.nodes ) { if ( ! v2Ids.has( n.id ) ) { ch.nodesRemoved++; } }
	for ( const v1n of v1.nodes ) { const v2n = v2.nodes.find( ( n ) => n.id === v1n.id ); if ( v2n && JSON.stringify( v1n.data ) !== JSON.stringify( v2n.data ) ) { ch.nodesModified++; } }
	const e1Ids = new Set( v1.edges.map( ( e ) => `${ e.source }-${ e.target }` ) );
	const e2Ids = new Set( v2.edges.map( ( e ) => `${ e.source }-${ e.target }` ) );
	for ( const e of v2.edges ) { if ( ! e1Ids.has( `${ e.source }-${ e.target }` ) ) { ch.edgesAdded++; } }
	for ( const e of v1.edges ) { if ( ! e2Ids.has( `${ e.source }-${ e.target }` ) ) { ch.edgesRemoved++; } }
	return ch;
};

export const formatVersion = ( v: WorkflowVersion ) => ( { ...v, formattedDate: new Date( v.timestamp ).toLocaleString(), summary: `${ v.nodeCount } nodes, ${ v.edgeCount } connections` } );

export const saveVersionToLocal = ( workflowId: string, version: WorkflowVersion ): boolean => {
	try { const k = `workflow_versions_${ workflowId }`; const vs = getVersionsFromLocal( workflowId ); vs.push( version ); if ( vs.length > 10 ) { vs.shift(); } localStorage.setItem( k, JSON.stringify( vs ) ); return true; }
	catch { return false; }
};

export const getVersionsFromLocal = ( workflowId: string ): WorkflowVersion[] => {
	try { const d = localStorage.getItem( `workflow_versions_${ workflowId }` ); return d ? JSON.parse( d ) : []; }
	catch { return []; }
};

export const restoreVersion = ( v: WorkflowVersion ): { nodes: WorkflowNode[]; edges: WorkflowEdge[]; name?: string; description?: string } => {
	return { nodes: JSON.parse( JSON.stringify( v.nodes ) ), edges: JSON.parse( JSON.stringify( v.edges ) ), name: v.name, description: v.description };
};
