/**
 * Trigger Node Component
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import BaseNode from './BaseNode';

const TriggerNode = ( { data } ) => {
	return (
		<BaseNode data={data} type="trigger" icon="⚡" color="#10b981" />
	);
};

export default TriggerNode;
