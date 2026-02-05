/**
 * Approval Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const ApprovalNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="approval" icon="✓" color="#14b8a6" />
	);
};

export default ApprovalNode;
