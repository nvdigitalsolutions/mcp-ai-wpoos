/**
 * Delay Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const DelayNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="delay" icon="⏱" color="#6366f1">
			{data.config?.duration && (
				<div className="node-detail">{data.config.duration}s</div>
			)}
		</BaseNode>
	);
};

export default DelayNode;
