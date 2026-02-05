/**
 * Parallel Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const ParallelNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="parallel" icon="⇉" color="#ec4899" />
	);
};

export default ParallelNode;
