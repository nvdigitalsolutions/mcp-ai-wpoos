/**
 * Sites Page — list all WordPress apps across all servers.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi';

interface AppItem {
  id: number;
  label: string;
  status: string;
  cname?: string;
  app_fqdn?: string;
  server_id: number;
  server_name: string;
  application?: string;
}

interface AppsResponse {
  apps: AppItem[];
  count: number;
}

export function SitesPage(): React.ReactElement {
  const { data, loading, error, refetch } = useApi<AppsResponse>('/apps');

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading sites…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);

  const apps = data?.apps ?? [];

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(
      'div',
      { className: 'cwd-page-actions' },
      createElement(Link, { to: '/sites/create', className: 'cwd-btn cwd-btn-primary' }, '➕ Create Site'),
      createElement(
        'button',
        { className: 'cwd-btn cwd-btn-secondary', onClick: refetch },
        '🔄 Refresh'
      )
    ),
    apps.length === 0
      ? createElement('div', { className: 'cwd-empty-state' },
          createElement('p', null, 'No sites found.'),
          createElement(Link, { to: '/sites/create', className: 'cwd-btn cwd-btn-primary' }, 'Create your first site')
        )
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
                createElement('th', null, 'Site'),
                createElement('th', null, 'Status'),
                createElement('th', null, 'Type'),
                createElement('th', null, 'Domain'),
                createElement('th', null, 'Server')
              )
            ),
            createElement(
              'tbody',
              null,
              apps.map((a) =>
                createElement(
                  'tr',
                  { key: a.id },
                  createElement(
                    'td',
                    null,
                    createElement(Link, { to: `/sites/${a.id}`, className: 'cwd-link' }, a.label || `App #${a.id}`)
                  ),
                  createElement('td', null, createElement('span', { className: `cwd-badge cwd-badge-${a.status === 'running' ? 'success' : 'warning'}` }, a.status || 'unknown')),
                  createElement('td', null, a.application || '—'),
                  createElement('td', null, a.cname || a.app_fqdn || '—'),
                  createElement('td', null, createElement(Link, { to: `/servers/${a.server_id}`, className: 'cwd-link' }, a.server_name || `Server #${a.server_id}`))
                )
              )
            )
          )
        )
  );
}
