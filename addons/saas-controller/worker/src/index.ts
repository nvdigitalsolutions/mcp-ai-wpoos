/**
 * NV oOS Cloud Worker — entry point.
 *
 * Scaffolding placeholder. Subsequent PRs will move the production handler
 * from `addons/cloud-worker/src/` here (or symlink it during dev) and emit
 * a single ESM bundle to `worker/dist/index.js` via esbuild.
 */

export interface Env {
	D1: D1Database;
	KV: KVNamespace;
}

export default {
	async fetch( request: Request, _env: Env, _ctx: ExecutionContext ): Promise< Response > {
		const url = new URL( request.url );
		if ( url.pathname === '/healthz' ) {
			return new Response( JSON.stringify( { ok: true } ), {
				headers: { 'content-type': 'application/json' },
			} );
		}
		return new Response( 'NV oOS Cloud Worker — scaffolding', { status: 200 } );
	},
};
