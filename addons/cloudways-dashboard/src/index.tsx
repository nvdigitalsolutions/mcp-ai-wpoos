/**
 * NV oOS Cloudways Dashboard — Entry Point
 *
 * Mounts the React SPA into every element matching `.nvoos-cloudways-dashboard-root`.
 *
 * @since 0.1.0
 */

import { createElement, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';

// TypeScript: declare process.env for esbuild define replacement.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds.
declare const process: { env: { NODE_ENV: string } };

// Load @axe-core/react in development builds for live accessibility audit output.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds,
// making this block dead code that is eliminated by tree-shaking.
if ( process.env.NODE_ENV !== 'production' ) {
	Promise.all( [
		import( 'react' ),
		import( 'react-dom' ),
		import( '@axe-core/react' ),
	] ).then( ( [ React, ReactDOM, axe ] ) => {
		axe.default( React, ReactDOM, 1000 );
	} ).catch( () => { /* axe unavailable */ } );
}

declare global {
  interface Window {
    NVOOS_CLOUDWAYS_DASHBOARD: {
      apiUrl: string;
      proApi: string;
      baseApi: string;
      tkApi: string;
      nonce: string;
      config: Record<string, unknown>;
      locale: string;
    };
    NVoOSCloudwaysDashboard: {
      mount: (element: HTMLElement) => void;
    };
  }
}

function mount(element: HTMLElement): void {
  const configStr = element.dataset.config || '{}';
  let config: Record<string, unknown> = {};
  try {
    config = JSON.parse(configStr);
  } catch {
    // ignore parse errors; use defaults
  }

  const root = createRoot(element);
  root.render(
    createElement(StrictMode, null, createElement(App, { config }))
  );
}

function autoMount(): void {
  const roots = document.querySelectorAll<HTMLElement>('.nvoos-cloudways-dashboard-root');
  roots.forEach((el) => {
    if (!el.dataset.mounted) {
      mount(el);
      el.dataset.mounted = '1';
    }
  });
}

// Expose the mount function globally so the admin-page inline script can call it.
if (typeof window !== 'undefined') {
  window.NVoOSCloudwaysDashboard = { mount };
}

// Auto-mount on DOM ready.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoMount);
  } else {
    autoMount();
  }
}
