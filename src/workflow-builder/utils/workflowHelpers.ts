/**
 * Workflow Helper Utilities — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { __ } from '@wordpress/i18n';
import type { WorkflowNode, WorkflowEdge } from '../../shared/types';

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
	return errors;
};
