/**
 * Schedule Anything SPA — App Root
 *
 * Sets up React Router, React Query, auth, and tenant providers.
 * All pages live under /src/pages/.
 */

import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '@/contexts/AuthContext';
import { TenantProvider } from '@/contexts/TenantContext';
import { DashboardPage } from '@/pages/DashboardPage';
import { SchedulesPage } from '@/pages/SchedulesPage';
import { BuilderPage } from '@/pages/BuilderPage';
import { PresetsPage } from '@/pages/PresetsPage';
import { HistoryPage } from '@/pages/HistoryPage';
import { AnalyticsPage } from '@/pages/AnalyticsPage';
import { SettingsPage } from '@/pages/SettingsPage';
import { BookingPage } from '@/pages/BookingPage';
import { AppLayout } from '@/components/layout/AppLayout';
import { ErrorBoundary } from '@/components/shared/ErrorBoundary';

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
            </BrowserRouter>
          </TenantProvider>
        </AuthProvider>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
