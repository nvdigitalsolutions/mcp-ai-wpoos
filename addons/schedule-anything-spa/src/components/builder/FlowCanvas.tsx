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

import { useCallback, useRef, useState, type DragEvent } from 'react';
import ReactFlow, {
  Background,
  Controls,
  MiniMap,
  addEdge,
  useNodesState,
  useEdgesState,
  type Node,
  type Edge,
  type Connection,
  ReactFlowProvider,
} from 'reactflow';
import 'reactflow/dist/style.css';

import { ToolNode } from './ToolNode';
import { TriggerNode } from './TriggerNode';
import { PropertyPanel } from './PropertyPanel';
import { useCreateSchedule } from '@/hooks/useSchedules';
import type { Schedule } from '@/hooks/useSchedules';

const nodeTypes = {
  toolNode: ToolNode,
  triggerNode: TriggerNode,
};

export function FlowCanvas() {
  const reactFlowWrapper = useRef<HTMLDivElement>(null);
  const [reactFlowInstance, setReactFlowInstance] = useState<any>(null);
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
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
      const position = reactFlowInstance.project({
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
        label: 'Scheduled Trigger',
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
      alert('Please enter a schedule name.');
      return;
    }

    const toolNodes = nodes.filter((n) => n.type === 'toolNode');
    const workflowSteps = toolNodes.map((n) => ({
      tool_slug: (n.data as any).toolSlug,
      arguments: (n.data as any).arguments || {},
      label: (n.data as any).label || (n.data as any).toolName || '',
    }));

    const scheduleData: Partial<Schedule> = {
      name: scheduleName,
      schedule_type: scheduleType,
      schedule: cronInterval,
      tags: [],
      workflow_steps: scheduleType === 'workflow' ? workflowSteps : undefined,
    };

    try {
      await createSchedule.mutateAsync(scheduleData);
      // Redirect to schedules page on success
      window.location.href = '/schedules';
    } catch (err) {
      alert('Failed to create schedule: ' + (err instanceof Error ? err.message : 'Unknown error'));
    }
  }, [scheduleName, scheduleType, cronInterval, nodes, createSchedule]);

  return (
    <div className="flex h-full">
      {/* Left: Tool Palette */}
      <div className="w-56 border-r border-gray-200 bg-white overflow-y-auto">
        <div className="p-3 border-b border-gray-100">
          <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Tools</h3>
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
            placeholder="Schedule name..."
            className="flex-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={scheduleType}
            onChange={(e) => setScheduleType(e.target.value as Schedule['schedule_type'])}
            className="px-2 py-1.5 text-sm border border-gray-300 rounded-lg bg-white"
          >
            <option value="task">Task</option>
            <option value="workflow">Workflow</option>
            <option value="assistant_run">AI Assistant</option>
            <option value="channel_broadcast">Broadcast</option>
          </select>
          <select
            value={cronInterval}
            onChange={(e) => {
              setCronInterval(e.target.value);
              updateNodeData('trigger', { schedule: e.target.value });
            }}
            className="px-2 py-1.5 text-sm border border-gray-300 rounded-lg bg-white"
          >
            <option value="hourly">Hourly</option>
            <option value="daily">Daily</option>
            <option value="wp_mcp_ai_every_6_hours">Every 6h</option>
            <option value="wp_mcp_ai_every_30_minutes">Every 30m</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
          <button
            onClick={addTriggerNode}
            className="px-3 py-1.5 text-xs bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition-colors"
          >
            + Trigger
          </button>
          <button
            onClick={handleSave}
            disabled={createSchedule.isPending}
            className="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors font-medium"
          >
            {createSchedule.isPending ? 'Saving...' : 'Save Schedule'}
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
 */
function ToolPaletteContent() {
  const toolkits = [
    {
      name: 'Calendar Booking',
      tools: [
        { slug: 'create_appointment', name: 'Create Appointment' },
        { slug: 'check_availability', name: 'Check Availability' },
        { slug: 'send_booking_confirmation', name: 'Send Confirmation' },
        { slug: 'send_appointment_reminder', name: 'Send Reminder' },
      ],
    },
    {
      name: 'CRM',
      tools: [
        { slug: 'create_lead', name: 'Create Lead' },
        { slug: 'send_email_campaign', name: 'Send Email Campaign' },
        { slug: 'update_contact', name: 'Update Contact' },
      ],
    },
    {
      name: 'E-commerce',
      tools: [
        { slug: 'get_abandoned_carts', name: 'Get Abandoned Carts' },
        { slug: 'send_cart_recovery_email', name: 'Send Recovery Email' },
        { slug: 'check_inventory', name: 'Check Inventory' },
      ],
    },
    {
      name: 'Analytics',
      tools: [
        { slug: 'generate_traffic_report', name: 'Traffic Report' },
        { slug: 'check_seo_rankings', name: 'SEO Rankings' },
        { slug: 'generate_performance_digest', name: 'Performance Digest' },
      ],
    },
    {
      name: 'Social Media',
      tools: [
        { slug: 'get_content_calendar', name: 'Get Content Calendar' },
        { slug: 'schedule_social_posts', name: 'Schedule Posts' },
        { slug: 'publish_to_social', name: 'Publish to Social' },
      ],
    },
    {
      name: 'Chat Channels',
      tools: [
        { slug: 'broadcast_slack', name: 'Broadcast to Slack' },
        { slug: 'broadcast_teams', name: 'Broadcast to Teams' },
        { slug: 'broadcast_telegram', name: 'Broadcast to Telegram' },
      ],
    },
  ];

  const onDragStart = (event: React.DragEvent, tool: { slug: string; name: string }, toolkitName: string) => {
    event.dataTransfer.setData(
      'application/reactflow',
      JSON.stringify({ ...tool, toolkit: toolkitName })
    );
    event.dataTransfer.effectAllowed = 'move';
  };

  return (
    <div className="p-2 space-y-3">
      {toolkits.map((tk) => (
        <div key={tk.name}>
          <h4 className="text-xs font-semibold text-gray-400 uppercase px-2 mb-1">{tk.name}</h4>
          {tk.tools.map((tool) => (
            <div
              key={tool.slug}
              draggable
              onDragStart={(e) => onDragStart(e, tool, tk.name)}
              className="px-3 py-2 text-xs text-gray-700 rounded-lg cursor-grab hover:bg-blue-50 hover:text-blue-700 transition-colors border border-transparent hover:border-blue-200"
            >
              {tool.name}
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}
