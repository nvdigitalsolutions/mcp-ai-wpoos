/**
 * Dashboard Page — aggregate stats cards and quick actions.
 *
 * @since 0.1.0
 */

import { createElement, useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi';
import { StatsGridSkeleton } from '../components/Skeleton';

interface SummaryData {
  total_servers: number;
  running_servers: number;
  total_apps: number;
  total_toolkits: number;
}

export function DashboardPage(): React.ReactElement {
  const { data, loading, error } = useApi<SummaryData>('/summary');
  const { data: settingsData } = useApi<{ configured: boolean }>('/settings');

  const configured = settingsData?.configured ?? false;

  if (!configured) {
    return createElement(
      'div',
      { className: 'cwd-empty-state' },
      createElement('h2', null, 'Welcome to Cloudways Dashboard'),
      createElement('p', null, 'Connect your Cloudways account to get started.'),
      createElement(Link, { to: '/settings', className: 'cwd-btn cwd-btn-primary' }, 'Configure API Credentials')
    );
  }

  if (loading) {
    return createElement(
      'div',
      { className: 'cwd-dashboard' },
      createElement(StatsGridSkeleton, { count: 4 })
    );
  }

  if (error) {
    return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);
  }

  const stats = data!;

  return createElement(
    'div',
    { className: 'cwd-dashboard' },
    // Stats cards
    createElement(
      'div',
      { className: 'cwd-stats-grid' },
      createElement(StatCard, { label: 'Total Servers', value: stats.total_servers, color: 'blue', to: '/servers' }),
      createElement(StatCard, { label: 'Running', value: stats.running_servers, color: 'green', to: '/servers' }),
      createElement(StatCard, { label: 'Sites', value: stats.total_apps, color: 'purple', to: '/sites' }),
      createElement(StatCard, { label: 'Toolkits', value: stats.total_toolkits, color: 'orange', to: '/toolkits' })
    ),
    // Quick actions
    createElement(
      'div',
      { className: 'cwd-quick-actions' },
      createElement('h3', null, 'Quick Actions'),
      createElement(
        'div',
        { className: 'cwd-action-grid' },
        createElement(Link, { to: '/sites/create', className: 'cwd-action-card' },
          createElement('span', { className: 'cwd-action-icon' }, '➕'),
          createElement('span', { className: 'cwd-action-label' }, 'Create New Site')
        ),
        createElement(Link, { to: '/servers', className: 'cwd-action-card' },
          createElement('span', { className: 'cwd-action-icon' }, '🖥️'),
          createElement('span', { className: 'cwd-action-label' }, 'View Servers')
        ),
        createElement(Link, { to: '/toolkits', className: 'cwd-action-card' },
          createElement('span', { className: 'cwd-action-icon' }, '🧰'),
          createElement('span', { className: 'cwd-action-label' }, 'Browse Toolkits')
        ),
        createElement(Link, { to: '/settings', className: 'cwd-action-card' },
          createElement('span', { className: 'cwd-action-icon' }, '⚙️'),
          createElement('span', { className: 'cwd-action-label' }, 'Settings')
        )
      )
    )
  );
}

// ── Stat Card ──────────────────────────────────────────────────────────

interface StatCardProps {
  label: string;
  value: number;
  color: 'blue' | 'green' | 'purple' | 'orange';
  to: string;
}

function StatCard({ label, value, color, to }: StatCardProps): React.ReactElement {
  return createElement(
    Link,
    { to, className: `cwd-stat-card cwd-stat-${color}` },
    createElement('span', { className: 'cwd-stat-value' }, String(value)),
    createElement('span', { className: 'cwd-stat-label' }, label)
  );
}
