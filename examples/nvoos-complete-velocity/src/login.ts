/**
 * Cookie-auth WordPress sign-in and sign-out for the NV oOS Complete
 * Console.
 *
 * The browser never leaves the app and never renders a WordPress page: this
 * module replays WordPress' own login form — seed the `wordpress_test_cookie`
 * with a GET, then POST the credentials form-encoded — against the
 * same-origin proxy (scripts/serve.mjs → /wp-login.php). The proxy strips the
 * Domain attribute from upstream Set-Cookie headers, so the WordPress auth
 * cookies land on the app origin and the SPA's REST calls authenticate
 * immediately.
 *
 * The outcome is read from the response itself: WordPress answers a
 * successful sign-on with a redirect (to `redirect_to`, which serve.mjs
 * re-scopes to the app origin) and a failed one with a 200 re-render of the
 * login form containing `#login_error`. Both are intercepted with
 * `redirect: 'manual'`, so no WordPress HTML is ever displayed. The caller
 * re-runs the session bootstrap afterwards — the authoritative check.
 */

export interface WpLoginInput {
  username: string;
  password: string;
  /** Persist the session across browser restarts (WP `rememberme=forever`). */
  remember?: boolean;
  signal?: AbortSignal;
}

export interface WpLoginResult {
  ok: boolean;
  /** User-facing message. Kept generic on purpose (see OWASP A07:2025). */
  message: string;
}

const LOGIN_PATH = '/wp-login.php';
const UNAVAILABLE_MESSAGE = 'Sign-in is temporarily unavailable. Please try again.';
const INVALID_MESSAGE = 'Invalid username or password.';

/**
 * Extract the human-readable text from WordPress' `#login_error` div and
 * unescape the entities esc_html() produced. Returns '' when the page holds
 * no error (e.g. a 2FA challenge step from a security plugin).
 */
export function extractWpLoginError(html: string): string {
  const match = html.match(/<div id="login_error">([\s\S]*?)<\/div>/i);
  if (!match?.[1]) return '';
  return match[1]
    .replace(/<[^>]*>/g, ' ')
    .replace(/&#0?39;|&apos;/gi, "'")
    .replace(/&quot;/gi, '"')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/\s+/g, ' ')
    .replace(/\s+([.,;:!?])/g, '$1')
    .trim();
}

/**
 * Sign in through WordPress cookie auth without showing wp-login.php.
 *
 * Success is a redirect response (the auth cookies are set on it); any 200
 * means the sign-on did not complete. The WordPress-specific reason is
 * logged to the console for debugging but deliberately NOT shown — uniform
 * messages prevent account enumeration (OWASP A07:2025).
 */
export async function submitWpLogin(input: WpLoginInput): Promise<WpLoginResult> {
  const { username, password, remember = false, signal } = input;

  // 1. Seed the test cookie exactly like a browser visiting the login page
  //    (wp-login.php rejects a testcookie POST without it). redirect:manual
  //    keeps already-signed-in visitors from downloading admin HTML.
  try {
    await fetch(LOGIN_PATH, { credentials: 'include', redirect: 'manual', signal });
  } catch (error) {
    if (error instanceof Error && error.name === 'AbortError') {
      throw error;
    }
    return { ok: false, message: UNAVAILABLE_MESSAGE };
  }

  // 2. Replay the native login form POST — the exact field names WordPress
  //    expects, so security plugins see a byte-familiar request.
  const body = new URLSearchParams({
    log: username,
    pwd: password,
    'wp-submit': 'Log In',
    redirect_to: '/',
    testcookie: '1',
  });
  if (remember) {
    body.set('rememberme', 'forever');
  }

  let response: Response;
  try {
    response = await fetch(LOGIN_PATH, {
      method: 'POST',
      credentials: 'include',
      redirect: 'manual',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
      signal,
    });
  } catch (error) {
    if (error instanceof Error && error.name === 'AbortError') {
      throw error;
    }
    return { ok: false, message: UNAVAILABLE_MESSAGE };
  }

  // 3. A successful sign-on redirects (fetch reports it as an opaque
  //    redirect or a raw 302/303 depending on the browser).
  if (response.type === 'opaqueredirect' || response.status === 302 || response.status === 303) {
    return { ok: true, message: '' };
  }

  // Proxy/backend failures carry their own status.
  if (!response.ok) {
    return { ok: false, message: UNAVAILABLE_MESSAGE };
  }

  // 4. Failed sign-on (or an intermediate challenge page from a 2FA plugin):
  //    uniform user-facing message; the specific reason stays in the console.
  const html = await response.text();
  const detail = extractWpLoginError(html);
  if (detail) {
    console.warn('[nvoos-console] wp-login rejected the sign-in:', detail);
  }
  return { ok: false, message: INVALID_MESSAGE };
}

const LOGOUT_CONFIRM_PATH = '/wp-login.php?action=logout';

/**
 * Pull the `log-out` nonce out of a rendered WordPress page. The wp-login
 * logout-confirmation page embeds it in the confirmation link; the wp-admin
 * page embeds it in the admin-bar logout link. The ampersand may be
 * HTML-escaped as `&amp;` or as numeric `&#038;` (esc_attr on hardened
 * installs) — accept both, plus a raw `&`.
 */
function extractLogoutNonce(html: string): string {
  const match = html.match(/action=logout(?:&amp;|&#0*38;|&)_wpnonce=([a-f0-9]{10})/i);
  return match?.[1] ?? '';
}

/**
 * End the WordPress session without ever showing a WordPress page.
 *
 * Scrape the log-out nonce from the wp-login confirmation page (fallback:
 * the wp-admin page, same nonce in the admin bar), then request the nonced
 * logout URL with `redirect: 'manual'` — WordPress clears the auth cookies
 * on that response and we never follow its redirect.
 */
export async function logoutFromWordPress(): Promise<{ ok: boolean; message: string }> {
  const sources = [LOGOUT_CONFIRM_PATH, '/wp-admin/'];
  let nonce = '';
  for (const source of sources) {
    try {
      const response = await fetch(source, { credentials: 'include', redirect: 'manual' });
      if (response.ok) {
        nonce = extractLogoutNonce(await response.text());
        if (nonce) {
          break;
        }
      }
    } catch {
      // Try the next nonce source.
    }
  }

  if (!nonce) {
    return { ok: false, message: 'No log-out nonce found — the session may already be over.' };
  }

  try {
    await fetch(`${LOGOUT_CONFIRM_PATH}&_wpnonce=${nonce}`, {
      credentials: 'include',
      redirect: 'manual',
    });
  } catch {
    return { ok: false, message: 'The log-out request failed.' };
  }
  return { ok: true, message: '' };
}
