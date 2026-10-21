/**
 * ConsoleSidebar tests — nav rendering, dock toggle, collapse behaviour.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import { ConsoleSidebar } from '../ConsoleSidebar';
import type { ProSpaUser } from '@nvoos/pro-spa-v2/api/config';

const user: ProSpaUser = {
  id: 7,
  login: 'admin',
  displayName: 'Console Admin',
  capabilities: ['manage_options'],
};

function renderSidebar(overrides: Partial<Parameters<typeof ConsoleSidebar>[0]> = {}) {
  const props = {
    dockOpen: true,
    onToggleDock: vi.fn(),
    isChatRoute: false,
    user,
    ...overrides,
  };
  render(
    <MemoryRouter initialEntries={['/']}>
      <ConsoleSidebar {...props} />
    </MemoryRouter>,
  );
  return props;
}

describe('ConsoleSidebar', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('renders every nav destination', () => {
    renderSidebar();
    for (const label of [
      'Dashboard',
      'Chat',
      'Assistants',
      'Tools',
      'Workflows',
      'Analytics',
      'Knowledge Graph',
      'Docs',
      'Media Studio',
      'Documents',
      'Settings',
    ]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  it('marks the active route link', () => {
    renderSidebar();
        const dashboardLink = screen.getByRole('link', { name: 'Dashboard' });
    expect(dashboardLink.className).toContain('nvoos-console-nav__link--active');
  });

  it('fires the dock toggle with the pressed state exposed', async () => {
    const props = renderSidebar();
    const toggle = screen.getByRole('button', { name: /chat dock/i });
    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await userEvent.click(toggle);
    expect(props.onToggleDock).toHaveBeenCalledTimes(1);
  });

  it('disables the dock toggle on the full-page chat route', () => {
    renderSidebar({ isChatRoute: true, dockOpen: false });
    const toggle = screen.getByRole('button', { name: /chat dock/i });
    expect(toggle).toBeDisabled();
  });

  it('collapses to an icon rail and expands again', async () => {
    renderSidebar();
    const nav = screen.getByRole('complementary', { name: /console navigation/i });
    expect(nav.className).not.toContain('nvoos-console-nav--collapsed');

    const collapse = screen.getByRole('button', { name: /collapse navigation/i });
    await userEvent.click(collapse);
    expect(nav.className).toContain('nvoos-console-nav--collapsed');

    const expand = screen.getByRole('button', { name: /expand navigation/i });
    await userEvent.click(expand);
    expect(nav.className).not.toContain('nvoos-console-nav--collapsed');
  });
});
