/**
 * Template Converter
 *
 * Converts pattern-based templates to ReactFlow workflow format.
 *
 * @package WP_MCP_AI
 * @since 2.1.0
 */

import { __ } from '@wordpress/i18n';
import { generateNodeId } from './workflowHelpers';

/**
 * Convert pattern template to workflow nodes and edges
 */
export const convertTemplateToWorkflow = ( template ) => {
	if ( ! template || ! template.workflow ) {
		return { nodes: [], edges: [] };
	}

	const nodes = [];
	const edges = [];
	let yPosition = 100;
	const xSpacing = 300;
	const ySpacing = 150;

	// Create trigger node
	const triggerNode = {
		id: generateNodeId( 'trigger' ),
		type: 'trigger',
		position: { x: 250, y: yPosition },
		data: {
			label: __( 'Start', 'mcp-ai-wpoos' ),
			config: {},
		},
	};
	nodes.push( triggerNode );
	let previousNodeId = triggerNode.id;
	yPosition += ySpacing;

	// Convert workflow steps to nodes
	template.workflow.forEach( ( step, index ) => {
		const nodeType = mapStepTypeToNodeType( step.type );
		const nodeId = generateNodeId( nodeType );

		// Calculate position based on step type
		let xPosition = 250;
		if ( step.type === 'parallel' && step.roles && step.roles.length > 1 ) {
			// Create multiple parallel nodes
			const parallelNodeIds = [];
			step.roles.forEach( ( role, roleIndex ) => {
				const parallelNodeId = generateNodeId( 'action' );
				const parallelNode = {
					id: parallelNodeId,
					type: 'action',
					position: {
						x: 100 + ( roleIndex * xSpacing ),
						y: yPosition,
					},
					data: {
						label: role.replace( /_/g, ' ' ).replace( /\b\w/g, ( l ) => l.toUpperCase() ),
						config: {
							command: `/${role}`,
							params: '{}',
						},
					},
				};
				nodes.push( parallelNode );
				parallelNodeIds.push( parallelNodeId );

				// Connect from previous node
				edges.push( {
					id: `edge-${previousNodeId}-${parallelNodeId}`,
					source: previousNodeId,
					target: parallelNodeId,
					animated: true,
				} );
			} );

			// Create merge node after parallel execution
			yPosition += ySpacing;
			const mergeNodeId = generateNodeId( 'merge' );
			const mergeNode = {
				id: mergeNodeId,
				type: 'merge',
				position: { x: 250, y: yPosition },
				data: {
					label: __( 'Merge Results', 'mcp-ai-wpoos' ),
					config: { strategy: 'all' },
				},
			};
			nodes.push( mergeNode );

			// Connect parallel nodes to merge
			parallelNodeIds.forEach( ( parallelNodeId ) => {
				edges.push( {
					id: `edge-${parallelNodeId}-${mergeNodeId}`,
					source: parallelNodeId,
					target: mergeNodeId,
					animated: true,
				} );
			} );

			previousNodeId = mergeNodeId;
			yPosition += ySpacing;
		} else {
			// Single node
			const node = {
				id: nodeId,
				type: nodeType,
				position: { x: xPosition, y: yPosition },
				data: {
					label: step.name || step.description || nodeType,
					config: mapStepConfigToNodeConfig( step, nodeType ),
				},
			};
			nodes.push( node );

			// Connect to previous node
			edges.push( {
				id: `edge-${previousNodeId}-${nodeId}`,
				source: previousNodeId,
				target: nodeId,
				animated: true,
			} );

			previousNodeId = nodeId;
			yPosition += ySpacing;
		}
	} );

	return { nodes, edges };
};

/**
 * Map step type to node type
 */
const mapStepTypeToNodeType = ( stepType ) => {
	const typeMap = {
		coordinate: 'agent',
		delegate: 'action',
		delegate_dynamic: 'tool',
		parallel: 'parallel',
		collaborate: 'agent',
		vote: 'agent',
		route: 'condition',
		validate: 'condition',
		monitor: 'trigger',
		respond: 'action',
		evaluate: 'agent',
	};

	return typeMap[ stepType ] || 'action';
};

/**
 * Map step configuration to node configuration
 */
const mapStepConfigToNodeConfig = ( step, nodeType ) => {
	const config = {};

	switch ( nodeType ) {
		case 'action':
			config.command = step.role ? `/${step.role}` : '/action';
			config.params = '{}';
			break;

		case 'agent':
			config.agent_id = step.role || 'default';
			config.prompt = step.description || '';
			break;

		case 'tool':
			config.tool_name = step.role || 'default_tool';
			config.arguments = '{}';
			break;

		case 'condition':
			config.expression = 'result.status === "success"';
			break;

		default:
			break;
	}

	return config;
};

/**
 * Get template preview data
 */
export const getTemplatePreview = ( template ) => {
	if ( ! template ) {
		return null;
	}

	return {
		name: template.name,
		description: template.description,
		pattern: template.pattern,
		stepCount: template.workflow ? template.workflow.length : 0,
		roles: template.roles || [],
	};
};
