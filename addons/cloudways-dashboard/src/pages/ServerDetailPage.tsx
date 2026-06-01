/**
 * Server Detail Page — stats, apps list, actions.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi';

interface ServerDetail {
  id: number;
  label: string;
  status: string;
  ip_address?: string;
  cloud?: string;
  region?: string;
  ram?: string;
  cpu?: string;
  disk_size?: string;
  fmem_last_reading_total?: number;
  cpu_last_reading_total_percentage?: number;
  apps_count?: number;
}

interface AppItem {
  id: number;
  label: string;
  status: string;
  cname?: string;
}

interface AppsResponse {
  apps: AppItem[];
}

export function ServerDetailPage(): React.ReactElement {
  const { serverId } = useParams<{ serverId: string }>();
  const { data, loading, error } = useApi<ServerDetail>(`/servers/${serverId}`);
  const { data: appsData, loading: appsLoading } = useApi<AppsResponse>(`/servers/${serverId}/apps`);

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading server…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);
  if (!data) return createElement('div', { className: 'cwd-empty-state' }, 'Server not found.');

  const server = data;
  const apps = appsData?.apps ?? [];

  const ramPercent = server.fmem_last_reading_total
    ? Math.round((server.fmem_last_reading_total / (parseInt(server.ram || '1024', 10) * 1024)) * 100)
    : null;

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(Link, { to: '/servers', className: 'cwd-back-link' }, '← Back to Servers'),

    createElement(
      'div',
      { className: 'cwd-detail-header' },
      createElement('h2', null, server.label || `Server #${server.id}`),
      createElement('span', { className: `cwd-badge cwd-badge-${server.status === 'running' ? 'success' : 'danger'}` }, server.status)
    ),

    // Quick stats
    createElement(
      'div',
      { className: 'cwd-stats-grid cwd-stats-sm' },
      createElement(QuickStat, { label: 'IP', value: server.ip_address || '—' }),
      createElement(QuickStat, { label: 'Cloud', value: server.cloud || '—' }),
      createElement(QuickStat, { label: 'Region', value: server.region || '—' }),
      createElement(QuickStat, { label: 'CPU', value: server.cpu || '—' }),
      createElement(QuickStat, { label: 'RAM', value: server.ram || '—' }),
      createElement(QuickStat, { label: 'Disk', value: server.disk_size || '—' })
    ),

    // Resource gauges
    ramPercent !== null &&
      createElement(
        'div',
        { className: 'cwd-resource-bar' },
        createElement('span', { className: 'cwd-resource-label' }, `RAM Usage: ${ramPercent}%`),
        createElement(
          'div',
          { className: 'cwd-progress' },
          createElement('div', { className: `cwd-progress-fill${ramPercent > 80 ? ' cwd-progress-danger' : ''}`, style: { width: `${Math.min(ramPercent, 100)}%` } })
        )
      ),

    // Apps on this server
    createElement('h3', { className: 'cwd-section-title' }, `Apps (${apps.length})`),
    appsLoading
      ? createElement('div', { className: 'cwd-loading' }, 'Loading apps…')
      : apps.length === 0
        ? createElement('div', { className: 'cwd-empty-state' }, 'No apps on this server.')
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
                  createElement('th', null, 'App'),
                  createElement('th', null, 'Status'),
                  createElement('th', null, 'CNAME')
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
                    createElement('td', null, a.cname || '—')
                  )
                )
              )
            )
          )
  );
}

function QuickStat({ label, value }: { label: string; value: string }): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-quick-stat' },
    createElement('span', { className: 'cwd-quick-stat-label' }, label),
    createElement('span', { className: 'cwd-quick-stat-value' }, value)
  );
}
