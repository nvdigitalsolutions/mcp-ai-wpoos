// ---------------------------------------------------------------------------
// sites.ts — site-management tool slice (096 §3.3): the operator allowlist
// that every fetch-capable tool consults. Credentials never appear here —
// secrets live in the vault, profiles carry none by design.
// ---------------------------------------------------------------------------

import { unlink } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { z } from 'zod/v4';

import { analyzeSite, profileFromDiscovery } from '../../lib/discovery.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl } from '../../lib/ssrf.ts';
import type { SiteProfile } from '../../lib/types.ts';
import { WpClient } from '../../lib/wp-client.ts';
import type { ToolDef } from '../tool-def.ts';
import { DESTRUCTIVE_WRITE_TOOL, errorResult, IDEMPOTENT_WRITE_TOOL, OPEN_WORLD_WRITE_TOOL, READ_ONLY_TOOL, text } from '../util.ts';

const NAME = z.string().regex( /^[a-z0-9][a-z0-9-]{0,31}$/, 'lowercase letters, digits, dashes' );

/** Write the draft profile for a site into <home>/profiles/<name>.json. */
async function writeProfile( env: { homeDir: string }, name: string, profile: unknown ): Promise<string> {
	const { mkdir, writeFile } = await import( 'node:fs/promises' );
	const dir = join( env.homeDir, 'profiles' );
	await mkdir( dir, { recursive: true } );
	const path = join( dir, `${ name }.json` );
	await writeFile( path, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
	return path;
}

export const SITE_TOOLS: ToolDef[] = [
	{
		name: 'site_add',
		title: 'Register a WordPress site (analyzes and drafts its profile)',
		description:
			'Register a WordPress/WooCommerce URL in the forge allowlist: runs the polite discovery pass (site_analyze), drafts the site profile under the forge home, and adds the named entry. Idempotent — re-adding refreshes the profile. The URL must be a plain http(s) hostname; private/loopback/literal-IP ranges are refused.',
		schema: z.object( { name: NAME, url: z.string().url() } ),
		annotations: OPEN_WORLD_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { name, url } = args as { name: string; url: string };
			try {
				assertSafeHttpUrl( url );
				const report = await analyzeSite( new WpClient( { baseUrl: url.replace( /\/+$/, '' ) } ) );
				const profile: SiteProfile = profileFromDiscovery( report );
				profile.url = url.replace( /\/+$/, '' );
				const profilePath = await writeProfile( env, name, profile );
				const store = new SitesStore( env.homeDir );
				const isDefault = ( await store.defaultName() ) === undefined;
				await store.add( name, { url: profile.url, profile: profilePath }, { isDefault } );
				return text( `Registered site "${ name }" -> ${ profile.url }${ isDefault ? ' (default)' : '' }. Profile: ${ profilePath }`, {
					name,
					url: profile.url,
					profilePath,
					isDefault,
					collections: profile.collections.map( ( collection ) => collection.slug ),
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'site_test',
		title: 'Test a registered site (reachability + collection counts)',
		description:
			'Report a registered site\'s root document and per-collection entry counts versus their page caps (a build must never silently truncate). Registry sites only — no arbitrary URLs.',
		schema: z.object( { name: NAME } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { name } = args as { name: string };
			try {
				const store = new SitesStore( env.homeDir );
				const { ref, profile } = await store.resolve( name );
				const client = WpClient.fromProfile( profile );
				const root = await client.root();
				const collections = [];
				for ( const collection of profile.collections ) {
					const perPage = collection.perPage ?? 100;
					const maxPages = collection.maxPages ?? 50;
					const { headers } = await client.get( collection.restBase, { page: '1', per_page: '1' } );
					const total = Number( headers.get( 'x-wp-total' ) ?? '0' );
					const pages = Math.ceil( total / perPage );
					collections.push( { slug: collection.slug, total, pages, cap: maxPages, truncated: pages > maxPages } );
				}
				const truncated = collections.some( ( collection ) => collection.truncated );
				return text(
					truncated
						? `Site ${ name } reachable but some collections exceed their page caps — raise maxPages.`
						: `Site ${ name } (${ ref.url }) reachable — "${ root.name }". All collections fit their caps.`,
					{ name, url: ref.url, siteName: root.name, collections }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'site_list',
		title: 'List registered sites',
		description: 'List the registered sites (name, URL, profile path) and mark the default. No credentials — profiles and the registry never store secrets.',
		schema: z.object( {} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( _args, env ) => {
			const store = new SitesStore( env.homeDir );
			const listed = await store.list();
			const defaultName = await store.defaultName();
			const sites = Object.entries( listed ).map( ( [ name, ref ] ) => ( {
				name,
				url: ref.url,
				profile: ref.profile,
				created: ref.created,
				isDefault: name === defaultName,
			} ) );
			return text(
				sites.length === 0 ? 'No registered sites.' : `${ sites.length } registered site(s).`,
				{ sites, default: defaultName ?? null }
			);
		},
	},
	{
		name: 'site_get',
		title: 'Read one registered site (reference + profile)',
		description: 'Return one site\'s registry reference and its profile (collections, excludes, tokens). Credentials are never part of a profile.',
		schema: z.object( { name: NAME } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { name } = args as { name: string };
			try {
				const store = new SitesStore( env.homeDir );
				const { ref, profile } = await store.resolve( name );
				return text( `Site ${ name }: ${ ref.url } (profile ${ ref.profile })`, { name, ref, profile } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'site_remove',
		title: 'Remove a registered site',
		description: 'Remove a site from the registry and delete its forge-managed profile file (profiles outside the forge home are left in place). The default falls back to the first remaining site.',
		schema: z.object( { name: NAME } ),
		annotations: DESTRUCTIVE_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { name } = args as { name: string };
			try {
				const store = new SitesStore( env.homeDir );
				const { ref } = await store.resolve( name );
				await store.remove( name );
				// Only delete forge-managed profile files (inside the home dir).
				const profileAbs = resolve( ref.profile );
				const homeAbs = resolve( env.homeDir );
				if ( profileAbs.startsWith( homeAbs + '/' ) || profileAbs.startsWith( homeAbs + '\\' ) ) {
					await unlink( profileAbs ).catch( () => undefined );
				}
				return text( `Removed site "${ name }".`, { name, default: ( await store.defaultName() ) ?? null } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'site_set_default',
		title: 'Set the default site',
		description: 'Mark a registered site as the default (profile-resolution and scaffold targets fall back to it).',
		schema: z.object( { name: NAME } ),
		annotations: IDEMPOTENT_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { name } = args as { name: string };
			try {
				const store = new SitesStore( env.homeDir );
				await store.setDefault( name );
				return text( `Default site is now "${ name }".`, { name } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
