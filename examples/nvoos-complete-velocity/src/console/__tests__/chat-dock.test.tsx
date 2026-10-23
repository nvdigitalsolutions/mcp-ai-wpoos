/**
 * ChatDock tests — mode-driven conversation-list collapse.
 *
 * The docked chat starts with the conversation list collapsed and the
 * full-page /chat breakout starts with it open; the status bar ☰ toggle
 * drives the same state in either mode. Pins the sidebar-visibility
 * contract that previously shipped with the list pinned open and the
 * toggle hidden (display: none) in the console shell.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import { ChatDock } from '../ChatDock';
import { StatusBar } from '@nvoos/pro-spa-v2/components/layout/StatusBar';
import { useUIStore } from '@nvoos/pro-spa-v2/stores/uiStore';
import type { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';

// The heavy spa-v2 children are out of scope here — ChatDock's job is the
// mode/class wiring; their internals are covered by spa-v2's own suites.
vi.mock('@nvoos/pro-spa-v2/features/chat/ChatPage', () => ({
  ChatPage: () => <div data-testid="chat-page" />,
}));
vi.mock('@nvoos/pro-spa-v2/components/layout/ChatSidebar', () => ({
  ChatSidebar: () => <div data-testid="chat-sidebar" />,
}));

function fakeTranscripts(sessionCount: number | null): ReturnType<typeof useTranscripts> {
  return {
    sessions: sessionCount === null ? null : Array.from({ length: sessionCount }, (_, i) => ({
      session_key: `session-${i + 1}`,
    })),
    sessionKey: 'session-1',
    isLoading: false,
    unavailableMessage: null,
    error: null,
    refreshList: vi.fn(async () => {}),
    selectSession: vi.fn(async () => {}),
    deleteSession: vi.fn(async () => {}),
    startNewSession: vi.fn(() => {}),
  } as unknown as ReturnType<typeof useTranscripts>;
}

function makeProps(overrides: Partial<Parameters<typeof ChatDock>[0]> = {}) {
  return {
    transcripts: fakeTranscripts(2),
    mode: 'docked' as const,
    hidden: false,
    nonce: 'test-nonce',
    assistantsEndpoint: 'https://example.com/wp-json/mcp-ai/v1/assistants',
    apiRoot: 'https://example.com/wp-json',
    mediaEndpoint: 'https://example.com/wp-json/wp/v2/media',
    onClose: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  useUIStore.setState({ sidebarOpen: true, theme: 'light' });
});

describe('ChatDock', () => {
  it('collapses the conversation list in docked mode and opens it in fullpage mode', async () => {
    const { rerender, container } = render(
      <MemoryRouter>
        <ChatDock {...makeProps()} />
      </MemoryRouter>,
    );

    // Docked: the mode-sync effect collapses the sidebar.
    await waitFor(() => {
      expect(container.querySelector('.nvoos-console-dock')).toHaveClass(
        'nvoos-console-dock--sidebar-collapsed',
      );
    });

    // Fullpage: the mode-sync effect reopens it.
    rerender(
      <MemoryRouter>
        <ChatDock {...makeProps({ mode: 'fullpage' })} />
      </MemoryRouter>,
    );
    await waitFor(() => {
      expect(container.querySelector('.nvoos-console-dock')).not.toHaveClass(
        'nvoos-console-dock--sidebar-collapsed',
      );
    });
  });

  it('offers a theme switch in the dock header', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <ChatDock {...makeProps()} />
      </MemoryRouter>,
    );

    const switchButton = screen.getByRole('button', {
      name: /switch to dark mode/i,
    });
    await user.click(switchButton);

    expect(useUIStore.getState().theme).toBe('dark');
    expect(
      screen.getByRole('button', { name: /switch to light mode/i }),
    ).toBeInTheDocument();
  });

  it('lets the status bar ☰ toggle expand and collapse the dock sidebar', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <MemoryRouter>
        <ChatDock {...makeProps()} />
        <StatusBar
          transcriptsEndpoint=""
          nonce="test-nonce"
        />
      </MemoryRouter>,
    );

    // Docked mount collapses the list; the toggle then reads "Open sidebar".
    await waitFor(() => {
      expect(container.querySelector('.nvoos-console-dock')).toHaveClass(
        'nvoos-console-dock--sidebar-collapsed',
      );
    });
    const toggle = screen.getByRole('button', { name: /open sidebar/i });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');

    // Expand via the status bar toggle.
    await user.click(toggle);
    await waitFor(() => {
      expect(container.querySelector('.nvoos-console-dock')).not.toHaveClass(
        'nvoos-console-dock--sidebar-collapsed',
      );
    });
    expect(screen.getByRole('button', { name: /close sidebar/i })).toHaveAttribute(
      'aria-expanded',
      'true',
    );

    // Collapse again.
    await user.click(screen.getByRole('button', { name: /close sidebar/i }));
    await waitFor(() => {
      expect(container.querySelector('.nvoos-console-dock')).toHaveClass(
        'nvoos-console-dock--sidebar-collapsed',
      );
    });
  });
});
