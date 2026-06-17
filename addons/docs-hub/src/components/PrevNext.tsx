/**
 * PrevNext — bottom-of-page navigation links.
 *
 * @since 1.0.0
 */

import { Link } from 'react-router-dom';

interface NavRef {
	slug: string;
	title: string;
}

interface PrevNextProps {
	prev: NavRef | null | undefined;
	next: NavRef | null | undefined;
}

export default function PrevNext( { prev, next }: PrevNextProps ) {
	if ( ! prev && ! next ) {
		return null;
	}

	return (
		<nav className="dh-prev-next" aria-label="Page navigation">
			{ prev ? (
				<Link
					to={ `/${ prev.slug }` }
					className="dh-prev-next-link dh-prev-next-link--prev"
					aria-label={ `Previous: ${ prev.title }` }
				>
					<span className="dh-prev-next-direction">← Previous</span>
					<span className="dh-prev-next-title">{ prev.title }</span>
				</Link>
			) : (
				<div /> /* spacer */
			) }

			{ next && (
				<Link
					to={ `/${ next.slug }` }
					className="dh-prev-next-link dh-prev-next-link--next"
					aria-label={ `Next: ${ next.title }` }
				>
					<span className="dh-prev-next-direction">Next →</span>
					<span className="dh-prev-next-title">{ next.title }</span>
				</Link>
			) }
		</nav>
	);
}
