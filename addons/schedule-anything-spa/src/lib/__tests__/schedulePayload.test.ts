/**
 * Tests for the builder → REST payload contract (Phase 4 of Proposal 081).
 */

import { describe, it, expect } from 'vitest';
import { buildSchedulePayload, SCHEDULE_DISPATCH_HOOK } from '../schedulePayload';

describe('buildSchedulePayload', () => {
  it('task schedules dispatch through the central hook and carry no steps', () => {
    const payload = buildSchedulePayload({
      name: 'Morning Reminder',
      scheduleType: 'task',
      cron: 'daily',
      toolNodes: [],
    });

    expect(payload).toEqual({
      name: 'Morning Reminder',
      schedule_type: 'task',
      schedule: 'daily',
      tags: [],
      hook: SCHEDULE_DISPATCH_HOOK,
      workflow_steps: undefined,
    });
  });

  it('workflow schedules serialize tool nodes into workflow_steps with no hook', () => {
    const payload = buildSchedulePayload({
      name: 'Lead Nurture',
      scheduleType: 'workflow',
      cron: 'daily',
      toolNodes: [
        {
          data: {
            toolSlug: 'create_lead',
            label: 'Create Lead',
            arguments: { source: 'web' },
          },
        },
        {
          data: {
            toolSlug: 'send_email_campaign',
            arguments: {},
          },
        },
      ],
    });

    expect(payload.schedule_type).toBe('workflow');
    expect(payload.hook).toBeUndefined();
    expect(payload.workflow_steps).toEqual([
      { tool_slug: 'create_lead', arguments: { source: 'web' }, label: 'Create Lead' },
      { tool_slug: 'send_email_campaign', arguments: {}, label: '' },
    ]);
  });

  it('passes the cron slug through verbatim (incl. custom intervals)', () => {
    const payload = buildSchedulePayload({
      name: 'Monthly Digest',
      scheduleType: 'task',
      cron: 'wp_mcp_ai_monthly',
      toolNodes: [],
    });

    expect(payload.schedule).toBe('wp_mcp_ai_monthly');
  });

  it('falls back to empty strings for malformed node data', () => {
    const payload = buildSchedulePayload({
      name: 'Weird Nodes',
      scheduleType: 'workflow',
      cron: 'daily',
      toolNodes: [{ data: { toolSlug: 42, label: null } }],
    });

    expect(payload.workflow_steps).toEqual([
      { tool_slug: '', arguments: {}, label: '' },
    ]);
  });
});
