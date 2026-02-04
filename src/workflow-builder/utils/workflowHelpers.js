/**
 * Workflow Helper Utilities
 *
 * Utility functions for workflow management.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Generate unique node ID
 */
export const generateNodeId = ( type ) => {
	const timestamp = Date.now();
	const random = Math.floor( Math.random() * 10000 );
	return `${type}-${timestamp}-${random}`;
};

/**
 * Validate workflow structure
 */
export const validateWorkflow = ( nodes, edges ) => {
	const errors = [];

	// Check if workflow has nodes
	if ( nodes.length === 0 ) {
		errors.push( __( 'Workflow must have at least one node', 'mcp-ai-wpoos' ) );
		return errors;
	}

	// Check for trigger node
	const hasTrigger = nodes.some( ( node ) => node.type === 'trigger' );
	if ( ! hasTrigger ) {
		errors.push( __( 'Workflow must have a trigger node', 'mcp-ai-wpoos' ) );
	}

	// Check for disconnected nodes (except trigger)
	nodes.forEach( ( node ) => {
		if ( node.type === 'trigger' ) {
			// Trigger must have outgoing connection
			const hasOutgoing = edges.some( ( edge ) => edge.source === node.id );
			if ( ! hasOutgoing ) {
				errors.push( __( `Trigger node "${node.data.label}" has no connections`, 'mcp-ai-wpoos' ) );
			}
		} else {
			// Other nodes must have incoming connection
			const hasIncoming = edges.some( ( edge ) => edge.target === node.id );
			if ( ! hasIncoming ) {
				errors.push( __( `Node "${node.data.label}" is not connected to workflow`, 'mcp-ai-wpoos' ) );
			}
		}
	} );

	// Check for nodes with required config
	nodes.forEach( ( node ) => {
		if ( node.type === 'action' && ! node.data.config?.command ) {
			errors.push( __( `Action node "${node.data.label}" is missing command`, 'mcp-ai-wpoos' ) );
		}

		if ( node.type === 'condition' && ! node.data.config?.expression ) {
			errors.push( __( `Condition node "${node.data.label}" is missing expression`, 'mcp-ai-wpoos' ) );
		}

		if ( node.type === 'loop' && ! node.data.config?.items ) {
			errors.push( __( `Loop node "${node.data.label}" is missing items configuration`, 'mcp-ai-wpoos' ) );
		}
	} );

	// Check for circular dependencies (basic check)
	const visited = new Set();
	const recursionStack = new Set();
	
	const hasCycle = ( nodeId ) => {
		if ( ! visited.has( nodeId ) ) {
			visited.add( nodeId );
			recursionStack.add( nodeId );

			const outgoingEdges = edges.filter( ( edge ) => edge.source === nodeId );
			for ( const edge of outgoingEdges ) {
				if ( ! visited.has( edge.target ) && hasCycle( edge.target ) ) {
					return true;
				} else if ( recursionStack.has( edge.target ) ) {
					return true;
				}
			}
		}
		recursionStack.delete( nodeId );
		return false;
	};

	const triggerNodes = nodes.filter( ( node ) => node.type === 'trigger' );
	for ( const trigger of triggerNodes ) {
		if ( hasCycle( trigger.id ) ) {
			errors.push( __( 'Workflow contains circular dependencies', 'mcp-ai-wpoos' ) );
			break;
		}
	}

	return errors;
};

/**
 * Export workflow to JSON
 */
export const exportWorkflow = ( workflow ) => {
	const json = JSON.stringify( workflow, null, 2 );
	const blob = new Blob( [json], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `${workflow.name || 'workflow'}.json`;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
};

/**
 * Import workflow from JSON
 */
export const importWorkflow = ( file ) => {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = ( e ) => {
			try {
				const workflow = JSON.parse( e.target.result );
				resolve( workflow );
			} catch ( error ) {
				reject( new Error( __( 'Invalid workflow file', 'mcp-ai-wpoos' ) ) );
			}
		};
		reader.onerror = () => {
			reject( new Error( __( 'Failed to read file', 'mcp-ai-wpoos' ) ) );
		};
		reader.readAsText( file );
	} );
};
