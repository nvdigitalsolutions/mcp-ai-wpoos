/**
 * Preset data-fetching hooks.
 *
 * Provides hooks for browsing and installing schedule presets.
 * Presets are read from the WP_MCP_AI_Pro_Schedule_Presets registry
 * and installed via the Schedule Manager's create_schedule method.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@/api/client';
import { scheduleKeys } from './useSchedules';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Preset {
  id: string;
  name: string;
  description: string;
  toolkit: string;
  category: string;
  icon: string;
  schedule_type: string;
  schedule: string;
  tags: string[];
  schedule_data: Record<string, unknown>;
}

export interface ToolkitInfo {
  slug: string;
  flag_key: string;
  enabled: boolean;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

export const presetKeys = {
  all: ['presets'] as const,
  lists: () => [...presetKeys.all, 'list'] as const,
  list: (filters: Record<string, string>) => [...presetKeys.lists(), filters] as const,
  toolkits: ['toolkits'] as const,
};

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

/**
 * Fetch all available presets.
 * Optionally filtered by toolkit or category.
 */
export function usePresets(toolkit?: string, category?: string) {
  const params = new URLSearchParams();
  if (toolkit) params.set('toolkit', toolkit);
  if (category) params.set('category', category);

  return useQuery<{ ok: boolean; presets: Preset[]; total: number }>({
    queryKey: presetKeys.list(Object.fromEntries(params)),
    queryFn: () =>
      apiFetch({ path: `/mcp-ai-pro/v1/presets?${params.toString()}` }),
  });
}

/**
 * Fetch a single preset by ID.
 */
export function usePreset(id: string | undefined) {
  return useQuery<{ ok: boolean; preset: Preset }>({
    queryKey: [...presetKeys.all, id],
    queryFn: () => apiFetch({ path: `/mcp-ai-pro/v1/presets/${id}` }),
    enabled: !!id,
  });
}

/**
 * Install a preset as a live schedule.
 *
 * Calls install_preset() on the backend which creates
 * a schedule record via WP_MCP_AI_Pro_Schedule_Manager.
 */
export function useInstallPreset() {
  const queryClient = useQueryClient();

  return useMutation<
    { ok: boolean; schedule_id: string },
    Error,
    { presetId: string; overrides?: Record<string, unknown> }
  >({
    mutationFn: ({ presetId, overrides }) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/presets/${presetId}/install`,
        method: 'POST',
        data: { overrides },
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: scheduleKeys.lists() });
    },
  });
}

/**
 * Fetch all toolkits with their enabled/disabled status.
 */
export function useToolkits() {
  return useQuery<{ ok: boolean; toolkits: ToolkitInfo[] }>({
    queryKey: presetKeys.toolkits,
    queryFn: () => apiFetch({ path: '/mcp-ai-pro/v1/toolkits' }),
  });
}

/**
 * Toggle a toolkit on or off for the current tenant.
 */
export function useToggleToolkit() {
  const queryClient = useQueryClient();

  return useMutation<{ ok: boolean }, Error, { slug: string; enabled: boolean }>({
    mutationFn: ({ slug, enabled }) =>
      apiFetch({
        path: `/mcp-ai-pro/v1/toolkits/${slug}/toggle`,
        method: 'POST',
        data: { enabled },
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: presetKeys.toolkits });
    },
  });
}
