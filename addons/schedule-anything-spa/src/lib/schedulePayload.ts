/**
 * Build the create-schedule REST payload from builder canvas state.
 *
 * Kept pure so the payload contract with
 * POST /mcp-ai-pro/v1/schedules can be unit-tested without mounting
 * the React Flow canvas.
 */

import type { Schedule } from '@/hooks/useSchedules';

/** Central dispatcher hook the Pro Schedule Manager executes. */
export const SCHEDULE_DISPATCH_HOOK = 'wp_mcp_ai_pro_schedule_exec';

interface ToolNodeData {
  toolSlug?: unknown;
  toolName?: unknown;
  label?: unknown;
  arguments?: unknown;
}

export interface BuildPayloadOptions {
  /** Schedule name (required by the REST endpoint). */
  name: string;
  /** Schedule type selected in the builder toolbar. */
  scheduleType: Schedule['schedule_type'];
  /** Registered WP cron interval slug (hourly, daily, weekly, wp_mcp_ai_monthly, …). */
  cron: string;
  /** Tool nodes currently on the canvas. */
  toolNodes: Array<{ data: ToolNodeData }>;
}

/**
 * Serialize canvas state into the payload the schedules REST endpoint
 * expects: task schedules dispatch through the central hook, workflow
 * schedules carry their steps (a non-empty list is validated server-side).
 */
export function buildSchedulePayload({
  name,
  scheduleType,
  cron,
  toolNodes,
}: BuildPayloadOptions): Partial<Schedule> {
  const workflowSteps = toolNodes.map((node) => ({
    tool_slug: typeof node.data.toolSlug === 'string' ? node.data.toolSlug : '',
    arguments:
      node.data.arguments && typeof node.data.arguments === 'object'
        ? (node.data.arguments as Record<string, unknown>)
        : {},
    label:
      typeof node.data.label === 'string'
        ? node.data.label
        : typeof node.data.toolName === 'string'
          ? node.data.toolName
          : '',
  }));

  return {
    name,
    schedule_type: scheduleType,
    schedule: cron,
    tags: [],
    hook: scheduleType === 'task' ? SCHEDULE_DISPATCH_HOOK : undefined,
    workflow_steps: scheduleType === 'workflow' ? workflowSteps : undefined,
  };
}
