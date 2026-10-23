/**
 * FlowCanvas — the main React Flow editor surface.
 *
 * Handles:
 * - Drag-and-drop from ToolPalette onto the canvas
 * - Node creation (tool nodes + trigger node)
 * - Edge connections between nodes
 * - Selection-based property editing
 * - Serialization to schedule data on save
 */

import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { __ } from '@/lib/i18n';
import {
  ReactFlow,
  Background,
  Controls,
  MiniMap,
  addEdge,
  useNodesState,
  useEdgesState,
  ReactFlowProvider,
  type Node,
  type Edge,
  type Connection,
  type NodeTypes,
  type ReactFlowInstance,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';

import { ToolNode } from './ToolNode';
import { TriggerNode } from './TriggerNode';
import { PropertyPanel } from './PropertyPanel';
import { useCreateSchedule } from '@/hooks/useSchedules';
import type { Schedule } from '@/hooks/useSchedules';
import { useSaTools } from '@/hooks/useSaTools';
import { accumulateToolGroups, toolDisplayName, type ToolGroupAccumulator } from '@/lib/toolCatalogue';
import { buildSchedulePayload } from '@/lib/schedulePayload';
import { toast } from 'sonner';

const nodeTypes: NodeTypes = {
  toolNode: ToolNode,
  triggerNode: TriggerNode,
};

export function FlowCanvas() {
  const reactFlowWrapper = useRef<HTMLDivElement>(null);
  const [reactFlowInstance, setReactFlowInstance] = useState<ReactFlowInstance | null>(null);
  const [nodes, setNodes, onNodesChange] = useNodesState<Node>([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
  const [selectedNode, setSelectedNode] = useState<Node | null>(null);
  const [scheduleName, setScheduleName] = useState('');
  const [scheduleType, setScheduleType] = useState<Schedule['schedule_type']>('task');
  const [cronInterval, setCronInterval] = useState('daily');

  const createSchedule = useCreateSchedule();

  // Handle edge connections
  const onConnect = useCallback(
    (connection: Connection) => setEdges((eds) => addEdge(connection, eds)),
    [setEdges]
  );

  // Handle node selection
  const onNodeClick = useCallback((_: React.MouseEvent, node: Node) => {
    setSelectedNode(node);
  }, []);

  // Handle pane click (deselect)
  const onPaneClick = useCallback(() => {
    setSelectedNode(null);
  }, []);

  // Handle drag-over from ToolPalette
  const onDragOver = useCallback((event: DragEvent) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  }, []);

  // Handle drop from ToolPalette onto canvas
  const onDrop = useCallback(
    (event: DragEvent) => {
      event.preventDefault();

      if (!reactFlowWrapper.current || !reactFlowInstance) return;

      const toolData = JSON.parse(event.dataTransfer.getData('application/reactflow'));

      const bounds = reactFlowWrapper.current.getBoundingClientRect();
      const position = reactFlowInstance.screenToFlowPosition({
        x: event.clientX - bounds.left - 90,
        y: event.clientY - bounds.top - 25,
      });

      const newNode: Node = {
        id: `tool-${Date.now()}`,
        type: 'toolNode',
        position,
        data: {
          toolSlug: toolData.slug,
          toolName: toolData.name,
          toolkit: toolData.toolkit,
          arguments: {},
          label: toolData.name,
        },
      };

      setNodes((nds) => nds.concat(newNode));
    },
    [reactFlowInstance, setNodes]
  );

  // Add trigger node
  const addTriggerNode = useCallback(() => {
    const newNode: Node = {
      id: 'trigger',
      type: 'triggerNode',
      position: { x: 250, y: 50 },
      data: {
        triggerType: 'cron',
        schedule: cronInterval,
        label: __('Scheduled Trigger', 'schedule-anything-spa'),
      },
    };
    setNodes((nds) => {
      // Don't duplicate trigger
      if (nds.some((n) => n.id === 'trigger')) return nds;
      return [newNode, ...nds];
    });
  }, [cronInterval, setNodes]);

  // Update node data from property panel
  const updateNodeData = useCallback(
    (nodeId: string, newData: Record<string, unknown>) => {
      setNodes((nds) =>
        nds.map((node) => {
          if (node.id === nodeId) {
            return { ...node, data: { ...node.data, ...newData } };
          }
          return node;
        })
      );
      setSelectedNode((prev) =>
        prev?.id === nodeId ? { ...prev, data: { ...prev.data, ...newData } } : prev
      );
    },
    [setNodes]
  );

  // Serialize and save
  const handleSave = useCallback(async () => {
    if (!scheduleName.trim()) {
      toast.error(__('Please enter a schedule name.', 'schedule-anything-spa'));
      return;
    }

    const toolNodes = nodes.filter((n) => n.type === 'toolNode');

    if (scheduleType === 'workflow' && toolNodes.length === 0) {
      toast.error(
        __('Add at least one tool to the canvas before saving a workflow schedule.', 'schedule-anything-spa')
      );
      return;
    }

    const scheduleData = buildSchedulePayload({
      name: scheduleName,
      scheduleType,
      cron: cronInterval,
      toolNodes,
    });

    try {
      await createSchedule.mutateAsync(scheduleData);
      toast.success(__('Schedule created.', 'schedule-anything-spa'));
      // Redirect to schedules page on success
      window.location.href = '/schedules';
    } catch (err) {
      // Surface the server's validation message (WP_Error.message) verbatim.
      toast.error(
        err instanceof Error
          ? err.message
          : __('Failed to create schedule.', 'schedule-anything-spa')
      );
    }
  }, [scheduleName, scheduleType, cronInterval, nodes, createSchedule]);

  return (
    <div className="flex h-full">
      {/* Left: Tool Palette */}
      <div className="w-56 border-r border-gray-200 bg-white flex flex-col">
        <div className="p-3 border-b border-gray-100">
          <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
            { __('Tools', 'schedule-anything-spa') }
          </h3>
        </div>
        <ToolPaletteContent />
      </div>

      {/* Center: Flow Canvas */}
      <div className="flex-1 flex flex-col" ref={reactFlowWrapper}>
        {/* Top bar */}
        <div className="flex items-center gap-3 px-4 py-2 border-b border-gray-200 bg-white">
          <input
            type="text"
            value={scheduleName}
            onChange={(e) => setScheduleName(e.target.value)}
            placeholder={ __('Schedule name…', 'schedule-anything-spa') }
            className="flex-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={scheduleType}
            onChange={(e) => setScheduleType(e.target.value as Schedule['schedule_type'])}
            className="px-2 py-1.5 text-sm border border-gray-300 rounded-lg bg-white"
          >
            <option value="task">{ __('Task', 'schedule-anything-spa') }</option>
            <option value="workflow">{ __('Workflow', 'schedule-anything-spa') }</option>
            <option value="assistant_run">{ __('AI Assistant', 'schedule-anything-spa') }</option>
            <option value="channel_broadcast">{ __('Broadcast', 'schedule-anything-spa') }</option>
          </select>
          <select
            value={cronInterval}
            onChange={(e) => {
              setCronInterval(e.target.value);
              updateNodeData('trigger', { schedule: e.target.value });
            }}
            className="px-2 py-1.5 text-sm border border-gray-300 rounded-lg bg-white"
          >
            <option value="hourly">{ __('Hourly', 'schedule-anything-spa') }</option>
            <option value="daily">{ __('Daily', 'schedule-anything-spa') }</option>
            <option value="wp_mcp_ai_every_6_hours">{ __('Every 6 hours', 'schedule-anything-spa') }</option>
            <option value="wp_mcp_ai_every_30_minutes">{ __('Every 30 minutes', 'schedule-anything-spa') }</option>
            <option value="weekly">{ __('Weekly', 'schedule-anything-spa') }</option>
            <option value="wp_mcp_ai_monthly">{ __('Monthly', 'schedule-anything-spa') }</option>
          </select>
          <button
            onClick={addTriggerNode}
            className="px-3 py-1.5 text-xs bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition-colors"
          >
            { __('+ Trigger', 'schedule-anything-spa') }
          </button>
          <button
            onClick={handleSave}
            disabled={createSchedule.isPending}
            className="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors font-medium"
          >
            {createSchedule.isPending
              ? __('Saving…', 'schedule-anything-spa')
              : __('Save Schedule', 'schedule-anything-spa')}
          </button>
        </div>

        {/* Canvas */}
        <div className="flex-1" onDrop={onDrop} onDragOver={onDragOver}>
          <ReactFlowProvider>
            <ReactFlow
              nodes={nodes}
              edges={edges}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              onConnect={onConnect}
              onNodeClick={onNodeClick}
              onPaneClick={onPaneClick}
              onInit={setReactFlowInstance}
              nodeTypes={nodeTypes}
              fitView
              deleteKeyCode={['Backspace', 'Delete']}
            >
              <Background />
              <Controls />
              <MiniMap
                nodeStrokeWidth={3}
                pannable
                zoomable
              />
            </ReactFlow>
          </ReactFlowProvider>
        </div>
      </div>

      {/* Right: Property Panel */}
      <PropertyPanel
        node={selectedNode}
        onUpdate={(data) => {
          if (selectedNode) updateNodeData(selectedNode.id, data);
        }}
        onDelete={() => {
          if (selectedNode) {
            setNodes((nds) => nds.filter((n) => n.id !== selectedNode.id));
            setSelectedNode(null);
          }
        }}
      />
    </div>
  );
}

/**
 * Tool palette content — draggable tools grouped by toolkit.
 *
 * The palette consumes the live tool catalogue from GET /sa-tools
 * (progressive discovery: search + load-more, grouped by the server's
 * tool-group facet). The drag payload shape is unchanged, so the canvas
 * drop handler and payload builder stay compatible.
 */
function ToolPaletteContent() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, isFetching, error, refetch } = useSaTools(search, '', page);

  // Accumulate loaded pages so "Load more" extends the palette instead of
  // replacing it.
  const [groups, setGroups] = useState<ToolGroupAccumulator[]>([]);

  useEffect(() => {
    setPage(1);
    setGroups([]);
  }, [search]);

  useEffect(() => {
    if (!data?.tools?.length) return;
    setGroups((prev) => {
      const next = accumulateToolGroups(data.tools, page === 1 ? [] : prev);
      // Avoid churn when nothing changed (e.g. refetch of the same page).
      if (JSON.stringify(next) === JSON.stringify(prev)) return prev;
      return next;
    });
  }, [data, page]);

  const hasMore = Boolean(data?.has_more);
  const shownCount = useMemo(
    () => groups.reduce((sum, g) => sum + g.tools.length, 0),
    [groups]
  );

  const onDragStart = (event: React.DragEvent, tool: { slug: string; name: string }, toolkitName: string) => {
    event.dataTransfer.setData(
      'application/reactflow',
      JSON.stringify({ ...tool, toolkit: toolkitName })
    );
    event.dataTransfer.effectAllowed = 'move';
  };

  if (isLoading) {
    return (
      <div className="p-4 space-y-2">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="skeleton h-8 rounded-lg" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-4 text-center">
        <p className="text-xs text-red-600 mb-3">
          { __('Could not load the tool catalogue.', 'schedule-anything-spa') }
        </p>
        <button
          onClick={() => refetch()}
          className="px-3 py-1.5 text-xs font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors"
        >
          { __('Retry', 'schedule-anything-spa') }
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full">
      <div className="p-2">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={ __('Search tools…', 'schedule-anything-spa') }
          aria-label={ __('Search tools', 'schedule-anything-spa') }
          className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <div className="flex-1 overflow-y-auto p-2 space-y-3">
        {groups.length === 0 && !isFetching ? (
          <p className="text-xs text-gray-400 text-center py-4">
            { __('No tools found.', 'schedule-anything-spa') }
          </p>
        ) : (
          groups.map((tk) => (
            <div key={tk.group}>
              <h4 className="text-xs font-semibold text-gray-400 uppercase px-2 mb-1">
                {tk.label}
              </h4>
              {tk.tools.map((tool) => (
                <div
                  key={tool.slug}
                  draggable
                  onDragStart={(e) => onDragStart(e, tool, tk.label)}
                  title={tool.description}
                  className="px-3 py-2 text-xs text-gray-700 rounded-lg cursor-grab hover:bg-blue-50 hover:text-blue-700 transition-colors border border-transparent hover:border-blue-200"
                >
                  {toolDisplayName(tool)}
                </div>
              ))}
            </div>
          ))
        )}
      </div>

      {hasMore && (
        <div className="p-2 border-t border-gray-100">
          <button
            onClick={() => setPage((p) => p + 1)}
            disabled={isFetching}
            className="w-full px-3 py-1.5 text-xs font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {isFetching
              ? __('Loading…', 'schedule-anything-spa')
              : __('Load more', 'schedule-anything-spa') + ` (${shownCount} / ${data?.total})`}
          </button>
        </div>
      )}
    </div>
  );
}
