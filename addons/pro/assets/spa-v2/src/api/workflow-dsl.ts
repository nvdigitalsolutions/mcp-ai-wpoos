/**
 * Pro SPA v2 — Workflow DSL client (Proposal 091, Track A).
 *
 * Wraps the DSL export/import surface on the base workflow REST
 * (mcp-ai/v1/orchestration/workflows): per-workflow export, preset export
 * (no save-first round trip) and dry-run-first import with findings.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { __ } from '@wordpress/i18n';

export interface DslFinding {
	path: string;
	message: string;
	severity: 'error' | 'warning';
}

export interface DslImportResult {
	dry_run?: boolean;
	findings?: DslFinding[];
	preview?: unknown;
	id?: number;
}

export interface WorkflowDslClientOptions {
	endpoint: string;
	nonce: string;
}

export class WorkflowDslClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: WorkflowDslClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >( path: string, body: unknown ): Promise< T > {
		const response = await fetch( `${ this.endpoint }${ path }`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify( body ),
		} );

		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as {
					message?: string;
					data?: { findings?: DslFinding[] };
				};
				detail = err?.message ?? '';
				if ( err?.data?.findings?.length ) {
					throw new DslFindingsError(
						detail || __( 'Workflow DSL validation failed.', 'nvoos-pro-spa' ),
						err.data.findings
					);
				}
			} catch ( parseErr ) {
				if ( parseErr instanceof DslFindingsError ) {
					throw parseErr;
				}
			}
			throw new Error( detail || __( 'Workflow DSL request failed.', 'nvoos-pro-spa' ) );
		}

		return ( await response.json() ) as T;
	}

	async exportWorkflow( id: number ): Promise< unknown > {
		return this.request< unknown >( `/${ id }/export`, {} );
	}

	async exportPreset( presetSlug: string ): Promise< unknown > {
		return this.request< unknown >( '/export', { source: 'preset', preset_slug: presetSlug } );
	}

	async importDsl(
		dsl: unknown,
		opts: { dryRun: boolean; source: 'native' | 'dify' }
	): Promise< DslImportResult > {
		return this.request< DslImportResult >( '/import', {
			workflow_json: dsl,
			dry_run: opts.dryRun,
			source: opts.source,
		} );
	}
}

export class DslFindingsError extends Error {
	public readonly findings: DslFinding[];

	constructor( message: string, findings: DslFinding[] ) {
		super( message );
		this.name = 'DslFindingsError';
		this.findings = findings;
	}
}

export function hasBlockers( findings: DslFinding[] ): boolean {
	return findings.some( ( finding ) => finding.severity === 'error' );
}

export function downloadDsl( dsl: unknown, filename: string ): void {
	const blob = new Blob( [ JSON.stringify( dsl, null, 2 ) ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = filename;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	URL.revokeObjectURL( url );
}
