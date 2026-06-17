/**
 * Breadcrumbs component.
 *
 * Renders a path-based breadcrumb trail from the current slug.
 * e.g. "features/chat" → Home > Features > Chat
 *
 * @since 1.0.0
 */

import React from 'react';
import { Link } from 'react-router-dom';

interface BreadcrumbsProps {
	slug: string;
	title: string;
}

function toLabel( segment: string ): string {
	return segment
		.replace( /-/g, ' ' )
		.replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

export default function Breadcrumbs( { slug, title }: BreadcrumbsProps ) {
	const parts = slug.split( '/' ).filter( Boolean );

	if ( parts.length <= 1 ) {
		return null;
	}

	const crumbs: { label: string; slug: string }[] = [];
	let accumulated = '';

	for ( let i = 0; i < parts.length - 1; i++ ) {
		accumulated = accumulated ? `${ accumulated }/${ parts[ i ] }` : parts[ i ];
		crumbs.push( { label: toLabel( parts[ i ] ), slug: accumulated } );
	}

	return (
		<nav aria-label="Breadcrumb" className="dh-breadcrumbs">
			<Link to="/">Home</Link>
			<span className="dh-breadcrumb-sep" aria-hidden="true">/</span>

			{ crumbs.map( ( crumb ) => (
				<React.Fragment key={ crumb.slug }>
					<Link to={ `/#/${ crumb.slug }` }>{ crumb.label }</Link>
					<span className="dh-breadcrumb-sep" aria-hidden="true">/</span>
				</React.Fragment>
			) ) }

			<span>{ title }</span>
		</nav>
	);
}
