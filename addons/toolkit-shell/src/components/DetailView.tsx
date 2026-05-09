/**
 * Detail view — renders a single row of a Resource as a
 * read-only definition list, manifest-driven.
 *
 * @since 0.2.0
 */

import { __ } from '@wordpress/i18n';
import type { Resource } from '../api/types';

interface DetailViewProps {
	resource: Resource;
	row: Record<string, unknown> | null;
	loading?: boolean;
	error?: string | null;
	onClose?: () => void;
	onEdit?: () => void;
}

export function DetailView( { resource, row, loading, error, onClose, onEdit }: DetailViewProps ) {
	return (
		<section className="nvoos-toolkit-shell-detail">
			<header className="nvoos-toolkit-shell-detail-header">
				<h3>{ resource.label || resource.name }</h3>
				<div className="nvoos-toolkit-shell-detail-actions">
					{ onEdit && (
						<button type="button" onClick={ onEdit }>
							{ __( 'Edit', 'nvoos-toolkit-shell' ) }
						</button>
					) }
					{ onClose && (
						<button type="button" onClick={ onClose }>
							{ __( 'Close', 'nvoos-toolkit-shell' ) }
						</button>
					) }
				</div>
			</header>
			{ loading && <p>{ __( 'Loading…', 'nvoos-toolkit-shell' ) }</p> }
			{ error && <p className="nvoos-toolkit-shell-error">{ error }</p> }
			{ ! loading && ! error && ! row && <p>{ __( 'No record selected.', 'nvoos-toolkit-shell' ) }</p> }
			{ row && (
				<dl className="nvoos-toolkit-shell-detail-list">
					{ resource.fields.map( ( field ) => (
						<div key={ field.name } className="nvoos-toolkit-shell-detail-row">
							<dt>{ field.label || field.name }</dt>
							<dd>{ formatValue( row[ field.name ], field.type ) }</dd>
						</div>
					) ) }
				</dl>
			) }
		</section>
	);
}

function formatValue( value: unknown, type: string ): string {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( type === 'boolean' ) {
		return value ? 'Yes' : 'No';
	}
	if ( typeof value === 'object' ) {
		try {
			return JSON.stringify( value );
		} catch {
			return '[object]';
		}
	}
	return String( value );
}
