/**
 * Tool Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const ToolNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="tool" icon="🔧" color="#0891b2" />
	);
};

export default ToolNode;
