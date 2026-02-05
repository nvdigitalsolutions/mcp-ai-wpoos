/**
 * Agent Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const AgentNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="agent" icon="🤖" color="#8b5cf6" />
	);
};

export default AgentNode;
