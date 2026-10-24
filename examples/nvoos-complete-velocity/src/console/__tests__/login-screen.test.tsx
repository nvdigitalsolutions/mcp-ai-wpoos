/**
 * LoginScreen UI tests — form submission, uniform error display, the
 * informational notice (session-expired) surface, and the magic-link
 * (passwordless) mode.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { LoginScreen } from '../LoginScreen';
import { requestMagicLink, submitWpLogin } from '../../login';

vi.mock('../../login', () => ({
  submitWpLogin: vi.fn(),
  requestMagicLink: vi.fn(),
}));

describe('LoginScreen', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('submits credentials and calls onSuccess when the sign-in succeeds', async () => {
    vi.mocked(submitWpLogin).mockResolvedValue({ ok: true, message: '' });
    const onSuccess = vi.fn();
    render(<LoginScreen onSuccess={onSuccess} />);

    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Username or email address'), 'admin');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(submitWpLogin).toHaveBeenCalledWith({
      username: 'admin',
      password: 'secret',
      remember: true,
    });
    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('shows the uniform error when the sign-in fails', async () => {
    vi.mocked(submitWpLogin).mockResolvedValue({
      ok: false,
      message: 'Invalid username or password.',
    });
    const onSuccess = vi.fn();
    render(<LoginScreen onSuccess={onSuccess} />);

    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Username or email address'), 'admin');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid username or password.');
    expect(onSuccess).not.toHaveBeenCalled();
  });

  it('renders the informational notice when provided', () => {
    render(
      <LoginScreen
        onSuccess={() => {}}
        notice="Please click the button below."
      />,
    );
    expect(screen.getByRole('status')).toHaveTextContent('Please click the button below');
  });

  it('renders the error line as an alert when provided', () => {
    render(
      <LoginScreen
        onSuccess={() => {}}
        error="This sign-in link is invalid or has expired. Request a new one."
      />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('This sign-in link is invalid or has expired.');
  });

  it('toggles password visibility with an accessible pressed state', async () => {
    render(<LoginScreen onSuccess={() => {}} />);

    const toggle = screen.getByRole('button', { name: 'Show password' });
    expect(toggle).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByLabelText('Password', { selector: 'input' })).toHaveAttribute('type', 'password');

    const user = userEvent.setup();
    await user.click(toggle);

    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByLabelText('Password', { selector: 'input' })).toHaveAttribute('type', 'text');
  });

  describe('magic-link mode', () => {
    it('switches to the email form via the tab and requests a link', async () => {
      vi.mocked(requestMagicLink).mockResolvedValue({ ok: true, message: '' });
      render(<LoginScreen onSuccess={() => {}} />);

      const user = userEvent.setup();
      await user.click(screen.getByRole('tab', { name: 'Magic link' }));

      expect(screen.getByRole('tab', { name: 'Magic link' })).toHaveAttribute(
        'aria-selected',
        'true',
      );
      expect(screen.getByRole('tab', { name: 'Password' })).toHaveAttribute(
        'aria-selected',
        'false',
      );

      await user.type(screen.getByLabelText('Email address'), 'ops@example.com');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in link' }));

      expect(requestMagicLink).toHaveBeenCalledWith('ops@example.com');
    });

    it('shows the truthful sent state with resend and back controls', async () => {
      vi.mocked(requestMagicLink).mockResolvedValue({ ok: true, message: '' });
      render(<LoginScreen onSuccess={() => {}} />);

      const user = userEvent.setup();
      await user.click(screen.getByRole('tab', { name: 'Magic link' }));
      await user.type(screen.getByLabelText('Email address'), 'ops@example.com');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in link' }));

      const status = await screen.findByRole('status');
      expect(status).toHaveTextContent('ops@example.com');
      expect(status).toHaveTextContent('expires in 15 minutes');

      await user.click(screen.getByRole('button', { name: 'Resend link' }));
      expect(requestMagicLink).toHaveBeenCalledTimes(2);
    });

    it('shows the uniform error when the request fails', async () => {
      vi.mocked(requestMagicLink).mockResolvedValue({
        ok: false,
        message: 'Sign-in is temporarily unavailable. Please try again.',
      });
      render(<LoginScreen onSuccess={() => {}} />);

      const user = userEvent.setup();
      await user.click(screen.getByRole('tab', { name: 'Magic link' }));
      await user.type(screen.getByLabelText('Email address'), 'ops@example.com');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in link' }));

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Sign-in is temporarily unavailable.',
      );
    });
  });
});
