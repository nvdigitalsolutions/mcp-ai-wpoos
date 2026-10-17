/**
 * Build-time environment for the standalone SPA shell.
 *
 * vite.config.ts bakes these keys into the client bundle via `define`
 * (`import.meta.env.NVOOS_*`); they are read from the app's .env files with
 * shell values taking precedence (matching NVOOS_TARGET_SITE). The `HOME`
 * login-preset flag from .env arrives as NVOOS_HOME — see the README's
 * "Login presets (HOME)" section.
 */

interface ImportMetaEnv {
  /** Login preset from `HOME` in .env: '1'..'4' pin the login page; unset = full screen. */
  readonly NVOOS_HOME?: string;
  /** WordPress site URL — pre-fills preset pages 1–3, required for preset 4. */
  readonly NVOOS_SITE_URL?: string;
  /** Assistant credential — pre-fills preset 3, primary auth for preset 4. */
  readonly NVOOS_ASSISTANT_CREDENTIAL?: string;
  /** Application-password login for preset 2 (and preset 4 fallback). */
  readonly NVOOS_WP_USERNAME?: string;
  readonly NVOOS_WP_APP_PASSWORD?: string;
  /** Design Stack media worker, attached automatically on preset pages. */
  readonly NVOOS_WORKER_URL?: string;
  readonly NVOOS_WORKER_TOKEN?: string;
  /** '1' = connect HOME=4 automatically on load (kiosk deployments). */
  readonly NVOOS_AUTO_CONNECT?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
