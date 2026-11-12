/**
 * Advisory JSONL event log.
 *
 * IMPORTANT: this log is advisory only. Velocity filesystem persistence is
 * unverified (Proposal 063 open item), so estate truth is always derived
 * from providers (ADR-04). Losing the log must never corrupt an estate.
 */

import { appendFile, mkdir, readFile } from 'node:fs/promises';
import { dirname } from 'node:path';

export async function appendEvent( file, event ) {
	await mkdir( dirname( file ), { recursive: true } );
	const line = JSON.stringify( { ...event, at: new Date().toISOString() } ) + '\n';
	await appendFile( file, line, 'utf8' );
}

export async function readEvents( file ) {
	try {
		const raw = await readFile( file, 'utf8' );
		return raw
			.split( '\n' )
			.filter( ( line ) => line.trim() !== '' )
			.map( ( line ) => {
				try {
					return JSON.parse( line );
				} catch {
					return { type: 'unparseable', raw: line };
				}
			} );
	} catch ( error ) {
		if ( error && error.code === 'ENOENT' ) {
			return [];
		}
		throw error;
	}
}
