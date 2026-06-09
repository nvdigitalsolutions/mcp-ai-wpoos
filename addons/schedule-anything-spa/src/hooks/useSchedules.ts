/**
 * Schedule data-fetching hooks using @tanstack/react-query.
 *
 * Provides hooks for listing, creating, updating, deleting,
 * toggling, and triggering schedules via the Pro REST API.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@/api/client';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Schedule {
  id: string;
  name: string;
  description: string;
  schedule_type: 'task' | 'workflow' | 'assistant_run' | 'channel_broadcast' | 'workflow_builder';
  hook: string;
  schedule: string;
  enabled: boolean;
  priority: number;
  tags: string[];
  run_count: number;
  last_run_status: string;
  last_run_time: number;
  last_run_duration: number;
  created_at: number;
  updated_at: number;
  workflow_steps?: Array<{
    tool_slug: string;
    arguments: Record<string, unknown>;
    label: string;
  }>;
  assistant_config?: {
    assistant_id: number;
    message: string;
    context?: Record<string, unknown>;
    max_agentic_iterations?: number;
  };
  broadcast_config?: {
    message: string;
    channels: string[];
    credentials: Record<string, unknown>;
  };
  workflow_builder_id?: string;
  timeout?: number;
  max_retries?: number;
  notify_on_failure?: boolean;
  notify_email?: string;
}

export interface ScheduleListResponse {
  ok: boolean;
  schedules: Schedule[];
  total: number;
}

export interface ScheduleResponse {
  ok: boolean;
  schedule: Schedule;
}

export interface ScheduleHistoryEntry {
  timestamp: number;
  status: string;
  duration: number;
  error?: string;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

export const scheduleKeys = {
  all: ['schedules'] as const,
  lists: () => [...scheduleKeys.all, 'list'] as const,
  list: (filters: Record<string, string>) => [...scheduleKeys.lists(), filters] as const,
  details: () => [...scheduleKeys.all, 'detail'] as const,
  detail: (id: string) => [...scheduleKeys.details(), id] as const,
  history: (id: string) => [...scheduleKeys.all, 'history', id] as const,
};

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

/**
 * Fetch all schedules for the current tenant.
 */
export function useSchedules(type?: string, tag?: string) {
  const params = new URLSearchParams();
  if (type) params.set('schedule_type', type);
  if (tag) params.set('tag', tag);

  return useQuery<ScheduleListResponse>({
    queryKey: scheduleKeys.list(Object.fromEntries(params)),
    queryFn: () => apiFetch({ path: `/mcp-ai-pro/v1/schedules?${params.toString()}` }),
  });
}

/**
 * Fetch a single schedule by ID.
 */
export function useSchedule(id: string | undefined) {
  return useQuery<ScheduleResponse>({
    queryKey: scheduleKeys.detail(id!),
    queryFn: () => apiFetch({ path: `/mcp-ai-pro/v1/schedules/${id}` }),
    enabled: !!id,
  });
}

/**
 * Fetch run history for a schedule.
 */
export function useScheduleHistory(id: string | undefined) {
  return useQuery<{ ok: boolean; schedule_id: string; history: ScheduleHistoryEntry[]; total: number }>({
    queryKey: scheduleKeys.history(id!),
    queryFn: () => apiFetch({ path: `/mcp-ai-pro/v1/schedules/${id}/history` }),
    enabled: !!id,
  });
}

/**
 * Create a new schedule.
 */
export function useCreateSchedule() {
  const queryClient = useQueryClient();

  return useMutation<ScheduleResponse, Error, Partial<Schedule>>({
    mutationFn: (data) =>
      apiFetch({
        path: '/mcp-ai-pro/v1/schedules',
        method: 'POST',
        data,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.lists() });
    },
  });
}

/**
 * Update an existing schedule.
 */
export function useUpdateSchedule() {
  const queryClient = useQueryClient();

  return useMutation<ScheduleResponse, Error, { id: string; data: Partial<Schedule> }>({
    mutationFn: ({ id, data }) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/schedules/${id}`,
        method: 'PUT',
        data,
      }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.lists() });
      queryClient.invalidateQueries({ queryKey: scheduleKeys.detail(variables.id) });
    },
  });
}

/**
 * Delete a schedule.
 */
export function useDeleteSchedule() {
  const queryClient = useQueryClient();

  return useMutation<{ ok: boolean; deleted: string }, Error, string>({
    mutationFn: (id) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/schedules/${id}`,
        method: 'DELETE',
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.lists() });
    },
  });
}

/**
 * Toggle a schedule's enabled state.
 */
export function useToggleSchedule() {
  const queryClient = useQueryClient();

  return useMutation<{ ok: boolean; enabled: boolean; schedule: Schedule }, Error, string>({
    mutationFn: (id) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/schedules/${id}/toggle`,
        method: 'POST',
      }),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.lists() });
      queryClient.invalidateQueries({ queryKey: scheduleKeys.detail(id) });
    },
  });
}

/**
 * Manually trigger a schedule.
 */
export function useTriggerSchedule() {
  const queryClient = useQueryClient();

  return useMutation<{ ok: boolean; schedule_id: string; result: unknown }, Error, string>({
    mutationFn: (id) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/schedules/${id}/trigger`,
        method: 'POST',
      }),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.history(id) });
      queryClient.invalidateQueries({ queryKey: scheduleKeys.detail(id) });
    },
  });
}
