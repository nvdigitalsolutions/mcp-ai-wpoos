/**
 * Servers Page — list all Cloudways servers.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi';

interface Server {
  id: number;
  label: string;
  status: string;
  ip_address?: string;
  cloud?: string;
  region?: string;
  ram?: string;
  cpu?: string;
  disk_size?: string;
}

interface ServersResponse {
  servers: Server[];
}

export function ServersPage(): React.ReactElement {
  const { data, loading, error, refetch } = useApi<ServersResponse>('/servers');

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading servers…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);

  const servers = data?.servers ?? [];

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(
      'div',
      { className: 'cwd-page-actions' },
      createElement(
        'button',
        { className: 'cwd-btn cwd-btn-secondary', onClick: refetch },
        '🔄 Refresh'
      )
    ),
    servers.length === 0
      ? createElement('div', { className: 'cwd-empty-state' }, 'No servers found.')
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
                createElement('th', null, 'Server'),
                createElement('th', null, 'Status'),
                createElement('th', null, 'IP'),
                createElement('th', null, 'Cloud'),
                createElement('th', null, 'Region'),
                createElement('th', null, 'Resources')
              )
            ),
            createElement(
              'tbody',
              null,
              servers.map((s) =>
                createElement(
                  'tr',
                  { key: s.id },
                  createElement(
                    'td',
                    null,
                    createElement(Link, { to: `/servers/${s.id}`, className: 'cwd-link' }, s.label || `Server #${s.id}`)
                  ),
                  createElement('td', null, createElement(StatusBadge, { status: s.status })),
                  createElement('td', null, s.ip_address || '—'),
                  createElement('td', null, s.cloud || '—'),
                  createElement('td', null, s.region || '—'),
                  createElement('td', null, `${s.ram || '—'} / ${s.cpu || '—'}`)
                )
              )
            )
          )
        )
  );
}

function StatusBadge({ status }: { status: string }): React.ReactElement {
  const s = (status || '').toLowerCase();
  const className = `cwd-badge cwd-badge-${s === 'running' ? 'success' : s === 'stopped' ? 'danger' : 'warning'}`;
  return createElement('span', { className }, status || 'unknown');
}
