/**
 * Action Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const ActionNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="action" icon="▶" color="#3b82f6">
			{data.config?.command && (
				<div className="node-detail">{data.config.command}</div>
			)}
		</BaseNode>
	);
};

export default ActionNode;
