/**
 * Site Toolkits Page — manage toolkits on a specific site.
 *
 * @since 0.1.0
 */

import { createElement, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useApi, useMutation } from '../hooks/useApi';

interface ToolkitState {
  slug: string;
  status: string;
  applied_at: number;
  updated_at: number;
  assistants?: Record<string, unknown>;
}

interface SiteToolkitsResponse {
  app_id: number;
  toolkits: Record<string, ToolkitState>;
  count: number;
}

interface ToolkitItem {
  slug: string;
  label: string;
  description: string;
  icon: string;
  version: string;
}

interface ToolkitsCatalogResponse {
  toolkits: ToolkitItem[];
  count: number;
}

export function SiteToolkitsPage(): React.ReactElement {
  const { siteId } = useParams<{ siteId: string }>();
  const { data, loading, error, refetch } = useApi<SiteToolkitsResponse>(`/apps/${siteId}/toolkits`);
  const { data: catalogData } = useApi<ToolkitsCatalogResponse>('/toolkits');

  const { mutate: applyMutation, loading: applying } = useMutation(`/apps/${siteId}/toolkits`);
  const { mutate: removeMutation, loading: removing } = useMutation(`/apps/${siteId}/toolkits`);

  const [selectedToAdd, setSelectedToAdd] = useState<string[]>([]);
  const [showAddPanel, setShowAddPanel] = useState(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; msg: string } | null>(null);

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading toolkits…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);

  const activeTKs = data?.toolkits ?? {};
  const catalog = catalogData?.toolkits ?? [];
  const availableToAdd = catalog.filter((tk) => !activeTKs[tk.slug]);
  const activeEntries = Object.values(activeTKs);

  const handleApply = async () => {
    if (selectedToAdd.length === 0) return;
    setFeedback(null);
    try {
      await applyMutation({ toolkits: selectedToAdd } as any);
      setSelectedToAdd([]);
      setShowAddPanel(false);
      setFeedback({ type: 'success', msg: 'Toolkits applied!' });
      refetch();
    } catch (e: any) {
      setFeedback({ type: 'error', msg: e.message || 'Failed to apply toolkits.' });
    }
  };

  const handleRemove = async (slug: string) => {
    setFeedback(null);
    try {
      await removeMutation({ toolkits: [slug] } as any);
      setFeedback({ type: 'success', msg: `"${slug}" removed.` });
      refetch();
    } catch (e: any) {
      setFeedback({ type: 'error', msg: e.message || 'Failed to remove toolkit.' });
    }
  };

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(Link, { to: `/sites/${siteId}`, className: 'cwd-back-link' }, '← Back to Site'),

    createElement(
      'div',
      { className: 'cwd-page-actions' },
      createElement('h2', null, `Toolkits on Site #${siteId}`),
      createElement(
        'button',
        { className: 'cwd-btn cwd-btn-primary', onClick: () => setShowAddPanel((o) => !o) },
        showAddPanel ? 'Cancel' : `➕ Add Toolkit (${availableToAdd.length} available)`
      )
    ),

    feedback &&
      createElement('div', { className: feedback.type === 'success' ? 'cwd-success' : 'cwd-error', style: { marginBottom: 12 } }, feedback.msg),

    // Add toolkit panel
    showAddPanel &&
      createElement(
        'div',
        { className: 'cwd-form-section' },
        createElement('h3', null, 'Add Toolkits'),
        availableToAdd.length === 0
          ? createElement('p', null, 'All available toolkits are already applied to this site.')
          : createElement(
              'div',
              { className: 'cwd-toolkit-grid' },
              availableToAdd.map((tk) =>
                createElement(
                  'label',
                  {
                    key: tk.slug,
                    className: `cwd-toolkit-card${selectedToAdd.includes(tk.slug) ? ' is-selected' : ''}`,
                  },
                  createElement('input', {
                    type: 'checkbox',
                    checked: selectedToAdd.includes(tk.slug),
                    onChange: () =>
                      setSelectedToAdd((prev) =>
                        prev.includes(tk.slug) ? prev.filter((s) => s !== tk.slug) : [...prev, tk.slug]
                      ),
                    style: { marginRight: 8 },
                  }),
                  createElement('strong', null, tk.label),
                  createElement('small', null, tk.description || '')
                )
              )
            ),
        selectedToAdd.length > 0 &&
          createElement(
            'button',
            { className: 'cwd-btn cwd-btn-primary', disabled: applying, onClick: handleApply, style: { marginTop: 12 } },
            applying ? 'Applying…' : `Apply ${selectedToAdd.length} toolkit(s)`
          )
      ),

    // Active toolkits table
    createElement('h3', { className: 'cwd-section-title' }, `Active Toolkits (${activeEntries.length})`),
    activeEntries.length === 0
      ? createElement('div', { className: 'cwd-empty-state' }, 'No toolkits are currently applied to this site.')
      : createElement(
          'div',
          { className: 'cwd-table-wrapper' },
          createElement(
            'table',
            { className: 'cwd-table' },
            createElement(
              'thead',
              null,
              createElement(
                'tr',
                null,
                createElement('th', null, 'Toolkit'),
                createElement('th', null, 'Status'),
                createElement('th', null, 'Applied'),
                createElement('th', null, 'Actions')
              )
            ),
            createElement(
              'tbody',
              null,
              activeEntries.map((tk) =>
                createElement(
                  'tr',
                  { key: tk.slug },
                  createElement('td', null,
                    createElement('strong', null, tk.slug),
                    tk.assistants && Object.keys(tk.assistants).length > 0 &&
                      createElement('small', { style: { display: 'block', color: 'var(--cwd-text-muted)' } }, `${Object.keys(tk.assistants).length} assistant intent(s)`)
                  ),
                  createElement('td', null,
                    createElement('span', { className: `cwd-badge cwd-badge-${tk.status === 'active' ? 'success' : 'warning'}` }, tk.status)
                  ),
                  createElement('td', null, formatDate(tk.applied_at)),
                  createElement('td', null,
                    createElement(
                      'button',
                      {
                        className: 'cwd-btn cwd-btn-danger',
                        disabled: removing,
                        onClick: () => handleRemove(tk.slug),
                        style: { fontSize: 11, padding: '4px 10px' },
                      },
                      'Remove'
                    )
                  )
                )
              )
            )
          )
        )
  );
}

function formatDate(ts: number): string {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleDateString();
}
