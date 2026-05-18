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
import PageFooter from '../components/PageFooter';
import NotFound from './NotFound';

export default function DocPage() {
	const params = useParams<{ '*': string }>();
	const slug = params[ '*' ] ?? '';

	const [ page, setPage ] = useState<DocPageData | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ notFound, setNotFound ] = useState( false );
	const [ pageError, setPageError ] = useState<string | null>( null );

	useEffect( () => {
		if ( ! slug ) {
			setLoading( false );
			setNotFound( true );
			return;
		}

		setLoading( true );
		setNotFound( false );
		setPage( null );
		setPageError( null );

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
				const msg = err instanceof Error ? err.message : String( err );
				const is404 = msg.startsWith( 'HTTP 404' );
				setNotFound( is404 );
				if ( ! is404 ) {
					setPageError( msg );
				}
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

	if ( pageError ) {
		return (
			<main id="nvoos-dh-main" tabIndex={ -1 } className="dh-error dh-main-area">
				<h2>Could not load page</h2>
				<p style={ { marginTop: '0.5rem', fontSize: 'var(--dh-font-size-sm)' } }>{ pageError }</p>
			</main>
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
				<PageFooter
					lastModified={ page.last_modified }
					relativePath={ page.relative_path }
					remoteUrl={ page.remote_url }
				/>
			</main>
			<aside className="dh-toc-area">
				<RightTOC items={ page.toc } />
			</aside>
		</>
	);
}
