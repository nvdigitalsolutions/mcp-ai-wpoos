/**
 * Entry point — mounts the Astro Admin SPA into its root element.
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

const rootEl = document.getElementById('astro-admin-root');
if (rootEl) {
  const root = createRoot(rootEl);
  root.render(
    <QueryClientProvider client={queryClient}>
      <AdminApp />
    </QueryClientProvider>
  );
}
