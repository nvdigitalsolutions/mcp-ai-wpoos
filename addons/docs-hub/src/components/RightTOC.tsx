/**
 * RightTOC — table-of-contents panel with scroll-spy.
 *
 * Listens to IntersectionObserver events on the heading elements inside
 * `.dh-content` and highlights the currently-visible heading in the TOC.
 *
 * @since 1.0.0
 */

import { useEffect, useState } from 'react';
import type { TocItem } from '../api/manifest-client';

interface RightTOCProps {
	items: TocItem[];
}

export default function RightTOC( { items }: RightTOCProps ) {
	const [ activeAnchor, setActiveAnchor ] = useState<string>( '' );

	useEffect( () => {
		if ( ! items.length || typeof IntersectionObserver === 'undefined' ) {
			return;
		}

		const headingEls: Element[] = [];
		items.forEach( ( item ) => {
			const el = document.getElementById( item.anchor );
			if ( el ) {
				headingEls.push( el );
			}
		} );

		if ( ! headingEls.length ) {
			return;
		}

		const observer = new IntersectionObserver(
			( entries ) => {
				const visible = entries.filter( ( e ) => e.isIntersecting );
				if ( visible.length > 0 ) {
					setActiveAnchor( visible[ 0 ].target.id );
				}
			},
			{
				rootMargin: '-80px 0px -60% 0px',
				threshold: 0,
			}
		);

		headingEls.forEach( ( el ) => observer.observe( el ) );

		return () => {
			observer.disconnect();
		};
	}, [ items ] );

	if ( ! items.length ) {
		return null;
	}

	return (
		<nav className="dh-toc" aria-label="On this page">
			<p className="dh-toc-title">On this page</p>
			<ul className="dh-toc-list">
				{ items.map( ( item ) => (
					<li
						key={ item.anchor }
						className={ `dh-toc-item dh-toc-h${ item.level }${ activeAnchor === item.anchor ? ' dh-active' : '' }` }
					>
						<a
							href={ `#${ item.anchor }` }
							onClick={ ( e ) => {
								e.preventDefault();
								const target = document.getElementById( item.anchor );
								if ( target ) {
									target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
									setActiveAnchor( item.anchor );
								}
							} }
						>
							{ item.text }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
