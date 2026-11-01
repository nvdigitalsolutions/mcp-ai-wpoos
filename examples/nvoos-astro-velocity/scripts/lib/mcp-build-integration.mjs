// ---------------------------------------------------------------------------
// mcp-build-integration.mjs — rebuild the /mcp bundle when `astro build`
// finishes. Wired into astro.config.mjs integrations.
//
// Why an Astro integration instead of an npm postbuild script: Astro
// documents `astro:build:done` as the extension point for post-build output
// work ("copy content into the generated /assets directory"), and it fires
// whenever `astro build` completes — via npm run build, the sync workflow,
// or a direct CLI invocation. An npm `postbuild` lifecycle script only fires
// when the build is driven by `npm run build`, and any pre-build step in the
// npm chain is erased by astro's dist/ wipe.
// ---------------------------------------------------------------------------

import { buildMcpEntry } from './mcp-build.mjs';

export default function mcpBuildIntegration() {
	return {
		name: 'nvoos-astro-mcp-build',
		hooks: {
			'astro:build:done': async ({ logger }) => {
				try {
					await buildMcpEntry();
				} catch (error) {
					// Fail the build: a silently-missing /mcp on the mirror
					// is worse than a visible build failure.
					throw new Error(error instanceof Error ? error.message : String(error));
				}
				logger.info('wrote dist/mcp-entry.mjs');
			},
		},
	};
}
