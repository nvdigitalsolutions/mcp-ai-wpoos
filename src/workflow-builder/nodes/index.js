/**
 * Custom Node Types
 *
 * Defines custom ReactFlow node components for different workflow node types.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import ActionNode from './ActionNode';
import TriggerNode from './TriggerNode';
import ConditionNode from './ConditionNode';
import LoopNode from './LoopNode';
import ParallelNode from './ParallelNode';
import DelayNode from './DelayNode';
import ApprovalNode from './ApprovalNode';
import ToolNode from './ToolNode';
import AgentNode from './AgentNode';
import MergeNode from './MergeNode';

/**
 * Node type registry
 */
const nodeTypes = {
	action: ActionNode,
	trigger: TriggerNode,
	condition: ConditionNode,
	loop: LoopNode,
	parallel: ParallelNode,
	delay: DelayNode,
	approval: ApprovalNode,
	tool: ToolNode,
	agent: AgentNode,
	merge: MergeNode,
};

export default nodeTypes;
