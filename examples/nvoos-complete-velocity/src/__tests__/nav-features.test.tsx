// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Nav gating tests — the visibility matrix and the RequireGate route guard.
 *
 * Covers: capability + feature filtering, the fail-open rule for an absent
 * feature map, and redirects for directly-visited gated routes.
 */

import { describe, expect, it } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { render, screen } from '@testing-library/react';

import {
  CONSOLE_NAV_ITEMS,
  RequireGate,
  gateForPath,
  isNavItemVisible,
  unavailableNavItems,
} from '../console/nav-features';

const ADMIN_CAPS = ['manage_options', 'edit_posts'];
const EDITOR_CAPS = ['edit_posts'];

const FEATURES_NONE: Record<string, boolean> = {
  docs_hub: false,
  knowledge_graph: false,
  media_studio: false,
  document_editor: false,
};

describe('isNavItemVisible', () => {
  it('shows ungated items for any logged-in session', () => {
    expect(isNavItemVisible({}, FEATURES_NONE, EDITOR_CAPS)).toBe(true);
  });

  it('hides items whose capability the session lacks', () => {
    expect(isNavItemVisible({ capability: 'manage_options' }, undefined, EDITOR_CAPS)).toBe(
      false,
    );
    expect(isNavItemVisible({ capability: 'manage_options' }, undefined, ADMIN_CAPS)).toBe(true);
  });

  it('hides items whose feature the server reports unavailable', () => {
    expect(
      isNavItemVisible({ capability: 'manage_options', feature: 'docs_hub' }, FEATURES_NONE, ADMIN_CAPS),
    ).toBe(false);
  });

  it('fails open when the feature map is absent or unknown', () => {
    expect(
      isNavItemVisible({ capability: 'manage_options', feature: 'docs_hub' }, undefined, ADMIN_CAPS),
    ).toBe(true);
    expect(
      isNavItemVisible({ capability: 'manage_options', feature: 'docs_hub' }, {}, ADMIN_CAPS),
    ).toBe(true);
  });

  it('hides items whose endpoint the backend does not serve', () => {
    const gate = { capability: 'manage_options', endpoint: 'mediaCc' as const };

    expect(isNavItemVisible(gate, undefined, ADMIN_CAPS, { mediaCc: '' })).toBe(false);
    expect(isNavItemVisible(gate, undefined, ADMIN_CAPS, { mediaCc: '/api/v1/pro/media-cc' })).toBe(
      true,
    );
  });

  it('fails open when the endpoint map is absent', () => {
    // Same rule as the feature map: a failed `/features` call must not empty
    // the sidebar.
    expect(
      isNavItemVisible(
        { capability: 'manage_options', endpoint: 'mediaCc' },
        undefined,
        ADMIN_CAPS,
        undefined,
      ),
    ).toBe(true);
  });

  it('declares an endpoint gate for the surfaces a backend may not serve', () => {
    const gated = CONSOLE_NAV_ITEMS.filter((item) => item.endpoint).map((item) => item.path);

    expect(gated).toEqual(
      expect.arrayContaining([
        '/toolkits',
        '/professions',
        '/org-teams',
        '/chat-channels',
        '/connections',
        '/media-ops',
        '/knowledge-graph',
        '/docs',
        '/media',
        '/documents',
      ]),
    );
  });

  it('shows gated items when capability + feature are both satisfied', () => {
    expect(
      isNavItemVisible(
        { capability: 'manage_options', feature: 'docs_hub' },
        { docs_hub: true },
        ADMIN_CAPS,
      ),
    ).toBe(true);
  });
});

describe('CONSOLE_NAV_ITEMS catalogue', () => {
  it('covers every gated route with a gate lookup', () => {
    for (const item of CONSOLE_NAV_ITEMS) {
      const gate = gateForPath(item.path);
      expect(gate.capability).toBe(item.capability);
      expect(gate.feature).toBe(item.feature);
    }
  });

  it('hides the docs item for editors without the feature', () => {
    const docs = CONSOLE_NAV_ITEMS.find((item) => item.path === '/docs');
    expect(docs).toBeDefined();
    expect(isNavItemVisible(docs!, FEATURES_NONE, ADMIN_CAPS)).toBe(false);
  });
});

describe('RequireGate', () => {
  function renderGate(features?: Record<string, boolean>, capabilities?: string[]) {
    return render(
      <MemoryRouter initialEntries={['/docs']}>
        <Routes>
          <Route path="/" element={<div>dashboard-home</div>} />
          <Route
            path="/docs"
            element={
              <RequireGate
                gate={gateForPath('/docs')}
                features={features}
                capabilities={capabilities}
              >
                <div>docs-page</div>
              </RequireGate>
            }
          />
        </Routes>
      </MemoryRouter>,
    );
  }

  it('renders the page for an entitled session', () => {
    renderGate({ docs_hub: true }, ADMIN_CAPS);
    expect(screen.getByText('docs-page')).toBeInTheDocument();
  });

  it('redirects to the dashboard when the feature is unavailable', () => {
    renderGate(FEATURES_NONE, ADMIN_CAPS);
    expect(screen.getByText('dashboard-home')).toBeInTheDocument();
    expect(screen.queryByText('docs-page')).not.toBeInTheDocument();
  });

  it('redirects when the capability is missing even with the feature on', () => {
    renderGate({ docs_hub: true }, EDITOR_CAPS);
    expect(screen.getByText('dashboard-home')).toBeInTheDocument();
  });
});

describe('unavailableNavItems', () => {
  const REASONS = {
    contentGraph: 'external-product',
    chatChannels: 'toolkit-shaped',
    orgTeams: 'runtime-adaptable',
    mediaCc: 'connected-service',
  };

  it('lists an external product with operator copy', () => {
    const items = unavailableNavItems(undefined, ADMIN_CAPS, { contentGraph: '' }, REASONS);
    const graph = items.find((item) => item.path === '/knowledge-graph');

    expect(graph).toBeDefined();
    expect(graph?.label).toBe('Knowledge Graph');
    expect(graph?.reason).toMatch(/separate product/);
  });

  it('lists exactly the surfaces the sidebar hides', () => {
    // The card and the nav must not disagree: both delegate to the same gate.
    const endpoints = { contentGraph: '', docsHub: '' };
    const hidden = CONSOLE_NAV_ITEMS.filter(
      (item) => !isNavItemVisible(item, undefined, ADMIN_CAPS, endpoints),
    ).map((item) => item.path);
    const listed = unavailableNavItems(undefined, ADMIN_CAPS, endpoints, {}).map(
      (item) => item.path,
    );

    expect(listed.sort()).toEqual(hidden.sort());
  });

  it('does not advertise a surface the session cannot use', () => {
    // A missing capability is a permission, not a runtime limitation —
    // listing it would leak admin-only surfaces to an editor.
    const items = unavailableNavItems(undefined, EDITOR_CAPS, { contentGraph: '' }, REASONS);

    expect(items.find((item) => item.path === '/knowledge-graph')).toBeUndefined();
  });

  it('reports a feature-disabled surface with its own copy', () => {
    const items = unavailableNavItems({ docs_hub: false }, ADMIN_CAPS, { docsHub: '/x' }, {});
    const docs = items.find((item) => item.path === '/docs');

    expect(docs?.reason).toBe('Disabled on this site.');
  });

  it('fails open when both maps are absent', () => {
    expect(unavailableNavItems(undefined, ADMIN_CAPS, undefined, undefined)).toEqual([]);
  });
});
