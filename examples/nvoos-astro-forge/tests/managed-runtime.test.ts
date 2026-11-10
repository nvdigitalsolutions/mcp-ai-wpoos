// ---------------------------------------------------------------------------
// managed-runtime.test.ts — Proposal 135 Phase 2: the emitted managed surface.
//
// These are contract assertions on generated TEXT (the files ship into a
// customer repo and are never regenerated at runtime), so they pin the
// security-relevant shape: target namespaces, the rest-param guard, the
// fail-closed revalidate token, and the Cookie Domain strip.
//
// Route-based proxy (not src/fetch.ts) is deliberate: a custom fetch handler
// makes Astro skip its sessions/actions/middleware/cache handlers.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	renderApiProxyTs,
	renderFormsProxyTs,
	renderManagedGenerated,
	renderMediaProxyTs,
	renderPreviewApiTs,
	renderPreviewPageTs,
	renderProxyHelperTs,
	renderRevalidateTs,
	renderServerShim,
	renderSyncDeployTree,
	renderVerifyDeployTree,
} from '../lib/managed-runtime.ts';

describe( 'managed runtime — proxy helper', () => {
	const src = renderProxyHelperTs();

	it( 'fails closed without a configured target', () => {
		expect( src ).toContain( 'process.env.NVOOS_TARGET_SITE' );
		expect( src ).toContain( 'NVOOS_TARGET_SITE is not configured.' );
		expect( src ).toContain( '{ status: 503 }' );
	} );

	it( 'guards rest params against traversal and absolute paths', () => {
		expect( src ).toContain( 'sanitizeRestPath' );
		expect( src ).toContain( "path.includes('..')" );
		expect( src ).toContain( 'return null;' );
	} );

	it( 'strips Domain from Set-Cookie so cookies stay host-only', () => {
		expect( src ).toContain( 'hostOnlyCookie' );
		expect( src ).toMatch( /Domain=\[\^;\]\+/ );
		expect( src ).toContain( "responseHeaders.delete('set-cookie')" );
	} );

	it( 'sends a same-origin request server-to-server', () => {
		expect( src ).toContain( 'process.env.ASTRO_SITE' );
		expect( src ).toContain( "headers.delete('host')" );
		expect( src ).toContain( "headers.delete('origin')" );
		expect( src ).toContain( "headers.set('origin', PUBLIC_ORIGIN)" );
	} );

	it( 'never embeds a credential', () => {
		expect( src ).not.toMatch( /(?:secret|password|api[_-]?key)\s*=/i );
		expect( src ).not.toMatch( /https?:\/\// );
	} );
} );

describe( 'managed runtime — proxy routes', () => {
	it( 'maps /api/* onto the commerce contract', () => {
		const src = renderApiProxyTs();
		expect( src ).toContain( '`/wp-json/nvoos-commerce/v1/${rest}`' );
		expect( src ).toContain( "import { proxyToWordPress, sanitizeRestPath } from '../../lib/managed-proxy';" );
		expect( src ).toContain( 'export const ALL: APIRoute' );
		expect( src ).toContain( 'export const prerender = false;' );
	} );

	it( 'maps /api/forms/submit onto the forge bridge', () => {
		const src = renderFormsProxyTs();
		expect( src ).toContain( "'/wp-json/nvoos-forge/v1/forms/submit'" );
		expect( src ).toContain( '../../../lib/managed-proxy' );
	} );

	it( 'maps /media/* onto the uploads directory', () => {
		const src = renderMediaProxyTs();
		expect( src ).toContain( '`/wp-content/uploads/${rest}`' );
		expect( src ).toContain( '../../lib/managed-proxy' );
	} );
} );

describe( 'managed runtime — revalidate endpoint', () => {
	const src = renderRevalidateTs();

	it( 'is disabled when no token is configured', () => {
		expect( src ).toContain( 'if (!TOKEN)' );
		expect( src ).toContain( 'status: 503' );
	} );

	it( 'rejects a wrong bearer token', () => {
		expect( src ).toContain( '`Bearer ${TOKEN}`' );
		expect( src ).toContain( 'status: 401' );
	} );

	it( 'invalidates by tag or path and validates the body', () => {
		expect( src ).toContain( 'cache.invalidate({ tags })' );
		expect( src ).toContain( 'cache.invalidate({ path })' );
		expect( src ).toContain( 'status: 400' );
	} );

	it( 'is server-rendered, never prerendered', () => {
		expect( src ).toContain( 'export const prerender = false;' );
	} );
} );

describe( 'managed runtime — preview (Proposal 135 Phase 5)', () => {
	describe( 'token exchange /api/preview', () => {
		const src = renderPreviewApiTs();

		it( 'stores the bridge token in a HOST-ONLY cookie', () => {
			expect( src ).toContain( "const COOKIE = 'nvoos_preview';" );
			expect( src ).toContain( 'httpOnly: true' );
			expect( src ).toContain( "sameSite: 'lax'" );
			// No Domain attribute anywhere: a domain cookie would leak to siblings.
			expect( src ).not.toContain( 'domain:' );
		} );

		it( 'accepts only a plain slug and redirects to the preview page', () => {
			expect( src ).toContain( 'safeSlug' );
			expect( src ).toMatch( /\^\[a-z0-9\]\+\(\?:\-\[a-z0-9\]\+\)\*\$/ );
			expect( src ).toContain( 'redirect(`/preview/${slug}/`, 302)' );
		} );

		it( 'is never cached', () => {
			expect( src ).toContain( "response.headers.set('cache-control', 'no-store')" );
		} );

		it( 'never verifies or mints a token (no key material on the frontend)', () => {
			expect( src ).not.toMatch( /(?:secret|hmac|hash_hmac|salt)\s*[=:(]/i );
		} );
	} );

	describe( 'draft-aware page /preview/<slug>/', () => {
		const src = renderPreviewPageTs();

		it( 'opens with a frontmatter fence (an .astro file needs one)', () => {
			expect( src.startsWith( '---\n' ) ).toBe( true );
			expect( src.split( '\n---\n' ) ).toHaveLength( 2 );
		} );

		it( 'is server-rendered and reads the host-only cookie', () => {
			expect( src ).toContain( 'export const prerender = false;' );
			expect( src ).toContain( 'Astro.cookies.get(COOKIE)' );
		} );

		it( 'asks the bridge for exactly the token\'s document', () => {
			expect( src ).toContain( 'preview_token: token' );
			expect( src ).toContain( "'where[slug][equals]': slug" );
			expect( src ).toContain( "entry.source === 'local'" );
		} );

		it( 'is never indexed and never cached', () => {
			expect( src ).toContain( "NO_STORE = 'private, no-store'" );
			expect( src ).toContain( "NO_INDEX = 'noindex, nofollow'" );
			expect( src ).toContain( 'x-robots-tag' );
		} );

		it( 'sanitizes before injecting the body', () => {
			expect( src ).toContain( 'sanitizeHtml(raw.html)' );
		} );
	} );
} );

describe( 'managed runtime — entry + deploy tree', () => {
	it( 'emits the managed flag the SSR routes guard on', () => {
		expect( renderManagedGenerated( true ) ).toContain( 'export const MANAGED = true;' );
		expect( renderManagedGenerated( false ) ).toContain( 'export const MANAGED = false;' );
	} );

	it( 'entry boots the Astro node adapter output', () => {
		expect( renderServerShim() ).toContain( "import './dist/server/entry.mjs';" );
	} );

	it( 'deploy-tree assembly requires dist/ and server.js and writes a start script', () => {
		const src = renderSyncDeployTree();
		expect( src ).toContain( "join(root, 'dist')" );
		expect( src ).toContain( "join(root, 'server.js')" );
		expect( src ).toContain( "start: 'node server.js'" );
		expect( src ).toContain( 'dist/ is missing' );
		expect( src ).toContain( 'server.js is missing' );
	} );
} );

describe( 'managed runtime — deploy tree package.json', () => {
	it( 'emits a shell-safe no-op build script', () => {
		const src = renderSyncDeployTree();
		// npm runs scripts through `sh -c`; an unquoted `(` is a syntax
		// error, and Velocity reports the whole deploy as failed.
		expect( src ).toContain( ' "process.exit(0)"' );
		expect( src ).not.toContain( 'node -e process.exit(0)' );
		} );

	it( 'carries the project dependencies and its lockfile', () => {
		const src = renderSyncDeployTree();
		// The Astro node adapter externalizes its runtime (cookie, devalue,
		// send, unstorage, @oslojs/encoding…). A manifest with no deps makes
		// Velocity install nothing and `node server.js` dies with
		// ERR_MODULE_NOT_FOUND — the release never promotes and the app keeps
		// serving an older build (2026-11-10).
		expect( src ).toContain( 'projectPkg.dependencies' );
		expect( src ).toContain( '\t\t\tdependencies,' );
		expect( src ).toContain( 'package-lock.json' );
		expect( src ).toContain( "join(root, 'package.json')" );
	} );

	it( 'refuses to assemble a tree whose project declares no dependencies', () => {
		const src = renderSyncDeployTree();
		expect( src ).toContain( 'package.json declares no dependencies' );
		expect( src ).toContain( 'process.exit(1);' );
	} );

	it( 'fails closed when the build imports a package outside the declared closure', () => {
		const src = renderSyncDeployTree();
		expect( src ).toContain( 'reachable' );
		expect( src ).toContain( 'the build imports packages the manifest does not declare' );
	} );
} );

describe( 'managed runtime — deploy-tree boot gate', () => {
	const src = renderVerifyDeployTree();

	it( 'installs from the emitted manifest and starts the entry', () => {
		expect( src ).toContain( "join(root, '.deploy')" );
		expect( src ).toContain( "'install', '--omit=dev'" );
		expect( src ).toContain( "spawn(process.execPath, ['server.js']" );
	} );

	it( 'fails closed without the assembled tree', () => {
		expect( src ).toContain( 'run scripts/sync-deploy-tree.mjs first' );
	} );

	it( 'treats any HTTP answer as booted (the CMS may be unreachable from the runner)', () => {
		expect( src ).toContain( 'res.statusCode' );
		expect( src ).toContain( 'no HTTP response on port' );
	} );

	it( 'never fails a passing gate on Windows temp cleanup', () => {
		expect( src ).toContain( 'maxRetries' );
		expect( src ).toContain( '// best effort — the OS reclaims it' );
	} );
} );
