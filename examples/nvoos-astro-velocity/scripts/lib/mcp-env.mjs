// ---------------------------------------------------------------------------
// mcp-env.mjs — the /mcp env snapshot seeding rule for the BFF boot
// (Proposal 095). The handler's manager-session gate and the tools' base
// resolution read NVOOS_SSR_API_ORIGIN / NVOOS_API_BASE from the snapshot
// serve.mjs takes BEFORE the SSR-origin rewrite. Boot paths that only set
// NVOOS_TARGET_SITE (CI smoke step, the Velocity activation notes) must
// still get a contract base — so derive it from the proxy target, the same
// way the /api proxy does. Explicit configuration always wins.
// ---------------------------------------------------------------------------

/**
 * Seed the MCP env snapshot with a contract base when none is configured.
 *
 * Always returns a fresh object: serve.mjs mutates process.env after boot
 * (the SSR-origin rewrite) and the handler must keep its pre-rewrite
 * snapshot (2026-10-30 manager-session 401 incident).
 *
 * @param {Record<string, string | undefined>} env the boot-time process env.
 * @returns {Record<string, string | undefined>} the snapshot to hand to
 *   createAstroMcpNodeHandler — with a derived NVOOS_SSR_API_ORIGIN when
 *   neither NVOOS_SSR_API_ORIGIN nor NVOOS_API_BASE is set but
 *   NVOOS_TARGET_SITE is.
 */
export function seedMcpEnv(env) {
	const targetSite = env.NVOOS_TARGET_SITE;
	if (env.NVOOS_SSR_API_ORIGIN || env.NVOOS_API_BASE || !targetSite) {
		return { ...env };
	}
	return {
		...env,
		NVOOS_SSR_API_ORIGIN: `${String(targetSite).replace(/\/+$/, '')}/wp-json/nvoos-commerce/v1`,
	};
}
