/**
 * Workflow node components — the three Engine V2 node kinds
 * (Phase 3b W10.2b). Headless-styled; data is a type alias per the
 * @xyflow/react v12 constraint.
 */

import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { JSX } from 'react';

import { NODE_TYPE_LABELS, type CanvasWorkflowNode, type WorkflowNodeType } from './workflow-graph';

function WorkflowNodeShell( {
	nodeType,
	accent,
	label,
	description,
	rawType,
	selected,
}: {
	nodeType: WorkflowNodeType;
	accent: string;
	label: string;
	description?: string;
	rawType?: string;
	selected?: boolean;
} ): JSX.Element {
	const kind = rawType || NODE_TYPE_LABELS[ nodeType ];
	return (
		<div
			className={ `nvoos-pro-spa-wf-node nvoos-pro-spa-wf-node--${ nodeType }${ selected ? ' is-selected' : '' }` }
			style={ { '--nvoos-wf-accent': accent } as React.CSSProperties }
		>
			<Handle type="target" position={ Position.Left } className="nvoos-pro-spa-wf-node__handle" />
			<div className="nvoos-pro-spa-wf-node__header">
				<span className="nvoos-pro-spa-wf-node__kind">{ kind }</span>
			</div>
			<div className="nvoos-pro-spa-wf-node__body">
				<strong className="nvoos-pro-spa-wf-node__label">{ label }</strong>
				{ description && <span className="nvoos-pro-spa-wf-node__desc">{ description }</span> }
			</div>
			<Handle type="source" position={ Position.Right } className="nvoos-pro-spa-wf-node__handle" />
		</div>
	);
}

export function ToolNode( props: NodeProps< CanvasWorkflowNode > ): JSX.Element {
	return (
		<WorkflowNodeShell
			nodeType="tool"
			accent="#2271b1"
			label={ props.data?.label ?? 'Tool' }
			description={ props.data?.description }
			rawType={ props.data?.rawType }
			selected={ props.selected }
		/>
	);
}

export function ActionNode( props: NodeProps< CanvasWorkflowNode > ): JSX.Element {
	return (
		<WorkflowNodeShell
			nodeType="action"
			accent="#007a4d"
			label={ props.data?.label ?? 'Action' }
			description={ props.data?.description }
			rawType={ props.data?.rawType }
			selected={ props.selected }
		/>
	);
}

export function AgentNode( props: NodeProps< CanvasWorkflowNode > ): JSX.Element {
	return (
		<WorkflowNodeShell
			nodeType="agent"
			accent="#7c3aed"
			label={ props.data?.label ?? 'Agent' }
			description={ props.data?.description }
			rawType={ props.data?.rawType }
			selected={ props.selected }
		/>
	);
}
