/**
 * Loop Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const LoopNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="loop" icon="🔄" color="#8b5cf6">
			{data.config?.items && (
				<div className="node-detail">{data.config.items}</div>
			)}
		</BaseNode>
	);
};

export default LoopNode;
