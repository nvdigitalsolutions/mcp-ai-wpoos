/**
 * NV oOS Skote — SPA entry point (Phase 1 stub).
 *
 * The real Skote tree is imported by `bin/import-skote.sh` and replaces this
 * file's neighbours under `src/`. For Phase 1 we ship a minimal stub so the
 * build pipeline produces a valid bundle and site admins can verify that
 * asset enqueue + the `window.nvoosSkote` payload work end-to-end before
 * licensing Skote.
 */

import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import App from './App';

declare global {
	interface Window {
		nvoosSkote?: {
			version: string;
			restUrl: string;
			restNonce: string;
			userId: number;
			userDisplayName: string;
			capabilities: string[];
			proEnabled: boolean;
			woocommerceEnabled: boolean;
			jetengineEnabled: boolean;
			i18nLocale: string;
			rootElementId: string;
			context: Record<string, unknown>;
			endpoints: Record<string, string>;
			pro?: { workflowBuilder?: string; orchestration?: string };
		};
	}
}

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			staleTime: 30_000,
			retry: 1,
			refetchOnWindowFocus: false,
		},
	},
});

function mount(): void {
	const settings = window.nvoosSkote;
	const rootId = settings?.rootElementId ?? 'nvoos-skote-root';
	const container = document.getElementById(rootId);
	if (!container) {
		return;
	}
	const root = createRoot(container);
	root.render(
		<React.StrictMode>
			<QueryClientProvider client={queryClient}>
				<App />
			</QueryClientProvider>
		</React.StrictMode>
	);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
