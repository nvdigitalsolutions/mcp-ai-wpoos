// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview
 * Shared bits for the database console panels.
 *
 * Formats timestamps and byte counts and renders the status pill every panel
 * uses, so the console has exactly one definition of each.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import type { JSX, ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import type { DatabaseProbe } from '../../../api/database';

/** A stored unix timestamp as a local string; `—` when absent. */
export function formatTimestamp( ts: number | undefined ): string {
	if ( ! ts || ts <= 0 ) {
		return '—';
	}

	try {
		return new Date( ts * 1000 ).toLocaleString();
	} catch {
		return '—';
	}
}

/** Bytes as a human string. */
export function formatBytes( bytes: number | undefined ): string {
	if ( ! bytes || bytes <= 0 ) {
		return '0 B';
	}

	const units = [ 'B', 'KB', 'MB', 'GB' ];
	let value = bytes;
	let unit = 0;

	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit += 1;
	}

	return `${ value.toFixed( unit === 0 ? 0 : 1 ) } ${ units[ unit ] }`;
}

/** Whether a probe says the role cannot write. */
export function probeIsReadSafe( probe: DatabaseProbe | null | undefined ): boolean {
	return Boolean( probe?.reachable ) && probe?.can_write !== true && probe?.bypasses_rls !== true;
}

/** A reachability pill, with the reason when it failed. */
export function ProbePill( { probe }: { probe?: DatabaseProbe | null } ): JSX.Element {
	if ( ! probe || probe.checked_at === undefined || probe.checked_at === 0 ) {
		return <span className="nvoos-db-pill nvoos-db-pill--muted">{ __( 'Not tested', 'nvoos-pro-spa' ) }</span>;
	}

	if ( probe.reachable ) {
		return <span className="nvoos-db-pill nvoos-db-pill--ok">{ __( 'Reachable', 'nvoos-pro-spa' ) }</span>;
	}

	return (
		<span className="nvoos-db-pill nvoos-db-pill--bad" title={ probe.error ?? undefined }>
			{ __( 'Unreachable', 'nvoos-pro-spa' ) }
		</span>
	);
}

/** One chip row, or nothing when the list is empty. */
export function Chips( {
	label,
	values,
	tone,
}: {
	label: string;
	values: string[] | undefined;
	tone?: 'bad' | 'warn' | 'ok';
} ): JSX.Element | null {
	if ( ! values || values.length === 0 ) {
		return null;
	}

	return (
		<div className="nvoos-db-chips">
			<span className="nvoos-db-chips__label">{ label }</span>
			{ values.map( ( value ) => (
				<span
					key={ value }
					className={ `nvoos-db-chip${ tone ? ` nvoos-db-chip--${ tone }` : '' }` }
				>
					{ value }
				</span>
			) ) }
		</div>
	);
}

/** The empty state every panel uses. */
export function EmptyState( {
	icon,
	title,
	children,
}: {
	icon: string;
	title: string;
	children?: ReactNode;
} ): JSX.Element {
	return (
		<div className="nvoos-db-empty">
			<span className="nvoos-db-empty__icon" aria-hidden="true">
				{ icon }
			</span>
			<h3>{ title }</h3>
			{ children }
		</div>
	);
}

/** A notice block. `tone` picks the accent. */
export function Notice( {
	tone = 'info',
	title,
	children,
}: {
	tone?: 'info' | 'bad' | 'warn';
	title: string;
	children?: ReactNode;
} ): JSX.Element {
	return (
		<div className={ `nvoos-db-notice nvoos-db-notice--${ tone }` } role={ tone === 'bad' ? 'alert' : 'status' }>
			<strong>{ title }</strong>
			{ children ? <div className="nvoos-db-notice__body">{ children }</div> : null }
		</div>
	);
}

/** Escape one CSV cell. */
function csvCell( value: unknown ): string {
	const text = value === null || value === undefined ? '' : String( value );

	return `"${ text.replace( /"/g, '""' ) }"`;
}

/**
 * Download rows as CSV, client-side.
 *
 * The audit trail is already in the browser when the button is enabled, so a
 * second server round trip would only add a route that exports what the list
 * just showed. Values are never in the trail, so the file carries templates and
 * counts, not data.
 */
export function downloadCsv( filename: string, header: string[], rows: Array< Array< unknown > > ): void {
	const body = [ header.map( csvCell ).join( ',' ), ...rows.map( ( row ) => row.map( csvCell ).join( ',' ) ) ].join(
		'\r\n'
	);

	const blob = new Blob( [ `\uFEFF${ body }` ], { type: 'text/csv;charset=utf-8' } );
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );

	anchor.href = url;
	anchor.download = filename;
	anchor.click();

	URL.revokeObjectURL( url );
}
