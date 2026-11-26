// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// media-snapshot.ts — freeze a localized build's media beside the profile.
//
// The CMS-less counterpart to `forge materialize` (content): once the origin is
// gone, a cache-less rebuild loses a different set of CDN derivatives every
// time (virtuallyjamaica, 2026-11-15: the et-line font and several hero images
// 404'd while the site stayed green). `forge media-snapshot` reads the
// localizer's own report and copies every mirrored file into
// `sites/<name>.media/` with a URL-keyed manifest. The scaffold copies that
// directory into the project, and `media.snapshot: true` makes the localizer
// read it before the network.
//
// Deterministic: the report is the only input and file names are the
// localizer's deterministic `<hash>-<slug>.<ext>`.
// ---------------------------------------------------------------------------

import { copyFile, mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

interface LocalizedEntry {
	url?: string;
	file?: string;
	bytes?: number;
	contentType?: string;
	/** The SOURCE stylesheet text (relative to the project), for a sheet entry. */
	sourceFile?: string;
}

interface LocalizeReport {
	localized?: LocalizedEntry[];
}

export interface MediaSnapshotResult {
	outDir: string;
	/** Entries written to the manifest (one per source URL). */
	count: number;
	/** Files copied (unique by destination). */
	files: number;
	/** Total bytes copied. */
	bytes: number;
	/** Report entries whose file was missing from the build. */
	missing: string[];
	/**
	 * Advisory problems: the snapshot is written, but a cold rebuild may still
	 * suffer. Never fatal — the operator may be snapshotting a build they can
	 * no longer reproduce.
	 */
	warnings: string[];
}

/** A rewritten sheet points at the snapshot's own `/media/…` output. */
const SELF_REFERENCING_MEDIA = /url\(\s*['"]?\/?(?:\.\.?\/)*media\//i;

/** A WordPress-owned asset path (`/wp-content/…`, `/wp-includes/…`). */
const WP_ASSET_PATH = /^\/(?:wp-content|wp-includes)\//i;

/**
 * Find the built file for a report `file` (`/media/<hash>-<slug>.<ext>`).
 *
 * The localizer may output to `dist/client` (Astro's default) or `dist` (the
 * no-client variant), and the report does not say which. The file's presence
 * is the answer.
 */
function builtPath( projectDir: string, file: string ): string | undefined {
	const relativeFile = String( file ?? '' ).replace( /^[/\\]+/, '' );
	for ( const root of [ join( projectDir, 'dist', 'client' ), join( projectDir, 'dist' ) ] ) {
		const candidate = join( root, relativeFile );
		if ( existsSync( candidate ) ) {
			return candidate;
		}
	}
	return undefined;
}

/** Collect `.html` / `.css` files under a directory (missing dirs are fine). */
async function walkDocuments( dir: string, out: string[] ): Promise< void > {
	let entries;
	try {
		entries = await readdir( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		const full = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			await walkDocuments( full, out );
		} else if ( entry.isFile() && /\.(?:html|css)$/i.test( entry.name ) ) {
			out.push( full );
		}
	}
}

/** Every built HTML/CSS file (`dist/client` wins; `dist` for no-client builds). */
async function builtDocuments( projectDir: string ): Promise< string[] > {
	const files: string[] = [];
	const client = join( projectDir, 'dist', 'client' );
	if ( existsSync( client ) ) {
		await walkDocuments( client, files );
	}
	if ( files.length === 0 ) {
		await walkDocuments( join( projectDir, 'dist' ), files );
	}
	return files;
}

/**
 * Read a localization report and write the media snapshot.
 *
 * The snapshot keeps the localizer's `media/` layout so the manifest entries
 * are portable: `{ url: { file: 'media/<hash>-<slug>.<ext>', contentType } }`.
 * Re-running merges into the existing directory (a re-forge must not have to
 * re-download fonts the origin no longer serves); files from URLs absent from
 * the new report are left in place, and the manifest is replaced.
 */
export async function writeMediaSnapshot( options: {
	projectDir: string;
	outDir: string;
	now?: Date;
	/**
	 * Fail the run when the snapshot carries advisory warnings (CI).
	 *
	 * The snapshot is still written and coherent — the warnings describe what a
	 * cold rebuild will hit, not what this run corrupted — so a failed strict run
	 * leaves the operator something to inspect and a re-run merges over it.
	 */
	strict?: boolean;
} ): Promise< MediaSnapshotResult > {
	const projectDir = resolve( options.projectDir );
	const outDir = resolve( options.outDir );
	const reportPath = join( projectDir, '.forge-cache', 'media-localize.json' );
	const raw = await readFile( reportPath, 'utf8' ).catch( () => null );
	if ( raw === null ) {
		throw new Error(
			`No localization report at ${ reportPath } — build the project first (npm run build), then snapshot it.`
		);
	}
	let report: LocalizeReport;
	try {
		report = JSON.parse( raw ) as LocalizeReport;
	} catch ( error ) {
		throw new Error( `The localization report is not valid JSON: ${ error instanceof Error ? error.message : error }` );
	}
	const localized = Array.isArray( report.localized ) ? report.localized : [];
	if ( localized.length === 0 ) {
		throw new Error( 'The localization report has no localized entries — nothing to snapshot.' );
	}

	const entries: Record< string, { file: string; contentType?: string; bytes?: number } > = {};
	const copied = new Set< string >();
	const missing: string[] = [];
	const warnings: string[] = [];
	let bytes = 0;
	for ( const entry of localized ) {
		if ( typeof entry.url !== 'string' || typeof entry.file !== 'string' ) {
			continue;
		}
		const source = entry.sourceFile
			? join( projectDir, ...String( entry.sourceFile ).replace( /^[/\\]+/, '' ).split( /[/\\]+/ ) )
			: builtPath( projectDir, entry.file );
		if ( ! source || ! existsSync( source ) ) {
			missing.push( entry.url );
			continue;
		}
		const relativeFile = String( entry.file )
			.replace( /^[/\\]+/, '' )
			.split( /[/\\]+/ )
			.join( '/' );
		const destination = join( outDir, ...relativeFile.split( '/' ) );
		if ( ! copied.has( destination ) ) {
			await mkdir( dirname( destination ), { recursive: true } );
			await copyFile( source, destination );
			copied.add( destination );
			bytes += ( await stat( source ) ).size;
		}
		entries[ entry.url ] = {
			file: relativeFile,
			...( entry.contentType ? { contentType: entry.contentType } : {} ),
			...( typeof entry.bytes === 'number' ? { bytes: entry.bytes } : {} ),
		};

		// A sheet must be snapshotted as its SOURCE text: the localizer resolves
		// a sheet's relative font URLs against the sheet URL, so a REWRITTEN copy
		// sends the next cold build after `/media/…` URLs the snapshot never
		// named — every font 404s and the `@font-face` is replaced by
		// `unavailable.svg` (virtuallysrilanka, 2026-11-22).
		const isSheet =
			entry.contentType === 'text/css' || /\.css(?:$|\?)/i.test( entry.url );
		if ( isSheet ) {
			const text = await readFile( destination, 'utf8' ).catch( () => '' );
			if ( ! entry.sourceFile ) {
				warnings.push(
					`${ entry.url } has no sourceFile in the report — this build predates the field, so the snapshot may hold the REWRITTEN sheet. Re-freeze from a current build.`
				);
			} else if ( SELF_REFERENCING_MEDIA.test( text ) ) {
				warnings.push(
					`${ entry.url } still references /media/… after snapshotting — it is a rewritten sheet, and a cold rebuild will 404 its fonts. Re-freeze from a current build.`
				);
			}
		}
	}

	await mkdir( outDir, { recursive: true } );

	// Origin asset references the report never localized. The common cause is a
	// source image Astro's <Image> consumed at build time (no `localized` entry)
	// — a materialized rebuild renders it as a plain <img>, misses the snapshot
	// and leaves a dead hotlink. Canonical/og URLs are page URLs, not assets, so
	// only `/wp-content/` + `/wp-includes/` paths count.
	const origin =
		typeof ( report as { origin?: unknown } ).origin === 'string'
			? String( ( report as { origin?: unknown } ).origin ).replace( /\/+$/, '' )
			: '';
	if ( origin ) {
		const uncovered = new Set< string >();
		for ( const file of await builtDocuments( projectDir ) ) {
			const text = await readFile( file, 'utf8' ).catch( () => '' );
			for ( const match of text.matchAll( /https?:\/\/[^\s"'<>)\\]+/g ) ) {
				const url = match[ 0 ].replace( /[,;]+$/, '' );
				if ( ! url.startsWith( `${ origin }/` ) || entries[ url ] ) {
					continue;
				}
				let pathname = '';
				try {
					pathname = new URL( url ).pathname;
				} catch {
					continue;
				}
				if ( WP_ASSET_PATH.test( pathname ) ) {
					uncovered.add( url );
				}
			}
		}
		if ( uncovered.size > 0 ) {
			const sample = [ ...uncovered ].slice( 0, 3 ).join( ', ' );
			warnings.push(
				`${ uncovered.size } origin asset reference(s) are not in the snapshot — a cold rebuild will leave them hotlinked: ${ sample }${ uncovered.size > 3 ? ', …' : '' }. Re-scaffold and re-freeze (a materialized profile renders origin images as plain <img>).`
			);
		}
	}
	await writeFile(
		join( outDir, 'manifest.json' ),
		`${ JSON.stringify(
			{
				version: 1,
				generatedAt: ( options.now ?? new Date() ).toISOString(),
				entries,
			},
			null,
			'\t'
		) }\n`,
		'utf8'
	);

	if ( options.strict && warnings.length > 0 ) {
		throw new Error(
			`media-snapshot: ${ warnings.length } warning(s) make this snapshot unsafe for a CMS-less rebuild:\n- ${ warnings.join( '\n- ' ) }\nThe snapshot WAS written to ${ outDir }; fix the causes and re-run, or drop --strict.`
		);
	}

	return { outDir, count: Object.keys( entries ).length, files: copied.size, bytes, missing, warnings };
}
