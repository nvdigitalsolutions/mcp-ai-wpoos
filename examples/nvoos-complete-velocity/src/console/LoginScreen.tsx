/**
 * Branded sign-in screen — replaces the proxied WordPress login page.
 *
 * Industry standards applied (OWASP Authentication Cheat Sheet + A07:2025,
 * NN/g login-form checklist, WCAG 2.2, WAI-ARIA tabs pattern):
 *  - Visible labels, 16px inputs (no iOS focus-zoom), ≥44px touch targets.
 *  - autocomplete="username" / "current-password" / "email" so password
 *    managers and browser autofill work (never autocomplete=off).
 *  - Uniform error message — no account enumeration via WP's specific
 *    reasons (the magic-link endpoint answers identically for known and
 *    unknown accounts).
 *  - Accessible show/hide password toggle (aria-pressed).
 *  - role="alert" errors, inputs focused on mount/mode switch, submit
 *    disabled while the request is in flight (no double submits).
 *  - Passwordless "magic link" mode: requests a one-shot emailed sign-in
 *    link (15-min expiry, single-use — backend-enforced) and renders a
 *    truthful "check your inbox" state for every accepted identifier.
 */

import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent, type JSX } from 'react';

import { requestMagicLink, submitWpLogin } from '../login';
import nvoosIcon from '../assets/nvoos-icon.svg';

interface LoginScreenProps {
  /** Re-run the session bootstrap after a successful sign-in. */
  onSuccess: () => void;
  /** Informational line (e.g. "please click the button below") shown above the form. */
  notice?: string;
  /** Error line (e.g. an invalid magic-link token) — announced as an alert. */
  error?: string;
}

type SignInMode = 'password' | 'magic';

export function LoginScreen({ onSuccess, notice, error }: LoginScreenProps): JSX.Element {
  const [mode, setMode] = useState<SignInMode>('password');

  // Password flow.
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [showPassword, setShowPassword] = useState(false);

  // Magic-link flow.
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);

  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');
  const usernameRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const tabsRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    usernameRef.current?.focus();
  }, []);

  // Focus follows the visible panel (WAI-ARIA tabs: selection follows focus).
  useEffect(() => {
    if (mode === 'magic' && !sent) {
      emailRef.current?.focus();
    } else if (mode === 'password') {
      usernameRef.current?.focus();
    }
  }, [mode, sent]);

  function switchMode(next: SignInMode): void {
    setMode(next);
    setFormError('');
    setSent(false);
  }

  /** Arrow-key navigation across the two sign-in-mode tabs. */
  function handleTabsKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
      return;
    }
    event.preventDefault();
    const tabs = Array.from(
      tabsRef.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]') ?? [],
    );
    const index = tabs.indexOf(document.activeElement as HTMLButtonElement);
    const offset = event.key === 'ArrowRight' ? 1 : -1;
    const next = (index + offset + tabs.length) % tabs.length;
    tabs[next]?.focus();
    switchMode(next === 1 ? 'magic' : 'password');
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (busy) {
      return;
    }
    setBusy(true);
    setFormError('');
    const result = await submitWpLogin({
      username: username.trim(),
      password,
      remember,
    });
    setBusy(false);
    if (result.ok) {
      onSuccess();
    } else {
      setFormError(result.message);
    }
  }

  /** Shared by the magic-link form and its "Resend link" button. */
  async function requestLink(): Promise<void> {
    if (busy) {
      return;
    }
    setBusy(true);
    setFormError('');
    const result = await requestMagicLink(email.trim());
    setBusy(false);
    if (result.ok) {
      setSent(true);
    } else {
      setFormError(result.message);
    }
  }

  async function handleMagicSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    await requestLink();
  }

  return (
    <div className="nvoos-login">
      <main className="nvoos-login__card">
        <header className="nvoos-login__header">
          <span className="nvoos-login__mark" aria-hidden="true">
            <img src={nvoosIcon} alt="" />
          </span>
          <h1 className="nvoos-login__title">NV oOS Console</h1>
          <p className="nvoos-login__subtitle">Sign in with your site account</p>
        </header>

        {notice ? (
          <p className="nvoos-login__notice" role="status">
            {notice}
          </p>
        ) : null}

        {error ? (
          <p className="nvoos-login__error" role="alert">
            {error}
          </p>
        ) : null}

        <div
          className="nvoos-login__modes"
          role="tablist"
          aria-label="Sign-in method"
          ref={tabsRef}
          tabIndex={0}
          onKeyDown={handleTabsKeyDown}
        >
          <button
            id="nvoos-login-tab-password"
            type="button"
            role="tab"
            aria-selected={mode === 'password'}
            aria-controls="nvoos-login-panel-password"
            className="nvoos-login__mode-tab"
            onClick={() => switchMode('password')}
          >
            Password
          </button>
          <button
            id="nvoos-login-tab-magic"
            type="button"
            role="tab"
            aria-selected={mode === 'magic'}
            aria-controls="nvoos-login-panel-magic"
            className="nvoos-login__mode-tab"
            onClick={() => switchMode('magic')}
          >
            Magic link
          </button>
        </div>

        {mode === 'password' ? (
          <div
            id="nvoos-login-panel-password"
            role="tabpanel"
            aria-labelledby="nvoos-login-tab-password"
          >
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

              {formError ? (
                <p className="nvoos-login__error" role="alert">
                  {formError}
                </p>
              ) : null}

              <button
                type="submit"
                className="nvoos-login__submit"
                disabled={busy}
                aria-busy={busy}
              >
                {busy ? 'Signing in…' : 'Sign in'}
              </button>
            </form>
          </div>
        ) : (
          <div
            id="nvoos-login-panel-magic"
            role="tabpanel"
            aria-labelledby="nvoos-login-tab-magic"
          >
            {sent ? (
              <div className="nvoos-login__form">
                <p className="nvoos-login__sent" role="status">
                  If an account exists for <strong>{email.trim()}</strong>, we&apos;ve emailed a
                  sign-in link. The link expires in 15 minutes and can be used once.
                </p>
                <button
                  type="button"
                  className="nvoos-login__secondary"
                  disabled={busy}
                  onClick={() => void requestLink()}
                >
                  {busy ? 'Sending…' : 'Resend link'}
                </button>
                <button
                  type="button"
                  className="nvoos-login__secondary"
                  onClick={() => switchMode('password')}
                >
                  Use password instead
                </button>
              </div>
            ) : (
              <form className="nvoos-login__form" onSubmit={handleMagicSubmit}>
                <div className="nvoos-login__field">
                  <label className="nvoos-login__label" htmlFor="nvoos-login-email">
                    Email address
                  </label>
                  <input
                    id="nvoos-login-email"
                    ref={emailRef}
                    className="nvoos-login__input"
                    name="email"
                    type="email"
                    autoComplete="email"
                    inputMode="email"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    disabled={busy}
                    required
                  />
                </div>

                {formError ? (
                  <p className="nvoos-login__error" role="alert">
                    {formError}
                  </p>
                ) : null}

                <button
                  type="submit"
                  className="nvoos-login__submit"
                  disabled={busy}
                  aria-busy={busy}
                >
                  {busy ? 'Sending…' : 'Email me a sign-in link'}
                </button>

                <button
                  type="button"
                  className="nvoos-login__secondary"
                  onClick={() => switchMode('password')}
                >
                  Use password instead
                </button>
              </form>
            )}
          </div>
        )}
      </main>
    </div>
  );
}
