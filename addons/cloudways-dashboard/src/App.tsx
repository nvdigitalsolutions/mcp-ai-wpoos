/**
 * NV oOS Cloudways Dashboard — Root Application Component
 *
 * Wraps routing, auth context, and the Velzon-style layout.
 *
 * @since 0.1.0
 */

import { createElement, useMemo } from 'react';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './contexts/AuthContext';
import { Layout } from './components/Layout';
import { ErrorBoundary } from './components/ErrorBoundary';
import { DashboardPage } from './pages/DashboardPage';
import { ServersPage } from './pages/ServersPage';
import { ServerDetailPage } from './pages/ServerDetailPage';
import { SitesPage } from './pages/SitesPage';
import { CreateSiteWizard } from './pages/CreateSiteWizard';
import { SiteDetailPage } from './pages/SiteDetailPage';
import { SiteToolkitsPage } from './pages/SiteToolkitsPage';
import { ToolkitsPage } from './pages/ToolkitsPage';
import { SettingsPage } from './pages/SettingsPage';
import './styles/dashboard.css';

interface AppProps {
  config: Record<string, unknown>;
}

export function App({ config }: AppProps): React.ReactElement {
  const isAdmin = useMemo(() => Boolean(config?.isAdmin), [config]);

  return createElement(
    AuthProvider,
    null,
    createElement(
      ErrorBoundary,
      null,
      createElement(
        HashRouter,
      null,
      createElement(
        Routes,
        null,
        createElement(
          Route,
          { path: '/', element: createElement(Layout, { isAdmin }) },
          createElement(Route, { index: true, element: createElement(DashboardPage) }),
          createElement(Route, { path: 'servers', element: createElement(ServersPage) }),
          createElement(Route, { path: 'servers/:serverId', element: createElement(ServerDetailPage) }),
          createElement(Route, { path: 'sites', element: createElement(SitesPage) }),
          createElement(Route, { path: 'sites/create', element: createElement(CreateSiteWizard) }),
          createElement(Route, { path: 'sites/:siteId', element: createElement(SiteDetailPage) }),
          createElement(Route, { path: 'sites/:siteId/toolkits', element: createElement(SiteToolkitsPage) }),
          createElement(Route, { path: 'toolkits', element: createElement(ToolkitsPage) }),
          createElement(Route, { path: 'settings', element: createElement(SettingsPage) }),
        ),
        createElement(Route, { path: '*', element: createElement(Navigate, { to: '/', replace: true }) })
      )
      )
    )
  );
}
