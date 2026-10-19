/**
 * Tests for the tool catalogue pure helpers used by the builder palette.
 */

import { describe, it, expect } from 'vitest';
import {
  accumulateToolGroups,
  humanizeSlug,
  toolDisplayName,
  type ToolGroupAccumulator,
} from '@/lib/toolCatalogue';
import type { SaTool } from '@/hooks/useSaTools';

function tool(overrides: Partial<SaTool>): SaTool {
  return {
    slug: 'create_lead',
    name: 'Create Lead',
    description: 'Creates a lead',
    group: 'wordpress-core',
    group_label: 'WordPress Core',
    ...overrides,
  };
}

describe('accumulateToolGroups', () => {
  it('groups tools by group key in first-seen order', () => {
    const groups = accumulateToolGroups([
      tool({ slug: 'a', group: 'crm', group_label: 'CRM' }),
      tool({ slug: 'b', group: 'analytics', group_label: 'Analytics' }),
      tool({ slug: 'c', group: 'crm', group_label: 'CRM' }),
    ]);

    expect(groups.map((g) => g.group)).toEqual(['crm', 'analytics']);
    expect(groups[0].tools.map((t) => t.slug)).toEqual(['a', 'c']);
    expect(groups[1].tools.map((t) => t.slug)).toEqual(['b']);
  });

  it('extends an existing accumulator without mutating it', () => {
    const existing: ToolGroupAccumulator[] = [
      { group: 'crm', label: 'CRM', tools: [tool({ slug: 'a' })] },
    ];

    const next = accumulateToolGroups(
      [tool({ slug: 'b', group: 'crm' }), tool({ slug: 'c', group: 'media', group_label: 'Media' })],
      existing
    );

    expect(existing[0].tools).toHaveLength(1);
    expect(next[0].tools.map((t) => t.slug)).toEqual(['a', 'b']);
    expect(next[1].group).toBe('media');
  });

  it('falls back to the group id when the label is missing', () => {
    const groups = accumulateToolGroups([tool({ group: 'other', group_label: '' })]);

    expect(groups[0].label).toBe('other');
  });

  it('returns an empty list for empty input', () => {
    expect(accumulateToolGroups([])).toEqual([]);
  });
});

describe('humanizeSlug', () => {
  it('converts underscores to title case', () => {
    expect(humanizeSlug('create_lead')).toBe('Create Lead');
  });

  it('handles empty and odd slugs', () => {
    expect(humanizeSlug('')).toBe('');
    expect(humanizeSlug('_a_b_')).toBe('A B');
  });
});

describe('toolDisplayName', () => {
  it('prefers the real name', () => {
    expect(toolDisplayName(tool({ name: 'Create Lead' }))).toBe('Create Lead');
  });

  it('falls back to a humanized slug when the name is blank', () => {
    expect(toolDisplayName(tool({ name: '', slug: 'check_availability' }))).toBe('Check Availability');
  });
});
