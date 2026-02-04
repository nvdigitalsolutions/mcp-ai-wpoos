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
 * Base Node Component
 */
const BaseNode = ( { data, type, icon, color, children } ) => {
	return (
		<div className={`workflow-node workflow-node-${type}`} style={{ borderColor: color }}>
			<Handle type="target" position={Position.Top} className="node-handle" />
			
			<div className="node-header" style={{ backgroundColor: color }}>
				<span className="node-icon">{icon}</span>
				<span className="node-type">{type}</span>
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
