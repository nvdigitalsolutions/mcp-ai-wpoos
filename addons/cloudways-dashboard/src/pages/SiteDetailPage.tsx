/**
 * Site Detail Page — app-specific info, toolkit status, live provisioning.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi';
import { usePollingApi } from '../hooks/usePollingApi';

interface SiteDetail {
  app?: {
    id: number;
    label: string;
    status: string;
    cname?: string;
    app_fqdn?: string;
    application?: string;
    server_id?: number;
    username?: string;
    password?: string;
  };
  pending_toolkits?: {
    toolkit_ids: string[];
    assistant_ids: string[];
    created_at: number;
  };
}

interface ProvisioningStatus {
  status: string;
  attempt: number;
  started_at: number;
  last_poll_at: number | null;
  error: string | null;
  results: Record<string, unknown> | null;
  app_data: Record<string, unknown> | null;
  pending_toolkits: Record<string, unknown> | null;
}

export function SiteDetailPage(): React.ReactElement {
  const { siteId } = useParams<{ siteId: string }>();
  const { data, loading, error } = useApi<SiteDetail>(`/apps/${siteId}`);

  // Poll provisioning status while the app is still provisioning.
  const {
    data: provStatus,
    loading: provLoading,
  } = usePollingApi<ProvisioningStatus>(
    `/apps/${siteId}/provisioning`,
    5000,
    (d: ProvisioningStatus) => d.status === 'ready' || d.status === 'failed' || d.status === 'timeout'
  );

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading site…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);
  if (!data) return createElement('div', { className: 'cwd-empty-state' }, 'Site not found.');

  const app = data.app;
  const pendingTKs = data.pending_toolkits;
  const isProvisioning = provStatus?.status === 'provisioning' || provStatus?.status === 'unknown';

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(Link, { to: '/sites', className: 'cwd-back-link' }, '← Back to Sites'),

    // Provisioning banner
    isProvisioning &&
      createElement(
        'div',
        { className: 'cwd-info-box cwd-info-warning' },
        createElement('p', null, `🔄 Site is being provisioned — attempt ${provStatus?.attempt || 0}/60`),
        createElement('div', { className: 'cwd-progress', style: { marginTop: 8 } },
          createElement('div', {
            className: 'cwd-progress-fill',
            style: { width: `${Math.min(((provStatus?.attempt || 0) / 60) * 100, 100)}%` },
          })
        )
      ),
    provStatus?.status === 'ready' &&
      createElement(
        'div',
        { className: 'cwd-info-box cwd-info-success' },
        createElement('p', null, '✅ Site is ready!'),
        provStatus.results &&
          createElement('p', null, `Toolkits applied: ${Object.keys(provStatus.results as Record<string, unknown>).filter(k => k !== 'plugin_install' && k !== 'cname' && k !== 'app_url' && k !== 'assistants' && k !== 'applied_at').join(', ') || 'none'}`)
      ),
    provStatus?.status === 'failed' &&
      createElement('div', { className: 'cwd-info-box' },
        createElement('p', { className: 'cwd-error' }, `❌ Provisioning failed: ${provStatus.error || 'Unknown error'}`)
      ),

    app &&
      createElement(
        'div',
        null,
        createElement(
          'div',
          { className: 'cwd-detail-header' },
          createElement('h2', null, app.label || `App #${app.id}`),
          createElement('span', { className: `cwd-badge cwd-badge-${app.status === 'running' ? 'success' : 'warning'}` }, app.status || 'unknown')
        ),

        createElement(
          'div',
          { className: 'cwd-stats-grid cwd-stats-sm' },
          createElement(QuickStat, { label: 'Domain', value: app.cname || app.app_fqdn || '—' }),
          createElement(QuickStat, { label: 'Application', value: app.application || '—' }),
          app.server_id &&
            createElement(QuickStat, {
              label: 'Server',
              value: createElement(Link, { to: `/servers/${app.server_id}`, className: 'cwd-link' }, `Server #${app.server_id}`) as unknown as string,
            })
        ),

        // Site credentials (if available)
        (app.username || app.password) &&
          createElement(
            'div',
            { className: 'cwd-info-box', style: { marginTop: 16 } },
            createElement('h4', null, 'WordPress Credentials'),
            app.username && createElement('p', null, `Username: ${app.username}`),
            app.password && createElement('p', null, `Password: ${app.password}`)
          ),

        // Pending toolkits
        createElement('h3', { className: 'cwd-section-title' }, 'Toolkits'),
        pendingTKs && pendingTKs.toolkit_ids.length > 0
          ? createElement(
              'div',
              { className: 'cwd-info-box' },
              createElement('p', null, `${pendingTKs.toolkit_ids.length} toolkit(s) configured:`),
              createElement(
                'ul',
                null,
                pendingTKs.toolkit_ids.map((tk) => createElement('li', { key: tk }, tk))
              ),
              isProvisioning && createElement('p', null, '⏳ Toolkits will be applied once provisioning completes.')
            )
          : createElement('div', { className: 'cwd-empty-state' }, 'No toolkits assigned. You can apply toolkits from the Toolkits page.'),

        // Quick actions
        createElement('h3', { className: 'cwd-section-title' }, 'Actions'),
        createElement(
          'div',
          { className: 'cwd-action-grid' },
          app.app_fqdn &&
            createElement(
              'a',
              { href: `https://${app.app_fqdn}`, target: '_blank', rel: 'noopener noreferrer', className: 'cwd-action-card' },
              createElement('span', { className: 'cwd-action-icon' }, '🔗'),
              createElement('span', { className: 'cwd-action-label' }, 'Open Site')
            ),
          createElement(
            Link,
            { to: `/sites/${app.id}/toolkits`, className: 'cwd-action-card' },
            createElement('span', { className: 'cwd-action-icon' }, '🧰'),
            createElement('span', { className: 'cwd-action-label' }, 'Manage Toolkits')
          ),
          app.app_fqdn &&
            createElement(
              'a',
              { href: `https://${app.app_fqdn}/wp-admin`, target: '_blank', rel: 'noopener noreferrer', className: 'cwd-action-card' },
              createElement('span', { className: 'cwd-action-icon' }, '⚙️'),
              createElement('span', { className: 'cwd-action-label' }, 'WP Admin')
            )
        )
      )
  );
}

function QuickStat({ label, value }: { label: string; value: string | React.ReactElement }): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-quick-stat' },
    createElement('span', { className: 'cwd-quick-stat-label' }, label),
    createElement('span', { className: 'cwd-quick-stat-value' }, value)
  );
}
