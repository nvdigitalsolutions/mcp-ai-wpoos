/**
 * Entry point — mounts the Funiq Admin SPA into the WordPress admin page.
 */
import { createRoot } from '@wordpress/element';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AdminApp } from './AdminApp';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
});

const rootEl = document.getElementById('funiq-admin-root');
if (rootEl) {
  const root = createRoot(rootEl);
  root.render(
    <QueryClientProvider client={queryClient}>
      <AdminApp />
    </QueryClientProvider>
  );
}
