// ---------------------------------------------------------------------------
// sites.ts — the multi-site profile registry (Proposal 096 Phase 3.4).
//
// `forge.mjs` works on one operator-written profile file; the registry turns
// that into a multi-site agency loop. `sites.json` in the forge home stores
// named references (URL + profile path + default flag) — NO credentials.
// Secrets stay in the vault; profile files stay operator-owned paths.
//
//   forge.mjs site add <name> <profile.json> [--default]
//   forge.mjs site remove <name>
//   forge.mjs site list
//   forge.mjs site use <name>
//   forge.mjs forge <name> --target <dir>   (resolve by name)
// ---------------------------------------------------------------------------

import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, resolve } from 'node:path';

import type { SiteProfile } from './types.ts';
import { forgeHome, sitesFile } from './vault.ts';

/** A named reference to one operator-owned site profile. */
export interface SiteRef {
	/** Root URL (https://…). */
	url: string;
	/** Profile JSON path — absolute, or relative to the registry file. */
	profile: string;
	/** ISO creation timestamp. */
	created: string;
}

/** The registry file shape. */
interface SitesFile {
	version: number;
	default?: string;
	sites: Record<string, SiteRef>;
}

/** Error type for registry failures. */
export class SitesError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'SitesError';
	}
}

const FILE_VERSION = 1;

/**
 * The multi-site registry. All mutations rewrite the single JSON file
 * atomically; reads cache per instance.
 */
export class SitesStore {
	private cache: SitesFile | undefined;
	private readonly dir: string;

	constructor( dir: string = forgeHome() ) {
		this.dir = dir;
	}

	/** The registry file path. */
	get file(): string {
		return sitesFile( this.dir );
	}

	/** Add (or update) a named site. First site added becomes the default. */
	async add( name: string, ref: { url: string; profile: string }, options: { isDefault?: boolean } = {} ): Promise<void> {
		this.assertName( name );
		if ( ! /^https?:\/\/.+/.test( ref.url ) ) {
			throw new SitesError( `Invalid site URL: ${ ref.url }` );
		}
		if ( ! ref.profile ) {
			throw new SitesError( 'A profile path is required.' );
		}
		const registry = await this.read();
		registry.sites[ name ] = {
			url: ref.url.replace( /\/+$/, '' ),
			profile: ref.profile,
			created: registry.sites[ name ]?.created ?? new Date().toISOString(),
		};
		if ( options.isDefault || registry.default === undefined ) {
			registry.default = name;
		}
		await this.write( registry );
	}

	/** Remove a named site; clears the default when it pointed at the site. */
	async remove( name: string ): Promise<void> {
		const registry = await this.read();
		if ( registry.sites[ name ] === undefined ) {
			throw new SitesError( `Unknown site: ${ name }` );
		}
		delete registry.sites[ name ];
		if ( registry.default === name ) {
			registry.default = Object.keys( registry.sites ).sort()[ 0 ];
		}
		await this.write( registry );
	}

	/** Set the default site. */
	async setDefault( name: string ): Promise<void> {
		const registry = await this.read();
		if ( registry.sites[ name ] === undefined ) {
			throw new SitesError( `Unknown site: ${ name }` );
		}
		registry.default = name;
		await this.write( registry );
	}

	/** All site refs (name → ref), never credentials. */
	async list(): Promise<Record<string, SiteRef>> {
		const registry = await this.read();
		return { ...registry.sites };
	}

	/** The default site name, if any. */
	async defaultName(): Promise<string | undefined> {
		const registry = await this.read();
		return registry.default;
	}

	/**
	 * Resolve a name to its reference and loaded profile. The profile path
	 * is resolved relative to the registry file (absolute paths pass through).
	 * The parsed shape is the SiteProfile contract — the loader validates the
	 * essentials at consume time (scaffold/WpClient assert url + collections).
	 */
	async resolve( name: string ): Promise<{ ref: SiteRef; profile: SiteProfile }> {
		const registry = await this.read();
		const ref = registry.sites[ name ];
		if ( ref === undefined ) {
			throw new SitesError( `Unknown site: ${ name }` );
		}
		const path = isAbsolute( ref.profile ) ? ref.profile : resolve( dirname( this.file ), ref.profile );
		let raw: string;
		try {
			raw = await readFile( path, 'utf8' );
		} catch {
			throw new SitesError( `Profile not found for site ${ name }: ${ path }` );
		}
		let profile: SiteProfile;
		try {
			profile = JSON.parse( raw ) as SiteProfile;
		} catch {
			throw new SitesError( `Profile for site ${ name } is not valid JSON: ${ path }` );
		}
		return { ref, profile };
	}

	private assertName( name: string ): void {
		if ( ! /^[a-z0-9][a-z0-9-]{0,31}$/.test( name ) ) {
			throw new SitesError( `Invalid site name: ${ name } (lowercase letters, digits, dashes)` );
		}
	}

	private async read(): Promise<SitesFile> {
		if ( this.cache ) {
			return this.cache;
		}
		let raw: string;
		try {
			raw = await readFile( this.file, 'utf8' );
		} catch {
			this.cache = { version: FILE_VERSION, sites: {} };
			return this.cache;
		}
		let parsed: SitesFile;
		try {
			parsed = JSON.parse( raw ) as SitesFile;
		} catch {
			throw new SitesError( `Sites registry ${ this.file } is not valid JSON.` );
		}
		if ( parsed.version !== FILE_VERSION || typeof parsed.sites !== 'object' || parsed.sites === null ) {
			throw new SitesError( `Sites registry ${ this.file } has an unsupported format.` );
		}
		this.cache = parsed;
		return parsed;
	}

	private async write( registry: SitesFile ): Promise<void> {
		await mkdir( this.dir, { recursive: true } );
		const tmp = this.file + '.tmp';
		await writeFile( tmp, JSON.stringify( registry, null, '\t' ) + '\n', 'utf8' );
		await rename( tmp, this.file );
		this.cache = registry;
	}
}
