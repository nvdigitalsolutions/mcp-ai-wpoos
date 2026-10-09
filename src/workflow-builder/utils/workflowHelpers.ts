/**
 * Workflow Helper Utilities — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { __ } from '@wordpress/i18n';
import type { WorkflowConfig, WorkflowNode, WorkflowEdge } from '../../shared/types';

/**
 * A workflow serialised to / parsed from a JSON file.
 */
export interface WorkflowFileData extends WorkflowConfig {
	name: string;
	description?: string;
}

export const generateNodeId = ( type: string ): string => {
	return `${ type }-${ Date.now() }-${ Math.floor( Math.random() * 10000 ) }`;
};

export const validateWorkflow = ( nodes: WorkflowNode[], edges: WorkflowEdge[] ): string[] => {
	const errors: string[] = [];
	if ( ! nodes.length ) { errors.push( __( 'Workflow must have at least one node' ) ); return errors; }
	const hasTrigger = nodes.some( ( n ) => n.type === 'trigger' );
	if ( ! hasTrigger ) { errors.push( __( 'Workflow must have a trigger node' ) ); }
	for ( const node of nodes ) {
		if ( node.type === 'trigger' ) {
			if ( ! edges.some( ( e ) => e.source === node.id ) ) { errors.push( __( `Trigger node "${ node.data.label }" has no connections` ) ); }
		} else {
			if ( ! edges.some( ( e ) => e.target === node.id ) ) { errors.push( __( `Node "${ node.data.label }" has no incoming connection` ) ); }
		}
	}

	// Check for nodes with required configuration.
	for ( const node of nodes ) {
		const config = ( node.data.config ?? {} ) as Record< string, unknown >;
		if ( node.type === 'action' && ! config.command ) { errors.push( __( `Action node "${ node.data.label }" is missing command` ) ); }
		if ( node.type === 'condition' && ! config.expression ) { errors.push( __( `Condition node "${ node.data.label }" is missing expression` ) ); }
		if ( node.type === 'loop' && ! config.items ) { errors.push( __( `Loop node "${ node.data.label }" is missing items configuration` ) ); }
	}

	// Check for circular dependencies (basic DFS cycle detection).
	const visited = new Set< string >();
	const recursionStack = new Set< string >();

	const hasCycle = ( nodeId: string ): boolean => {
		if ( visited.has( nodeId ) ) { return false; }
		visited.add( nodeId );
		recursionStack.add( nodeId );
		for ( const edge of edges.filter( ( e ) => e.source === nodeId ) ) {
			if ( recursionStack.has( edge.target ) ) { return true; }
			if ( hasCycle( edge.target ) ) { return true; }
		}
		recursionStack.delete( nodeId );
		return false;
	};

	for ( const trigger of nodes.filter( ( n ) => n.type === 'trigger' ) ) {
		if ( hasCycle( trigger.id ) ) {
			errors.push( __( 'Workflow contains circular dependencies' ) );
			break;
		}
	}

	return errors;
};

/**
 * Export a workflow as a JSON file download.
 *
 * Serialises the workflow, creates a Blob URL, triggers a download named
 * after the workflow (or `workflow.json`) and cleans up afterwards.
 */
export const exportWorkflow = ( workflow: WorkflowFileData ): void => {
	const json = JSON.stringify( workflow, null, 2 );
	const blob = new Blob( [ json ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `${ workflow.name || 'workflow' }.json`;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
};

/**
 * Import a workflow from a JSON file.
 *
 * Reads the file as text and parses it as JSON, rejecting with a translated
 * error message when the file is malformed or unreadable.
 */
export const importWorkflow = ( file: File ): Promise< WorkflowFileData > => {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = ( event ) => {
			try {
				const workflow = JSON.parse( String( event.target?.result ?? '' ) ) as WorkflowFileData;
				resolve( workflow );
			} catch ( error ) {
				reject( new Error( __( 'Invalid workflow file' ) ) );
			}
		};
		reader.onerror = () => {
			reject( new Error( __( 'Failed to read file' ) ) );
		};
		reader.readAsText( file );
	} );
};
