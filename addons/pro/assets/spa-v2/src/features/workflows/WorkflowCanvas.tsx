/**
 * WorkflowCanvas — the visual DAG canvas (Proposal 084 Phase 3b W10.2b).
 *
 * ReactFlow v12 board over the workflow graph: the three Engine V2 node
 * kinds, palette-driven add, drag-to-connect, selected-node rename, and a
 * validated save back through the workflow-builder REST. Heavy route —
 * lazy-mounted by the WorkflowsPage shell.
 */

import { useCallback, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import {
	ReactFlow,
	ReactFlowProvider,
	Background,
	Controls,
	MiniMap,
	addEdge,
	useNodesState,
	useEdgesState,
	MarkerType,
	type Connection,
	type Edge,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';

import type { WorkflowDetail, WorkflowGraph } from '../../api/workflow-builder';
import {
	NODE_TYPE_LABELS,
	nodeExecutePayload,
	toCanvasNode,
	toCanvasNodes,
	toGraphEdges,
	toGraphNode,
	validateGraph,
	WORKFLOW_NODE_TYPES,
	type CanvasWorkflowNode,
	type WorkflowNodeData,
	type WorkflowNodeType,
} from './workflow-graph';
import { ActionNode, AgentNode, ToolNode } from './WorkflowNode';
import { NodeEditor } from './NodeEditor';

const nodeTypes = {
	toolNode: ToolNode,
	actionNode: ActionNode,
	agentNode: AgentNode,
};

export interface WorkflowCanvasProps {
	workflow: WorkflowDetail;
	saving: boolean;
	onSave: ( graph: WorkflowGraph ) => Promise< void >;
	/** Run a single selected node (null payload = button disabled). */
	onRunNode?: ( node: CanvasWorkflowNode ) => void;
	nodeRunning?: boolean;
}

/** Fresh id for a palette-added node. */
function freshNodeId(): string {
	return `node_${ Date.now().toString( 36 ) }_${ Math.random().toString( 36 ).slice( 2, 7 ) }`;
}

export function WorkflowCanvas( props: WorkflowCanvasProps ): JSX.Element {
	const { workflow, saving, onSave, onRunNode, nodeRunning = false } = props;

	const [ nodes, setNodes, onNodesChange ] = useNodesState< CanvasWorkflowNode >(
		useMemo( () => toCanvasNodes( workflow.graph?.nodes ?? [] ), [ workflow.graph?.nodes ] )
	);
	const [ edges, setEdges, onEdgesChange ] = useEdgesState< Edge >(
		useMemo( () => toGraphEdges( workflow.graph?.edges ?? [] ), [ workflow.graph?.edges ] )
	);

	const [ selectedNodeId, setSelectedNodeId ] = useState< string | null >( null );
	const [ validationErrors, setValidationErrors ] = useState< string[] >( [] );

	const selectedNode = nodes.find( ( node ) => node.id === selectedNodeId ) ?? null;

	const addNode = useCallback(
		( type: WorkflowNodeType ) => {
			const id = freshNodeId();
			const index = nodes.length;
			const node = toCanvasNode(
				{ id, type, label: NODE_TYPE_LABELS[ type ] },
				index
			);
			setNodes( ( current ) => [ ...current, node ] );
			setSelectedNodeId( id );
		},
		[ nodes.length, setNodes ]
	);

	const onConnect = useCallback(
		( connection: Connection ) => {
			setEdges( ( current ) =>
				addEdge(
					{
						...connection,
						markerEnd: { type: MarkerType.ArrowClosed },
					},
					current
				)
			);
		},
		[ setEdges ]
	);

	const onNodeClick = useCallback(
		( _event: React.MouseEvent, node: CanvasWorkflowNode ) => {
			setSelectedNodeId( node.id );
		},
		[]
	);

	const renameSelected = useCallback(
		( label: string ) => {
			if ( ! selectedNodeId ) {
				return;
			}
			setNodes( ( current ) =>
				current.map( ( node ) =>
					node.id === selectedNodeId
						? { ...node, data: { ...node.data, label } }
						: node
				)
			);
		},
		[ selectedNodeId, setNodes ]
	);

	// Merge an arbitrary configuration patch into the selected node's data
	// (the NodeEditor writes toolSlug/arguments/command/params/agentId/prompt).
	const patchSelected = useCallback(
		( patch: Partial< WorkflowNodeData > ) => {
			if ( ! selectedNodeId ) {
				return;
			}
			setNodes( ( current ) =>
				current.map( ( node ) =>
					node.id === selectedNodeId
						? { ...node, data: { ...node.data, ...patch } }
						: node
				)
			);
		},
		[ selectedNodeId, setNodes ]
	);

	const deleteSelected = useCallback( () => {
		if ( ! selectedNodeId ) {
			return;
		}
		setNodes( ( current ) => current.filter( ( node ) => node.id !== selectedNodeId ) );
		setEdges( ( current ) =>
			current.filter(
				( edge ) => edge.source !== selectedNodeId && edge.target !== selectedNodeId
			)
		);
		setSelectedNodeId( null );
	}, [ selectedNodeId, setNodes, setEdges ] );

	const saveGraph = useCallback( async () => {
		const check = validateGraph( nodes, edges );
		if ( ! check.valid ) {
			setValidationErrors( check.errors );
			return;
		}
		setValidationErrors( [] );
		await onSave( {
			nodes: nodes.map( toGraphNode ),
			edges: edges.map( ( edge ) => ( {
				id: edge.id,
				source: edge.source,
				target: edge.target,
			} ) ),
		} );
	}, [ nodes, edges, onSave ] );

	return (
		<div className="nvoos-pro-spa-wf-canvas" role="region" aria-label="Workflow canvas">
			<div className="nvoos-pro-spa-wf-canvas__toolbar">
				<div className="nvoos-pro-spa-wf-canvas__palette" role="group" aria-label="Add node">
					{ WORKFLOW_NODE_TYPES.map( ( type ) => (
						<button
							key={ type }
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							onClick={ () => addNode( type ) }
						>
							+ { NODE_TYPE_LABELS[ type ] }
						</button>
					) ) }
					{ selectedNode && (
						<>
							<input
								type="text"
								className="nvoos-pro-spa-assistants-form__input nvoos-pro-spa-wf-canvas__rename"
								value={ selectedNode.data?.label ?? '' }
								aria-label="Node label"
								onChange={ ( event ) => renameSelected( event.target.value ) }
							/>
							{ onRunNode && (
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ nodeRunning || ! nodeExecutePayload( selectedNode, String( workflow.id ) ) }
									onClick={ () => onRunNode( selectedNode ) }
								>
									{ __( 'Run node', 'nvoos-pro-spa' ) }
								</button>
							) }
							<button
								type="button"
								className="nvoos-pro-spa-token-manager__action nvoos-pro-spa-token-manager__action--danger"
								onClick={ deleteSelected }
							>
								Delete node
							</button>
						</>
					) }
					<button
						type="button"
						className="nvoos-pro-spa-token-manager__action"
						disabled={ saving }
						onClick={ () => void saveGraph() }
					>
						{ saving ? 'Saving…' : 'Save graph' }
					</button>
				</div>
			</div>

			{ validationErrors.length > 0 && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<ul>
						{ validationErrors.map( ( message ) => (
							<li key={ message }>{ message }</li>
						) ) }
					</ul>
				</div>
			) }

			{ selectedNode && (
				<NodeEditor
					key={ selectedNode.id + ':' + ( selectedNode.type ?? '' ) }
					node={ selectedNode }
					onPatch={ patchSelected }
				/>
			) }

			<div className="nvoos-pro-spa-wf-canvas__board">
				<ReactFlow
					nodes={ nodes }
					edges={ edges }
					nodeTypes={ nodeTypes }
					onNodesChange={ onNodesChange }
					onEdgesChange={ onEdgesChange }
					onConnect={ onConnect }
					onNodeClick={ onNodeClick }
					onPaneClick={ () => setSelectedNodeId( null ) }
					fitView
					proOptions={ { hideAttribution: true } }
				>
					<Background gap={ 20 } />
					<Controls />
					<MiniMap pannable zoomable />
				</ReactFlow>
			</div>
		</div>
	);
}

/** Provider-wrapped export — <Handle/> needs ReactFlowProvider ancestry. */
export function WorkflowCanvasWithProvider( props: WorkflowCanvasProps ): JSX.Element {
	return (
		<ReactFlowProvider>
			<WorkflowCanvas { ...props } />
		</ReactFlowProvider>
	);
}
