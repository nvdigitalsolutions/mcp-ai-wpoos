/**
 * PropertyPanel — right sidebar for editing selected node properties.
 */

import type { Node } from 'reactflow';

interface PropertyPanelProps {
  node: Node | null;
  onUpdate: (data: Record<string, unknown>) => void;
  onDelete: () => void;
}

export function PropertyPanel({ node, onUpdate, onDelete }: PropertyPanelProps) {
  if (!node) {
    return (
      <div className="w-64 border-l border-gray-200 bg-white p-4">
        <p className="text-sm text-gray-400 text-center mt-8">
          Select a node to edit its properties
        </p>
      </div>
    );
  }

  const data = node.data as Record<string, unknown>;
  const isToolNode = node.type === 'toolNode';

  return (
    <div className="w-64 border-l border-gray-200 bg-white overflow-y-auto">
      <div className="p-4 border-b border-gray-100">
        <h3 className="text-sm font-semibold text-gray-700">
          {isToolNode ? 'Tool Properties' : 'Trigger Properties'}
        </h3>
        <p className="text-xs text-gray-400 mt-0.5">Node ID: {node.id}</p>
      </div>

      <div className="p-4 space-y-4">
        {isToolNode ? (
          <>
            {/* Tool slug (read-only) */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Tool</label>
              <input
                type="text"
                value={(data.toolSlug as string) || ''}
                readOnly
                className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded bg-gray-50 text-gray-500"
              />
            </div>

            {/* Label */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Label</label>
              <input
                type="text"
                value={(data.label as string) || ''}
                onChange={(e) => onUpdate({ label: e.target.value })}
                placeholder="Display name"
                className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            {/* Arguments (JSON) */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">
                Arguments (JSON)
              </label>
              <textarea
                value={JSON.stringify(data.arguments || {}, null, 2)}
                onChange={(e) => {
                  try {
                    const parsed = JSON.parse(e.target.value);
                    onUpdate({ arguments: parsed });
                  } catch {
                    // Invalid JSON — don't update yet
                  }
                }}
                rows={6}
                className="w-full px-2 py-1.5 text-xs font-mono border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </>
        ) : (
          <>
            {/* Trigger type */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Trigger Type</label>
              <input
                type="text"
                value={(data.triggerType as string) || 'cron'}
                readOnly
                className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded bg-gray-50 text-gray-500"
              />
            </div>

            {/* Schedule interval */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Interval</label>
              <input
                type="text"
                value={(data.schedule as string) || ''}
                onChange={(e) => onUpdate({ schedule: e.target.value })}
                className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            {/* Label */}
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Label</label>
              <input
                type="text"
                value={(data.label as string) || ''}
                onChange={(e) => onUpdate({ label: e.target.value })}
                className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </>
        )}

        {/* Delete button */}
        <button
          onClick={onDelete}
          className="w-full px-3 py-2 text-xs text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition-colors mt-4"
        >
          Delete Node
        </button>
      </div>
    </div>
  );
}
