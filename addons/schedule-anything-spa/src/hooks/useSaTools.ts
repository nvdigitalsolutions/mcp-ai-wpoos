/**
 * Schedulable tool catalogue hook for the visual builder palette.
 *
 * Consumes GET /mcp-ai-pro/v1/sa-tools — a light, paginated projection of
 * the live NV oOS tool registry (slug/name/description/group only; the
 * full inputSchema never ships to the palette).
 */

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@/api/client';

export interface SaTool {
  slug: string;
  name: string;
  description: string;
  group: string;
  group_label: string;
}

export interface SaToolGroup {
  slug: string;
  label: string;
}

export interface SaToolsResponse {
  ok: boolean;
  tools: SaTool[];
  total: number;
  page: number;
  per_page: number;
  has_more: boolean;
  groups: SaToolGroup[];
}

export const saToolsKeys = {
  all: ['sa-tools'] as const,
  list: (params: { search: string; group: string; page: number }) =>
    [...saToolsKeys.all, 'list', params] as const,
};

/**
 * Fetch one page of the schedulable tool catalogue.
 *
 * @param search Free-text search across slug/name/description.
 * @param group  Group filter (empty string = all groups).
 * @param page   1-based page number.
 */
export function useSaTools(search = '', group = '', page = 1) {
  const params = new URLSearchParams();
  if (search) params.set('search', search);
  if (group) params.set('group', group);
  params.set('page', String(page));
  params.set('per_page', '30');

  return useQuery<SaToolsResponse>({
    queryKey: saToolsKeys.list({ search, group, page }),
    queryFn: () =>
      apiFetch({ path: `/mcp-ai-pro/v1/sa-tools?${params.toString()}` }),
  });
}
