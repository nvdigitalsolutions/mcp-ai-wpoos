/**
 * Schedule Anything SPA — App Root
 *
 * Sets up React Router, React Query, auth, and tenant providers.
 * All pages live under /src/pages/.
 */

import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '@/contexts/AuthContext';
import { TenantProvider } from '@/contexts/TenantContext';
import { DashboardPage } from '@/pages/DashboardPage';
import { SchedulesPage } from '@/pages/SchedulesPage';
import { SettingsPage } from '@/pages/SettingsPage';
import { BookingPage } from '@/pages/BookingPage';
import { AppLayout } from '@/components/layout/AppLayout';
import { ErrorBoundary } from '@/components/shared/ErrorBoundary';
import { Toaster } from '@/components/ui/toast';

/**
 * Route-level code splitting: the heavy surfaces (React Flow builder,
 * chart library analytics) load only when visited, keeping the initial
 * dashboard bundle lean. Named exports are mapped to default for lazy().
 */
const BuilderPage = lazy(() =>
  import('@/pages/BuilderPage').then((m) => ({ default: m.BuilderPage }))
);
const AnalyticsPage = lazy(() =>
  import('@/pages/AnalyticsPage').then((m) => ({ default: m.AnalyticsPage }))
);
const HistoryPage = lazy(() =>
  import('@/pages/HistoryPage').then((m) => ({ default: m.HistoryPage }))
);
const PresetsPage = lazy(() =>
  import('@/pages/PresetsPage').then((m) => ({ default: m.PresetsPage }))
);

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 2,
      refetchOnWindowFocus: true,
    },
  },
});

/**
 * Protected route wrapper.
 * Redirects to WordPress login if the user is not authenticated.
 */
function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const auth = useAuth();

  if (auth.isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
      </div>
    );
  }

  if (!auth.isLoggedIn) {
    // Redirect to WordPress login, then back to this page
    const returnUrl = encodeURIComponent(window.location.href);
    window.location.href = `/wp-login.php?redirect_to=${returnUrl}`;
    return null;
  }

  return <>{children}</>;
}

export function App() {
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <TenantProvider>
            <BrowserRouter>
              <Suspense fallback={<PageFallback />}>
                <Routes>
                  {/* Public routes (no auth required) */}
                  <Route path="/book/:tenant" element={<BookingPage />} />
                  <Route path="/book" element={<BookingPage />} />

                  {/* Tenant admin routes (auth required) */}
                  <Route
                    path="/*"
                    element={
                      <ProtectedRoute>
                        <AppLayout>
                          <Routes>
                            <Route path="/dashboard" element={<DashboardPage />} />
                            <Route path="/schedules" element={<SchedulesPage />} />
                            <Route path="/builder" element={<BuilderPage />} />
                            <Route path="/builder/:id" element={<BuilderPage />} />
                            <Route path="/presets" element={<PresetsPage />} />
                            <Route path="/history" element={<HistoryPage />} />
                            <Route path="/analytics" element={<AnalyticsPage />} />
                            <Route path="/settings" element={<SettingsPage />} />
                            <Route path="/" element={<Navigate to="/dashboard" replace />} />
                          </Routes>
                        </AppLayout>
                      </ProtectedRoute>
                    }
                  />
                </Routes>
              </Suspense>
            </BrowserRouter>
          </TenantProvider>
        </AuthProvider>
      </QueryClientProvider>
      <Toaster />
    </ErrorBoundary>
  );
}

/**
 * Suspense fallback for lazy-loaded routes.
 */
function PageFallback() {
  return (
    <div className="flex items-center justify-center min-h-screen">
      <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
    </div>
  );
}
