/**
 * LoginScreen UI tests — form submission, uniform error display, and the
 * informational notice (session-expired) surface.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { LoginScreen } from '../LoginScreen';
import { submitWpLogin } from '../../login';

vi.mock('../../login', () => ({
  submitWpLogin: vi.fn(),
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
    await user.type(screen.getByLabelText('Password'), 'secret');
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
    await user.type(screen.getByLabelText('Password'), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid username or password.');
    expect(onSuccess).not.toHaveBeenCalled();
  });

  it('renders the informational notice when provided', () => {
    render(
      <LoginScreen
        onSuccess={() => {}}
        notice="Your session expired — please sign in again."
      />,
    );
    expect(screen.getByRole('status')).toHaveTextContent('Your session expired');
  });

  it('toggles password visibility with an accessible pressed state', async () => {
    render(<LoginScreen onSuccess={() => {}} />);

    const toggle = screen.getByRole('button', { name: 'Show password' });
    expect(toggle).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');

    const user = userEvent.setup();
    await user.click(toggle);

    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'text');
  });
});
