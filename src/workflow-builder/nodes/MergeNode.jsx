/**
 * Merge Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const MergeNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="merge" icon="⊕" color="#6b7280" />
	);
};

export default MergeNode;
