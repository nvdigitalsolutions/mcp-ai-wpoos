/**
 * Base Node Component
 *
 * Reusable base component for all workflow nodes.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { Handle, Position } from 'reactflow';

/**
 * Execution status badge component
 */
const ExecutionBadge = ( { status } ) => {
	if ( ! status ) {
		return null;
	}

	const icons = {
		running: '⏳',
		completed: '✓',
		failed: '✗',
		paused: '⏸',
		cancelled: '⊗',
	};

	const icon = icons[ status ] || '';

	return (
		<span className={`node-execution-badge node-execution-badge--${status}`} title={status}>
			{icon}
		</span>
	);
};

/**
 * Base Node Component
 */
const BaseNode = ( { data, type, icon, color, children } ) => {
	const isExecuting     = data.isExecuting;
	const executionStatus = data.executionStatus;

	const statusClass = isExecuting
		? 'node--executing'
		: executionStatus
		? `node--${executionStatus}`
		: '';

	return (
		<div className={`workflow-node workflow-node-${type} ${statusClass}`} style={{ borderColor: color }}>
			<Handle type="target" position={Position.Top} className="node-handle" />

			<div className="node-header" style={{ backgroundColor: color }}>
				<span className="node-icon">{icon}</span>
				<span className="node-type">{type}</span>
				<ExecutionBadge status={isExecuting ? 'running' : executionStatus} />
			</div>

			<div className="node-content">
				<div className="node-label">{data.label}</div>
				{children}
			</div>

			<Handle type="source" position={Position.Bottom} className="node-handle" />
		</div>
	);
};

export default BaseNode;
