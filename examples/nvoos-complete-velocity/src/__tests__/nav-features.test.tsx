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
