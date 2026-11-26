// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Vitest setup — jsdom polyfills for the host tests.
 *
 * The suite is mostly jsdom, but a few files opt into the node environment
 * with `// @vitest-environment node` (the connected-app proxy tests spawn a
 * real HTTP server). Setup files run for every environment, so anything that
 * touches a DOM global has to be guarded — otherwise the node files fail at
 * import time with "Element is not defined".
 */

import { afterEach } from 'vitest';

const hasDom = typeof window !== 'undefined' && typeof Element !== 'undefined';

if (hasDom) {
  // jsdom-only helpers: importing them in a node environment throws, so they
  // are pulled in lazily rather than at module scope.
  await import('@testing-library/jest-dom/vitest');
  const { cleanup } = await import('@testing-library/react');

  // vitest globals are disabled in this app, so testing-library's auto-cleanup
  // does not register itself — unmount accumulated renders explicitly.
  afterEach(() => {
    cleanup();
  });

  if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
  }

  if (!window.matchMedia) {
    window.matchMedia = (query: string): MediaQueryList =>
      ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
      }) as unknown as MediaQueryList;
  }
}

class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}

if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver;
}
