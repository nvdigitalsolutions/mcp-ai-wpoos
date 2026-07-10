/**
 * MediaGrid — Thumbnail grid view for the Media Library tab.
 *
 * Renders a responsive CSS grid of media thumbnails. Each item
 * shows a preview image/icon, filename, and MIME type badge.
 *
 * @since 2.0.3
 */

import type { JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import type { MediaItem } from '../../api/media';

export interface MediaGridProps {
	items: MediaItem[];
	onSelect: ( item: MediaItem ) => void;
	selectedIds: Set< number >;
}

function formatFileSize( bytes: number ): string {
	if ( bytes === 0 ) return '';
	if ( bytes >= 1048576 ) return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	if ( bytes >= 1024 ) return `${ Math.round( bytes / 1024 ) } KB`;
	return `${ bytes } B`;
}

function getMimeLabel( mimeType: string ): string {
	if ( mimeType.startsWith( 'image/' ) ) return __( 'Image', 'nvoos-pro-spa' );
	if ( mimeType.startsWith( 'video/' ) ) return __( 'Video', 'nvoos-pro-spa' );
	if ( mimeType.startsWith( 'audio/' ) ) return __( 'Audio', 'nvoos-pro-spa' );
	if ( mimeType === 'application/pdf' ) return 'PDF';
	return mimeType.split( '/' )[ 1 ]?.toUpperCase() ?? mimeType;
}

function getMimeIcon( mimeType: string ): string {
	if ( mimeType.startsWith( 'image/' ) ) return '\u{1F5BC}';
	if ( mimeType.startsWith( 'video/' ) ) return '\u{1F3AC}';
	if ( mimeType.startsWith( 'audio/' ) ) return '\u{1F3B5}';
	if ( mimeType === 'application/pdf' ) return '\u{1F4C4}';
	return '\u{1F4CE}';
}

export function MediaGrid( props: MediaGridProps ): JSX.Element {
	const { items, onSelect, selectedIds } = props;

	return (
		<ul className="nvoos-pro-spa-media-grid" aria-label={ __( 'Media grid', 'nvoos-pro-spa' ) }>
			{ items.map( ( item ) => {
				const isSelected = selectedIds.has( item.id );
				const isImage = item.mimeType.startsWith( 'image/' );

				return (
					<li
						key={ item.id }
						className={ [
							'nvoos-pro-spa-media-grid__item',
							isSelected ? 'nvoos-pro-spa-media-grid__item--selected' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
					>
						<button
							type="button"
							className="nvoos-pro-spa-media-grid__card"
							onClick={ () => onSelect( item ) }
							aria-label={ sprintf(
								/* translators: %1$s: media title, %2$s: MIME type */
								__( 'Select %1$s (%2$s)', 'nvoos-pro-spa' ),
								item.title || sprintf( __( 'Item #%d', 'nvoos-pro-spa' ), item.id ),
								getMimeLabel( item.mimeType )
							) }
							aria-pressed={ isSelected }
						>
							<div className="nvoos-pro-spa-media-grid__thumb">
								{ isImage ? (
									<img
										src={ item.thumbnailUrl || item.sourceUrl }
										alt={ item.altTextPlain || item.title }
										loading="lazy"
										className="nvoos-pro-spa-media-grid__img"
									/>
								) : (
									<span
										className="nvoos-pro-spa-media-grid__icon"
										aria-hidden="true"
									>
										{ getMimeIcon( item.mimeType ) }
									</span>
								) }
								{ isSelected && (
									<span className="nvoos-pro-spa-media-grid__check" aria-hidden="true">
										\u2713
									</span>
								) }
							</div>
							<div className="nvoos-pro-spa-media-grid__info">
								<span className="nvoos-pro-spa-media-grid__name">
									{ item.title || sprintf( __( '#%d', 'nvoos-pro-spa' ), item.id ) }
								</span>
								<span className="nvoos-pro-spa-media-grid__meta">
									<span className="nvoos-pro-spa-media-grid__mime">
										{ getMimeLabel( item.mimeType ) }
									</span>
									{ item.fileSize > 0 && (
										<span className="nvoos-pro-spa-media-grid__size">
											{ formatFileSize( item.fileSize ) }
										</span>
									) }
								</span>
							</div>
						</button>
					</li>
				);
			} ) }
		</ul>
	);
}
