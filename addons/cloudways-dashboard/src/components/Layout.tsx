/**
 * NV oOS Cloudways Dashboard — Layout Component
 *
 * Velzon-inspired sidebar navigation with the main content outlet.
 *
 * @since 0.1.0
 */

import { createElement, useState } from 'react';
import { Outlet, NavLink, useLocation } from 'react-router-dom';

interface LayoutProps {
  isAdmin: boolean;
}

interface NavItem {
  to: string;
  label: string;
  icon: string;
}

const NAV_ITEMS: NavItem[] = [
  { to: '/',           label: 'Dashboard', icon: '📊' },
  { to: '/servers',   label: 'Servers',   icon: '🖥️' },
  { to: '/sites',      label: 'Sites',     icon: '🌐' },
  { to: '/toolkits',   label: 'Toolkits',  icon: '🧰' },
  { to: '/settings',   label: 'Settings',  icon: '⚙️' },
];

export function Layout({ isAdmin }: LayoutProps): React.ReactElement {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const location = useLocation();

  return createElement(
    'div',
    { className: 'cwd-layout' },
    // Sidebar
    createElement(
      'aside',
      { className: `cwd-sidebar${sidebarOpen ? ' is-open' : ''}` },
      createElement(
        'div',
        { className: 'cwd-sidebar-header' },
        createElement('span', { className: 'cwd-brand' }, '☁️ oOS Cloudways'),
        createElement(
          'button',
          {
            className: 'cwd-sidebar-toggle',
            onClick: () => setSidebarOpen((o) => !o),
            'aria-label': sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar',
          },
          sidebarOpen ? '◀' : '▶'
        )
      ),
      createElement(
        'nav',
        { className: 'cwd-nav' },
        NAV_ITEMS.map((item) =>
          createElement(
            NavLink,
            {
              key: item.to,
              to: item.to,
              end: item.to === '/',
              className: ({ isActive }: { isActive: boolean }) =>
                `cwd-nav-item${isActive ? ' is-active' : ''}`,
            },
            createElement('span', { className: 'cwd-nav-icon' }, item.icon),
            createElement('span', { className: 'cwd-nav-label' }, item.label)
          )
        )
      ),
      createElement(
        'div',
        { className: 'cwd-sidebar-footer' },
        createElement('span', { className: 'cwd-version' }, 'v0.1.0')
      )
    ),
    // Main content
    createElement(
      'main',
      { className: 'cwd-main' },
      // Top bar
      createElement(
        'header',
        { className: 'cwd-topbar' },
        createElement('h1', { className: 'cwd-page-title' }, getPageTitle(location.pathname)),
        isAdmin &&
          createElement(
            'a',
            {
              href: '/wp-admin/admin.php?page=nvoos-cloudways-dashboard',
              className: 'cwd-admin-link',
            },
            'WP Admin'
          )
      ),
      createElement(
        'div',
        { className: 'cwd-content' },
        createElement(Outlet)
      )
    )
  );
}

function getPageTitle(pathname: string): string {
  if (pathname === '/' || pathname === '') return 'Dashboard';
  if (pathname.startsWith('/servers')) return 'Servers';
  if (pathname.startsWith('/sites')) return 'Sites';
  if (pathname.startsWith('/toolkits')) return 'Toolkits';
  if (pathname.startsWith('/settings')) return 'Settings';
  return 'Cloudways Dashboard';
}
