/**
 * App layout — sidebar navigation + content area.
 *
 * Provides the shell for all authenticated tenant pages.
 * Uses Tailwind CSS for styling.
 */

import { useState, type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useTenant } from '@/contexts/TenantContext';
import { useAuth } from '@/contexts/AuthContext';
import { __, sprintf } from '@/lib/i18n';
import clsx from 'clsx';

interface AppLayoutProps {
  children: ReactNode;
}

const NAV_ITEMS = [
  { path: '/dashboard', label: __( 'Dashboard', 'schedule-anything-spa' ), icon: '📊' },
  { path: '/schedules', label: __( 'Schedules', 'schedule-anything-spa' ), icon: '⚡' },
  { path: '/presets', label: __( 'Presets', 'schedule-anything-spa' ), icon: '📋' },
  { path: '/history', label: __( 'Run History', 'schedule-anything-spa' ), icon: '📜' },
  { path: '/analytics', label: __( 'Analytics', 'schedule-anything-spa' ), icon: '📈' },
  { path: '/settings', label: __( 'Settings', 'schedule-anything-spa' ), icon: '⚙️' },
];

const TENANT_TIER_BADGES: Record<string, string> = {
  starter: 'bg-gray-100 text-gray-700',
  professional: 'bg-blue-100 text-blue-700',
  enterprise: 'bg-purple-100 text-purple-700',
};

export function AppLayout({ children }: AppLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const tenant = useTenant();
  const auth = useAuth();

  return (
    <div className="flex h-screen bg-gray-50">
      {/* Sidebar */}
      <aside
        className={clsx(
          'flex flex-col bg-gray-900 text-white transition-all duration-300',
          sidebarOpen ? 'w-64' : 'w-16'
        )}
      >
        {/* Logo */}
        <div className="flex items-center h-16 px-4 border-b border-gray-700">
          <button
            onClick={() => setSidebarOpen(!sidebarOpen)}
            className="text-gray-400 hover:text-white"
          >
            {sidebarOpen ? '◀' : '▶'}
          </button>
          {sidebarOpen && (
            <span className="ml-3 font-semibold text-sm">
              { __( 'Schedule Anything', 'schedule-anything-spa' ) }
            </span>
          )}
        </div>

        {/* Tenant info */}
        {sidebarOpen && tenant.slug && (
          <div className="px-4 py-3 border-b border-gray-700">
            <p className="text-xs text-gray-400 truncate">{tenant.slug}</p>
            <span
              className={clsx(
                'inline-block mt-1 px-2 py-0.5 text-xs rounded-full font-medium',
                TENANT_TIER_BADGES[tenant.tier] || TENANT_TIER_BADGES.starter
              )}
            >
              {tenant.tier}
            </span>
          </div>
        )}

        {/* Navigation */}
        <nav className="flex-1 py-4">
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              className={({ isActive }) =>
                clsx(
                  'flex items-center px-4 py-2.5 text-sm transition-colors',
                  'hover:bg-gray-800',
                  isActive
                    ? 'bg-gray-800 text-white border-r-2 border-blue-500'
                    : 'text-gray-300'
                )
              }
            >
              <span className="w-6 text-center">{item.icon}</span>
              {sidebarOpen && <span className="ml-3">{item.label}</span>}
            </NavLink>
          ))}
        </nav>

        {/* User info */}
        {sidebarOpen && auth.isLoggedIn && (
          <div className="px-4 py-3 border-t border-gray-700">
            <p className="text-xs text-gray-400">
              { /* translators: %s: WordPress user id */ }
              { sprintf( __( 'User ID: %s', 'schedule-anything-spa' ), String( auth.userId ?? '' ) ) }
            </p>
          </div>
        )}
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-auto">
        <div className="p-6">{children}</div>
      </main>
    </div>
  );
}
