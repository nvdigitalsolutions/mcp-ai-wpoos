/**
 * Types for scripts/connected-apps.mjs (Proposal 090-p W6).
 *
 * The proxy policy lives in plain ESM so `scripts/serve.mjs` can stay
 * dependency-free; this declaration is what the Vitest suite typechecks
 * against.
 */

export interface ConnectedApp {
  key: string;
  base: string;
  token: string;
  cacheTtl: number;
}

export declare const CONNECTED_KEYS: readonly string[];
export declare const CONNECTED_TTL_CAP: number;

export declare function parseConnectedApps(raw: string | undefined): Map<string, ConnectedApp>;

export declare function normaliseConnectedPath(rest: string | undefined): string | null;

export declare function buildConnectedRequestHeaders(
  incoming: Record<string, string | string[] | undefined>,
  app: Pick<ConnectedApp, 'base' | 'token'>,
): Record<string, string | string[] | undefined>;

export declare function buildConnectedResponseHeaders(
  upstream: Record<string, string | string[] | undefined>,
  app: Pick<ConnectedApp, 'key' | 'cacheTtl'>,
): Record<string, string | string[] | undefined>;
