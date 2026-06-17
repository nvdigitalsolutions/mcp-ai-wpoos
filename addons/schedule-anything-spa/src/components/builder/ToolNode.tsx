/**
 * Custom React Flow node: displays a tool execution step.
 */

import { memo } from 'react';
import { Handle, Position, type NodeProps } from 'reactflow';

interface ToolNodeData {
  toolSlug: string;
  toolName: string;
  toolkit: string;
  arguments: Record<string, unknown>;
  label?: string;
}

function ToolNodeComponent({ data, selected }: NodeProps<ToolNodeData>) {
  return (
    <div
      className={`
        px-4 py-3 rounded-lg border-2 bg-white shadow-sm min-w-[180px]
        ${selected ? 'border-blue-500 shadow-md' : 'border-gray-200'}
        transition-shadow
      `}
    >
      <Handle type="target" position={Position.Top} className="!bg-gray-400 !w-3 !h-3" />

      <div className="flex items-center gap-2 mb-1">
        <span className="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium">
          {data.toolkit || 'tool'}
        </span>
      </div>

      <p className="text-sm font-medium text-gray-900 truncate">
        {data.label || data.toolName || data.toolSlug}
      </p>

      {data.toolSlug && (
        <p className="text-xs text-gray-400 mt-0.5 truncate">{data.toolSlug}</p>
      )}

      <Handle type="source" position={Position.Bottom} className="!bg-gray-400 !w-3 !h-3" />
    </div>
  );
}

export const ToolNode = memo(ToolNodeComponent);
