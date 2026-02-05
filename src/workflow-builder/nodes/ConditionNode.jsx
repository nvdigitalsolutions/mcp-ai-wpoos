/**
 * Condition Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { Handle, Position } from 'reactflow';

const ConditionNode = ( { data } ) => {
	return (
		<div className="workflow-node workflow-node-condition">
			<Handle type="target" position={Position.Top} className="node-handle" />
			
			<div className="node-header" style={{ backgroundColor: '#f59e0b' }}>
				<span className="node-icon">◆</span>
				<span className="node-type">condition</span>
			</div>

			<div className="node-content">
				<div className="node-label">{data.label}</div>
				{data.config?.expression && (
					<div className="node-detail">{data.config.expression}</div>
				)}
			</div>

			<Handle
				type="source"
				position={Position.Bottom}
				id="true"
				className="node-handle handle-true"
				style={{ left: '30%' }}
			/>
			<Handle
				type="source"
				position={Position.Bottom}
				id="false"
				className="node-handle handle-false"
				style={{ left: '70%' }}
			/>
		</div>
	);
};

export default ConditionNode;
