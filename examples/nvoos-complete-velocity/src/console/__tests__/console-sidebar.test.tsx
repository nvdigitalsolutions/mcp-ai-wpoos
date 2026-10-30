/**
 * ConsoleSidebar tests — grouped nav rendering, disclosure behaviour,
 * dock toggle, collapse rail, and the visibility matrix.
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
  capabilities: ['manage_options', 'edit_posts'],
};

function makeProps(overrides: Partial<Parameters<typeof ConsoleSidebar>[0]> = {}) {
  return {
    dockOpen: true,
    onToggleDock: vi.fn(),
    isChatRoute: false,
    user,
    onSignOut: vi.fn(),
    ...overrides,
  };
}

function renderSidebar(
  overrides: Partial<Parameters<typeof ConsoleSidebar>[0]> = {},
  route = '/',
) {
  const props = makeProps(overrides);
  render(
    <MemoryRouter initialEntries={[route]}>
      <ConsoleSidebar {...props} />
    </MemoryRouter>,
  );
  return props;
}

describe('ConsoleSidebar', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('shows only the workspace by default and reveals collapsed groups on demand', async () => {
    renderSidebar();
    // Only the daily-use Workspace group starts expanded — the rail stays
    // short; every other destination is one click away.
    for (const label of ['Dashboard', 'Chat', 'Assistants', 'Blueprints', 'Tools']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument();
    }
    expect(screen.queryByRole('link', { name: 'Workflows' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'CRM' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Analytics' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();

    const automation = screen.getByRole('button', { name: /automation/i });
    expect(automation).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(automation);
    expect(automation).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('link', { name: 'Workflows' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Approvals' })).toBeInTheDocument();

    const commandCenters = screen.getByRole('button', { name: /command centers/i });
    expect(commandCenters).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(commandCenters);
    expect(screen.getByRole('link', { name: 'CRM' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Food & Beverage' })).toBeInTheDocument();
  });

  it('hides toolkit command centers when the server reports them disabled', async () => {
    renderSidebar({
      features: {
        fnb: false,
        crm: false,
        project_management: false,
        calendar_booking: false,
      },
    });
    await userEvent.click(screen.getByRole('button', { name: /command centers/i }));
    for (const label of ['Food & Beverage', 'CRM', 'Projects', 'Calendar']) {
      expect(screen.queryByRole('link', { name: label })).not.toBeInTheDocument();
    }
    // Non-gated command centers stay.
    expect(screen.getByRole('link', { name: 'Teams' })).toBeInTheDocument();
  });

  it('fails open when the feature map is absent', async () => {
    renderSidebar({ features: undefined });
    await userEvent.click(screen.getByRole('button', { name: /command centers/i }));
    for (const label of ['Food & Beverage', 'CRM', 'Projects', 'Calendar']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument();
    }
  });

  it('auto-expands the group holding the active route', () => {
    renderSidebar({}, '/crm');
    const toggle = screen.getByRole('button', { name: /command centers/i });
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('link', { name: 'CRM' })).toHaveAttribute('aria-current', 'page');
  });

  it('marks the active route link', () => {
    renderSidebar();
    const dashboardLink = screen.getByRole('link', { name: 'Dashboard' });
    expect(dashboardLink.className).toContain('nvoos-console-nav__link--active');
  });

  it('persists a group toggle across remounts', async () => {
    const first = render(
      <MemoryRouter initialEntries={['/']}>
        <ConsoleSidebar {...makeProps()} />
      </MemoryRouter>,
    );
    // Automation is default-collapsed — expand it and expect the choice to
    // survive a remount (the active group, Workspace, re-expands by design).
    await userEvent.click(screen.getByRole('button', { name: /automation/i }));
    expect(screen.getByRole('button', { name: /automation/i })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
    first.unmount();

    render(
      <MemoryRouter initialEntries={['/']}>
        <ConsoleSidebar {...makeProps()} />
      </MemoryRouter>,
    );
    expect(screen.getByRole('button', { name: /automation/i })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
  });

  it('hides groups with no visible items for a non-admin session', () => {
    renderSidebar({ user: { ...user, capabilities: [] } });
    for (const label of ['Dashboard', 'Chat', 'Assistants', 'Tools']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument();
    }
    expect(screen.queryByRole('button', { name: /automation/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /command centers/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /admin/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
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

  it('flattens groups into icon links in the collapsed rail', async () => {
    renderSidebar();
    await userEvent.click(screen.getByRole('button', { name: /collapse navigation/i }));
    // Group toggles disappear in the rail; destinations stay reachable.
    expect(screen.queryByRole('button', { name: /command centers/i })).not.toBeInTheDocument();
    const crm = screen.getByRole('link', { name: 'CRM' });
    expect(crm).toBeInTheDocument();
    expect(crm).toHaveAttribute('title', 'CRM');
  });

  it('signs out through the footer action and disables the button while in flight', async () => {
    let resolveSignOut: () => void = () => {};
    const onSignOut = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          resolveSignOut = resolve;
        }),
    );
    renderSidebar({ onSignOut });

    const button = screen.getByRole('button', { name: 'Sign out' });
    await userEvent.click(button);

    expect(onSignOut).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Signing out…' })).toBeDisabled();

    resolveSignOut();
    await screen.findByRole('button', { name: 'Sign out' });
  });
});
