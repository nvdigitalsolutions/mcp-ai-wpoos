/**
 * MediaList — Table/list view for the Media Library tab.
 *
 * Dense detail view showing filename, MIME type, dimensions,
 * file size, and upload date for each media item.
 *
 * @since 2.0.3
 */

import type { JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import type { MediaItem } from '../../api/media';

export interface MediaListProps {
	items: MediaItem[];
	onSelect: ( item: MediaItem ) => void;
	selectedIds: Set< number >;
}

function formatFileSize( bytes: number ): string {
	if ( bytes === 0 ) return '\u2014';
	if ( bytes >= 1048576 ) return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	if ( bytes >= 1024 ) return `${ Math.round( bytes / 1024 ) } KB`;
	return `${ bytes } B`;
}

function formatDate( isoDate: string ): string {
	if ( ! isoDate ) return '\u2014';
	try {
		const d = new Date( isoDate );
		const months = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
		return `${ String( d.getDate() ).padStart( 2, '0' ) }-${ months[ d.getMonth() ] }-${ String( d.getFullYear() ).slice( -2 ) }`;
	} catch {
		return isoDate.slice( 0, 10 );
	}
}

function getMimeLabel( mimeType: string ): string {
	if ( mimeType.startsWith( 'image/' ) ) return mimeType.replace( 'image/', '' ).toUpperCase();
	if ( mimeType.startsWith( 'video/' ) ) return mimeType.replace( 'video/', '' ).toUpperCase();
	if ( mimeType === 'application/pdf' ) return 'PDF';
	return mimeType.split( '/' )[ 1 ]?.toUpperCase() ?? mimeType;
}

export function MediaList( props: MediaListProps ): JSX.Element {
	const { items, onSelect, selectedIds } = props;

	return (
		<div className="nvoos-pro-spa-media-list-wrap" role="table" aria-label={ __( 'Media list', 'nvoos-pro-spa' ) }>
			<div className="nvoos-pro-spa-media-list__header" role="row">
				<span className="nvoos-pro-spa-media-list__cell nvoos-pro-spa-media-list__cell--head" role="columnheader">
					{ __( 'File', 'nvoos-pro-spa' ) }
				</span>
				<span className="nvoos-pro-spa-media-list__cell nvoos-pro-spa-media-list__cell--head" role="columnheader">
					{ __( 'Type', 'nvoos-pro-spa' ) }
				</span>
				<span className="nvoos-pro-spa-media-list__cell nvoos-pro-spa-media-list__cell--head" role="columnheader">
					{ __( 'Size', 'nvoos-pro-spa' ) }
				</span>
				<span className="nvoos-pro-spa-media-list__cell nvoos-pro-spa-media-list__cell--head" role="columnheader">
					{ __( 'Date', 'nvoos-pro-spa' ) }
				</span>
			</div>
			{ items.map( ( item ) => {
				const isSelected = selectedIds.has( item.id );
				return (
					<div
						key={ item.id }
						className={ [
							'nvoos-pro-spa-media-list__row',
							isSelected ? 'nvoos-pro-spa-media-list__row--selected' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						role="row"
					>
						<button
							type="button"
							className="nvoos-pro-spa-media-list__cell nvoos-pro-spa-media-list__cell--name"
							role="cell"
							onClick={ () => onSelect( item ) }
							aria-label={ sprintf(
								/* translators: %1$s: media title, %2$s: MIME type */
								__( '%1$s (%2$s)', 'nvoos-pro-spa' ),
								item.title || sprintf( __( 'Item #%d', 'nvoos-pro-spa' ), item.id ),
								getMimeLabel( item.mimeType )
							) }
						>
							{ item.title || sprintf( __( '#%d', 'nvoos-pro-spa' ), item.id ) }
						</button>
						<span className="nvoos-pro-spa-media-list__cell" role="cell">
							{ getMimeLabel( item.mimeType ) }
						</span>
						<span className="nvoos-pro-spa-media-list__cell" role="cell">
							{ formatFileSize( item.fileSize ) }
						</span>
						<span className="nvoos-pro-spa-media-list__cell" role="cell">
							{ formatDate( item.date ) }
						</span>
					</div>
				);
			} ) }
		</div>
	);
}
