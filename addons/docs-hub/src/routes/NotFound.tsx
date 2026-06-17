/**
 * NotFound — shown when a requested slug is not in the manifest.
 *
 * @since 1.0.0
 */

import { Link } from 'react-router-dom';

interface NotFoundProps {
	slug?: string;
}

export default function NotFound( { slug }: NotFoundProps ) {
	return (
		<main id="nvoos-dh-main" tabIndex={ -1 } className="dh-not-found dh-main-area">
			<h1 style={ { fontSize: '3rem', marginBottom: '0.5rem' } }>404</h1>
			<h2 style={ { marginBottom: '1rem' } }>Page Not Found</h2>

			{ slug && (
				<p style={ { marginBottom: '1rem', color: 'var(--dh-text-muted)', fontSize: 'var(--dh-font-size-sm)' } }>
					No documentation page exists for: <code>{ slug }</code>
				</p>
			) }

			<Link to="/" style={ { color: 'var(--dh-text-link)' } }>
				← Back to documentation home
			</Link>
		</main>
	);
}
