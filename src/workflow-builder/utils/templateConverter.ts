/**
 * Template Converter — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { __ } from '@wordpress/i18n';
import { generateNodeId } from './workflowHelpers';
import type { WorkflowNode, WorkflowEdge } from '../../shared/types';

interface TemplateStep { type: string; name?: string; description?: string; role?: string; roles?: string[]; }
interface Template { workflow?: TemplateStep[]; name?: string; description?: string; pattern?: string; roles?: string[]; }

export const convertTemplateToWorkflow = ( template: Template | null ): { nodes: WorkflowNode[]; edges: WorkflowEdge[] } => {
	if ( ! template?.workflow ) { return { nodes: [], edges: [] }; }
	const nodes: WorkflowNode[] = [];
	const edges: WorkflowEdge[] = [];
	let yPosition = 100;
	const ySpacing = 150, xSpacing = 300;
	const triggerNode: WorkflowNode = { id: generateNodeId( 'trigger' ), type: 'trigger', position: { x: 250, y: yPosition }, data: { label: __( 'Start' ), config: {} } };
	nodes.push( triggerNode ); let prev = triggerNode.id; yPosition += ySpacing;

	for ( const step of template.workflow ) {
		const nodeType = mapStepType( step.type );
		if ( step.type === 'parallel' && step.roles && step.roles.length > 1 ) {
			const paraIds: string[] = [];
			for ( let ri = 0; ri < step.roles.length; ri++ ) {
				const pid = generateNodeId( 'action' ); paraIds.push( pid );
				nodes.push( { id: pid, type: 'action', position: { x: 100 + ri * xSpacing, y: yPosition }, data: { label: step.roles[ ri ].replace( /_/g, ' ' ).replace( /\b\w/g, ( l ) => l.toUpperCase() ), config: { command: `/${ step.roles[ ri ] }`, params: '{}' } } } );
				edges.push( { id: `e-${ prev }-${ pid }`, source: prev, target: pid } );
			}
			yPosition += ySpacing;
			const mid = generateNodeId( 'merge' ); nodes.push( { id: mid, type: 'merge', position: { x: 250, y: yPosition }, data: { label: __( 'Merge Results' ), config: { strategy: 'all' } } } );
			for ( const pid of paraIds ) { edges.push( { id: `e-${ pid }-${ mid }`, source: pid, target: mid } ); }
			prev = mid; yPosition += ySpacing;
		} else {
			const nid = generateNodeId( nodeType ); nodes.push( { id: nid, type: nodeType, position: { x: 250, y: yPosition }, data: { label: step.name || step.description || nodeType, config: mapStepConfig( step, nodeType ) } } );
			edges.push( { id: `e-${ prev }-${ nid }`, source: prev, target: nid } );
			prev = nid; yPosition += ySpacing;
		}
	}
	return { nodes, edges };
};

const mapStepType = ( t: string ): string => {
	const m: Record< string, string > = { coordinate: 'agent', delegate: 'action', delegate_dynamic: 'tool', parallel: 'parallel', collaborate: 'agent', vote: 'agent', route: 'condition', validate: 'condition', monitor: 'trigger', respond: 'action', evaluate: 'agent' };
	return m[ t ] || 'action';
};

const mapStepConfig = ( step: TemplateStep, nodeType: string ): Record< string, string > => {
	switch ( nodeType ) {
		case 'action': return { command: step.role ? `/${ step.role }` : '/action', params: '{}' };
		case 'agent': return { agent_id: step.role || 'default', prompt: step.description || '' };
		case 'tool': return { tool_name: step.role || 'default_tool', arguments: '{}' };
		case 'condition': return { expression: 'result.status === "success"' };
		default: return {};
	}
};

export const getTemplatePreview = ( template: Template | null ): Record< string, unknown > | null => {
	if ( ! template ) { return null; }
	return { name: template.name, description: template.description, pattern: template.pattern, stepCount: template.workflow?.length || 0, roles: template.roles || [] };
};
