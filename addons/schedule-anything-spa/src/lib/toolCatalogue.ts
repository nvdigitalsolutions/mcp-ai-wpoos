/**
 * Pure helpers for the schedulable tool catalogue palette.
 *
 * Kept free of React so the grouping/accumulation logic is unit-testable
 * without rendering.
 */

import type { SaTool } from '@/hooks/useSaTools';

export interface ToolGroupAccumulator {
  /** Group id (e.g. 'wordpress-core'). */
  group: string;
  /** Display label (e.g. 'WordPress Core'). */
  label: string;
  tools: SaTool[];
}

/**
 * Accumulate tool rows into ordered groups.
 *
 * Group order follows first-seen order of the rows (the server facet order),
 * which keeps the palette deterministic regardless of pagination.
 *
 * @param tools   Tool rows (typically one page).
 * @param into    Existing accumulator to append to (default: new list).
 * @returns The accumulated groups.
 */
export function accumulateToolGroups(
  tools: SaTool[],
  into: ToolGroupAccumulator[] = []
): ToolGroupAccumulator[] {
  const groups = into.map((g) => ({ ...g, tools: [...g.tools] }));
  const index = new Map<string, number>();
  groups.forEach((g, i) => index.set(g.group, i));

  for (const tool of tools) {
    const existing = index.get(tool.group);
    if (existing !== undefined) {
      groups[existing].tools.push(tool);
    } else {
      index.set(tool.group, groups.length);
      groups.push({
        group: tool.group,
        label: tool.group_label || tool.group,
        tools: [tool],
      });
    }
  }

  return groups;
}

/**
 * Humanize a tool slug into a display name fallback.
 *
 * Mirrors the server-side fallback (ucwords of underscores) so the SPA
 * never renders an empty node label.
 *
 * @param slug Tool slug.
 * @returns Humanized label.
 */
export function humanizeSlug(slug: string): string {
  return slug
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

/**
 * Resolve a stable display name for a tool row.
 *
 * @param tool Tool row.
 * @returns Non-empty display name.
 */
export function toolDisplayName(tool: Pick<SaTool, 'name' | 'slug'>): string {
  return tool.name.trim() !== '' ? tool.name : humanizeSlug(tool.slug);
}
