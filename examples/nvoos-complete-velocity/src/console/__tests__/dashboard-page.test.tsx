/**
 * DashboardPage tests — welcome card, live stats, quick-action navigation.
 */

import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import { DashboardPage } from '../DashboardPage';
import type { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';
import type { ProSpaUser } from '@nvoos/pro-spa-v2/api/config';

const user: ProSpaUser = {
  id: 7,
  login: 'admin',
  displayName: 'Console Admin',
  capabilities: ['manage_options'],
};

function fakeTranscripts(sessionCount: number): ReturnType<typeof useTranscripts> {
  return {
    sessionKey: 'session-1',
    isLoading: false,
    initialMessages: [],
    sessions: Array.from({ length: sessionCount }, (_, i) => ({
      session_key: `session-${i + 1}`,
    })),
    unavailableMessage: null,
    error: null,
    refreshList: vi.fn(async () => {}),
    selectSession: vi.fn(async () => {}),
    startNewSession: vi.fn(),
    deleteSession: vi.fn(async () => {}),
  } as unknown as ReturnType<typeof useTranscripts>;
}

function renderDashboard(overrides: Partial<Parameters<typeof DashboardPage>[0]> = {}) {
  const props = {
    transcripts: fakeTranscripts(1),
    user,
    dockOpen: true,
    onToggleDock: vi.fn(),
    ...overrides,
  };
  render(
    <MemoryRouter initialEntries={['/']}>
      <DashboardPage {...props} />
    </MemoryRouter>,
  );
  return props;
}

describe('DashboardPage', () => {
  it('renders the welcome card with the signed-in user', () => {
    renderDashboard();
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByText(/Signed in as Console Admin/)).toBeInTheDocument();
  });

  it('reports live stats from the stores and transcripts', () => {
    renderDashboard({ transcripts: fakeTranscripts(3) });
    expect(screen.getByText('Conversations')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.getByText('Open')).toBeInTheDocument();
  });

  it('links to every addon page', () => {
    renderDashboard();
    for (const label of [
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
      expect(screen.getByRole('link', { name: new RegExp(label, 'i') })).toBeInTheDocument();
    }
    expect(screen.getByRole('link', { name: /Open Chat/i })).toHaveAttribute('href', '/chat');
  });

  it('toggles the chat dock from the quick actions', async () => {
    const props = renderDashboard();
    const toggle = screen.getByRole('button', { name: /close chat dock/i });
    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await userEvent.click(toggle);
    expect(props.onToggleDock).toHaveBeenCalledTimes(1);
  });

  it('labels the dock action by its current state', () => {
    renderDashboard({ dockOpen: false });
    expect(screen.getByRole('button', { name: /open chat dock/i })).toBeInTheDocument();
    expect(screen.getByText('Closed')).toBeInTheDocument();
  });
});
