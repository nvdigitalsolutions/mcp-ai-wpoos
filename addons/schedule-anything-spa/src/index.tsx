/**
 * Schedule Anything SPA — Entry Point
 *
 * Mounts the React app into the DOM. Supports both:
 * 1. Direct mount: <div id="sa-root"></div> (for standalone SPA)
 * 2. Shortcode mount: data-sa-mount attribute (for WP shortcode embedding)
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles/global.css';

// Live accessibility audit output in dev builds only — the dynamic import
// is dead-code-eliminated in production bundles.
if (import.meta.env.DEV) {
  Promise.all([import('react'), import('react-dom'), import('@axe-core/react')])
    .then(([React, ReactDOM, axe]) => {
      axe.default(React, ReactDOM, 1000);
    })
    .catch(() => {
      /* axe unavailable — non-fatal */
    });
}

function mountApp() {
  // Try standalone mount first
  const rootElement = document.getElementById('sa-root');
  if (rootElement) {
    const root = createRoot(rootElement);
    root.render(<App />);
    return;
  }

  // Try shortcode/block mounts
  const mounts = document.querySelectorAll('[data-sa-mount]');
  mounts.forEach((mount) => {
    const root = createRoot(mount as HTMLElement);
    root.render(<App />);
  });

  // Auto-mount if no explicit mount point found but we're on the right domain
  if (mounts.length === 0 && window.location.hostname.includes('scheduleanything.com')) {
    const autoRoot = document.createElement('div');
    autoRoot.id = 'sa-root';
    document.body.appendChild(autoRoot);
    const root = createRoot(autoRoot);
    root.render(<App />);
  }
}

// Wait for DOM to be ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountApp);
} else {
  mountApp();
}
