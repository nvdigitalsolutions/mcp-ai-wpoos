// ---------------------------------------------------------------------------
// managed.generated.ts — rewritten by the forge scaffold (Proposal 135).
//
// true when the site runs the managed runtime (BFF proxy routes + Astro route
// caching + POST /api/revalidate). False for the static tier, where there is
// no cache provider and nothing to invalidate.
//
// The on-demand product route reads this before calling Astro.cache.set, so an
// ssr-without-managed build never touches the cache API.
// ---------------------------------------------------------------------------
export const MANAGED = false;
