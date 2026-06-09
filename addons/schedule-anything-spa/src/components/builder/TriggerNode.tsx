/**
 * Custom React Flow node: trigger (cron, webhook, or event).
 */

import { memo } from 'react';
import { Handle, Position, type NodeProps } from 'reactflow';

interface TriggerNodeData {
  triggerType: 'cron' | 'webhook' | 'event';
  schedule?: string;
  hook?: string;
  label?: string;
}

const TRIGGER_ICONS: Record<string, string> = {
  cron: '⏰',
  webhook: '🔗',
  event: '📡',
};

const TRIGGER_LABELS: Record<string, string> = {
  cron: 'Scheduled',
  webhook: 'Webhook',
  event: 'Event',
};

function TriggerNodeComponent({ data, selected }: NodeProps<TriggerNodeData>) {
  return (
    <div
      className={`
        px-4 py-3 rounded-lg border-2 bg-amber-50 shadow-sm min-w-[160px]
        ${selected ? 'border-amber-500 shadow-md' : 'border-amber-200'}
      `}
    >
      <Handle type="source" position={Position.Bottom} className="!bg-amber-500 !w-3 !h-3" />

      <div className="flex items-center gap-2">
        <span className="text-lg">{TRIGGER_ICONS[data.triggerType] || '▶'}</span>
        <div>
          <p className="text-sm font-semibold text-amber-800">
            {data.label || TRIGGER_LABELS[data.triggerType] || 'Trigger'}
          </p>
          {data.schedule && (
            <p className="text-xs text-amber-600 mt-0.5">
              Every {data.schedule.replace('wp_mcp_ai_', '').replace(/_/g, ' ')}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export const TriggerNode = memo(TriggerNodeComponent);
