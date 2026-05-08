/**
 * DocPage — fetches a documentation page by slug and renders it.
 *
 * Composed of: Breadcrumbs + ContentArea + RightTOC + PrevNext.
 *
 * @since 1.0.0
 */

import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { fetchPage } from '../api/manifest-client';
import type { DocPage as DocPageData } from '../api/manifest-client';
import ContentArea from '../components/ContentArea';
import RightTOC from '../components/RightTOC';
import Breadcrumbs from '../components/Breadcrumbs';
import PrevNext from '../components/PrevNext';
import NotFound from './NotFound';

export default function DocPage() {
	const params = useParams<{ '*': string }>();
	const slug = params[ '*' ] ?? '';

	const [ page, setPage ] = useState<DocPageData | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ notFound, setNotFound ] = useState( false );

	useEffect( () => {
		if ( ! slug ) {
			setLoading( false );
			setNotFound( true );
			return;
		}

		setLoading( true );
		setNotFound( false );
		setPage( null );

		fetchPage( slug )
			.then( ( data ) => {
				setPage( data );
				setLoading( false );

				// Update document title.
				if ( data.title ) {
					document.title = `${ data.title } — Docs`;
				}
			} )
			.catch( ( err: unknown ) => {
				const status = ( err instanceof Error && err.message.startsWith( 'HTTP 404' ) ) ? 404 : 0;
				setNotFound( status === 404 );
				setLoading( false );
			} );
	}, [ slug ] );

	// Scroll to top on slug change.
	useEffect( () => {
		const main = document.querySelector( '.dh-main-area' );
		if ( main ) {
			main.scrollTo( { top: 0, behavior: 'instant' } );
		}
	}, [ slug ] );

	if ( loading ) {
		return (
			<div className="dh-loading">
				<span className="dh-spinner" role="status" aria-label="Loading" />
				Loading…
			</div>
		);
	}

	if ( notFound || ! page ) {
		return <NotFound slug={ slug } />;
	}

	return (
		<>
			<main id="nvoos-dh-main" tabIndex={ -1 } className="dh-main-area">
				<Breadcrumbs slug={ page.slug } title={ page.title } />
				<ContentArea content={ page.content } remoteUrl={ page.remote_url } />
				<PrevNext prev={ page.prev } next={ page.next } />
			</main>
			<aside className="dh-toc-area">
				<RightTOC items={ page.toc } />
			</aside>
		</>
	);
}
