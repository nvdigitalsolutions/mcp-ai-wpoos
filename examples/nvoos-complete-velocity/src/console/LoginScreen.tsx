/**
 * Branded sign-in screen — replaces the proxied WordPress login page.
 *
 * Industry standards applied (OWASP Authentication Cheat Sheet + A07:2025,
 * NN/g login-form checklist, WCAG 2.2):
 *  - Visible labels, 16px inputs (no iOS focus-zoom), ≥44px touch targets.
 *  - autocomplete="username" / "current-password" so password managers and
 *    browser autofill work (never autocomplete=off).
 *  - Uniform error message — no account enumeration via WP's specific
 *    reasons.
 *  - Accessible show/hide password toggle (aria-pressed).
 *  - role="alert" errors, username focused on mount, submit disabled while
 *    the request is in flight (no double submits).
 */

import { useEffect, useRef, useState, type FormEvent, type JSX } from 'react';

import { submitWpLogin } from '../login';

interface LoginScreenProps {
  /** Re-run the session bootstrap after a successful sign-in. */
  onSuccess: () => void;
  /** Informational line (e.g. "please click the button below") shown above the form. */
  notice?: string;
}

export function LoginScreen({ onSuccess, notice }: LoginScreenProps): JSX.Element {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const usernameRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    usernameRef.current?.focus();
  }, []);

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (busy) {
      return;
    }
    setBusy(true);
    setError('');
    const result = await submitWpLogin({
      username: username.trim(),
      password,
      remember,
    });
    setBusy(false);
    if (result.ok) {
      onSuccess();
    } else {
      setError(result.message);
    }
  }

  return (
    <div className="nvoos-login">
      <main className="nvoos-login__card">
        <header className="nvoos-login__header">
          <span className="nvoos-login__mark" aria-hidden="true">
            NV
          </span>
          <h1 className="nvoos-login__title">NV oOS Console</h1>
          <p className="nvoos-login__subtitle">Sign in with your site account</p>
        </header>

        {notice ? (
          <p className="nvoos-login__notice" role="status">
            {notice}
          </p>
        ) : null}

        <form className="nvoos-login__form" onSubmit={handleSubmit}>
          <div className="nvoos-login__field">
            <label className="nvoos-login__label" htmlFor="nvoos-login-username">
              Username or email address
            </label>
            <input
              id="nvoos-login-username"
              ref={usernameRef}
              className="nvoos-login__input"
              name="username"
              type="text"
              autoComplete="username"
              autoCapitalize="none"
              autoCorrect="off"
              spellCheck={false}
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              disabled={busy}
              required
            />
          </div>

          <div className="nvoos-login__field">
            <div className="nvoos-login__label-row">
              <label className="nvoos-login__label" htmlFor="nvoos-login-password">
                Password
              </label>
              <button
                type="button"
                className="nvoos-login__toggle"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-pressed={showPassword}
              >
                {showPassword ? 'Hide password' : 'Show password'}
              </button>
            </div>
            <input
              id="nvoos-login-password"
              className="nvoos-login__input"
              name="password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              disabled={busy}
              required
            />
          </div>

          <label className="nvoos-login__remember">
            <input
              type="checkbox"
              checked={remember}
              onChange={(event) => setRemember(event.target.checked)}
              disabled={busy}
            />
            <span>Keep me signed in</span>
          </label>

          {error ? (
            <p className="nvoos-login__error" role="alert">
              {error}
            </p>
          ) : null}

          <button type="submit" className="nvoos-login__submit" disabled={busy} aria-busy={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </main>
    </div>
  );
}
