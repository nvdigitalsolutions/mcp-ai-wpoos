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

import { copyFile, mkdir, readFile, stat, writeFile } from 'node:fs/promises';
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
}

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
	}

	await mkdir( outDir, { recursive: true } );
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

	return { outDir, count: Object.keys( entries ).length, files: copied.size, bytes, missing };
}
